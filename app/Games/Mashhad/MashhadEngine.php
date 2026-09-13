<?php

namespace App\Games\Mashhad;

use App\Games\Effects;
use App\Games\Mashhad\Scenes\SceneLibrary;
use App\Games\State\GameStateStore;
use App\Jobs\AdvanceMashhadPhase;
use App\Models\Channel;
use App\Models\Game;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * آلة حالات لعبة المشهد.
 *
 * هذه اللعبة لا تُقاس بجواب صحيح ولا بسرعة: تُقاس بنوايا متضاربة تتحقّق أو
 * لا. ولأن المشهد حوارٌ حرّ لا يستطيع السيرفر الحكم عليه، فالحكم اجتماعي —
 * تدّعي، ويُكشف هدفك للجميع، فيعترض من يشك، ويحسم الباقون بالتصويت. السيرفر
 * يضبط الإيقاع ويحرس السرّية ويحسب النقاط؛ الحقيقة عند الطاولة.
 *
 * وسرّية هذه اللعبة ثلاثية: **الهدف والسرّ لا يُبَثّان قبل مرحلة الكشف**،
 * و**الحدث الخاص يصل صاحبه وحده**. كلها تصل في `snapshot()` مبنيّةً على هوية
 * القارئ، ولا تمرّ على القناة الحيّة أبداً.
 */
class MashhadEngine
{
    public const TYPE = 'mashhad';

    public function __construct(
        private readonly GameStateStore $store,
        private readonly SceneLibrary $scenes,
        private readonly MashhadScoring $scoring,
    ) {}

    // =========================================================================
    // اللوبي
    // =========================================================================

    /** @return array<string, mixed> */
    public function openLobby(Game $game, User $host): array
    {
        $config = MashhadConfig::fromArray($game->config);

        $state = [
            'gameId' => $game->id,
            'channelId' => $game->channel_id,
            'gameType' => self::TYPE,
            'status' => Game::STATUS_LOBBY,
            'startedBy' => $host->id,
            'hostId' => $host->id,
            'config' => $config->toArray(),
            'players' => [],
            'order' => [],
            'currentScene' => 0,
            'usedSceneKeys' => [],
            'awards' => [],
            'seq' => 0,
            'round' => null,
        ];

        $state = $this->addPlayer($state, $host, spectator: false);
        $this->store->put($game->id, $state);

        return $state;
    }

    /** @return array<string, mixed>|null */
    public function join(Game $game, User $user): ?array
    {
        return $this->transaction($game->id, function (array $state, Effects $fx) use ($game, $user) {
            if (isset($state['players'][$user->id])) {
                $player = $state['players'][$user->id];
                $grace = (int) config('mashhad.limits.rejoin_grace_scenes');

                if ($player['leftAtScene'] !== null && $grace >= $state['currentScene'] - $player['leftAtScene']) {
                    $state['players'][$user->id]['leftAtScene'] = null;
                    $fx->emit('player_returned', ['userId' => $user->id, 'username' => $player['username']]);
                    $fx->emit('lobby_updated', $this->lobbyPayload($state));
                }

                return $state;
            }

            if ($state['status'] === Game::STATUS_LOBBY) {
                if (count($this->seatedPlayerIds($state)) >= (int) config('mashhad.limits.max_players')) {
                    return $state;
                }

                $state = $this->addPlayer($state, $user, spectator: false);
                $fx->defer(fn () => $this->persistPlayer($game, $user, $state['players'][$user->id]));
                $fx->emit('lobby_updated', $this->lobbyPayload($state));

                return $state;
            }

            // المتأخر يتفرّج: يقرأ الشات ولا دور له ولا هدف.
            $state = $this->addPlayer($state, $user, spectator: true);
            $fx->defer(fn () => $this->persistPlayer($game, $user, $state['players'][$user->id]));
            $fx->emit('spectator_joined', ['userId' => $user->id, 'username' => $user->username]);

            return $state;
        });
    }

    public function leave(Game $game, User $user): void
    {
        $this->transaction($game->id, function (array $state, Effects $fx) use ($game, $user) {
            if (! isset($state['players'][$user->id])) {
                return $state;
            }

            if ($state['status'] === Game::STATUS_LOBBY) {
                $username = $state['players'][$user->id]['username'];
                unset($state['players'][$user->id]);
                $state['order'] = array_values(array_diff($state['order'], [$user->id]));

                $fx->defer(fn () => $game->players()->where('user_id', $user->id)->delete());

                if ($this->seatedPlayerIds($state) === []) {
                    $fx->defer(fn () => $this->abandonGame($game));
                    $fx->emit('game_abandoned', []);
                    $fx->emitToChannel('active_game_changed', ['activeGame' => null]);
                    $this->store->forget($game->id);

                    return null;
                }

                $state = $this->reassignHostIfNeeded($state, $user->id);
                $fx->emit('player_left', ['userId' => $user->id, 'username' => $username]);
                $fx->emit('lobby_updated', $this->lobbyPayload($state));

                return $state;
            }

            $state['players'][$user->id]['leftAtScene'] = $state['currentScene'];
            $state = $this->reassignHostIfNeeded($state, $user->id);

            $fx->emit('player_left', [
                'userId' => $user->id,
                'username' => $state['players'][$user->id]['username'],
                'activePlayers' => count($this->activePlayerIds($state)),
                'hostId' => $state['hostId'],
            ]);

            if (count($this->activePlayerIds($state)) < (int) config('mashhad.limits.min_players')) {
                return $this->finishGame($state, $fx, endedEarly: true, reason: 'not_enough_players');
            }

            // غادر آخر من ننتظره: لا معنى لمؤقّت لا ينتظره أحد.
            return $this->closeIfEveryoneDone($state, $fx);
        });
    }

    public function start(Game $game, User $user): void
    {
        $this->transaction($game->id, function (array $state, Effects $fx) use ($game, $user) {
            if ($state['status'] !== Game::STATUS_LOBBY || $state['hostId'] !== $user->id) {
                return $state;
            }

            $seated = $this->seatedPlayerIds($state);

            if (count($seated) < (int) config('mashhad.limits.min_players')) {
                return $state;
            }

            $state['status'] = Game::STATUS_PLAYING;
            $state['order'] = $seated;
            $state['currentScene'] = 0;

            $fx->defer(function () use ($game) {
                $game->forceFill([
                    'status' => Game::STATUS_PLAYING,
                    'started_at' => now(),
                ])->save();
            });

            $fx->emit('game_started', [
                'config' => $state['config'],
                'scenes' => $state['config']['scenes'],
                'players' => $this->playersPayload($state),
            ]);

            $fx->emitToChannel('active_game_changed', [
                'activeGame' => [
                    'id' => $game->id,
                    'gameType' => self::TYPE,
                    'status' => Game::STATUS_PLAYING,
                    'playerCount' => count($seated),
                ],
            ]);

            return $this->beginScene($state, $fx);
        });
    }

    // =========================================================================
    // نوايا اللاعبين
    // =========================================================================

    /** رسالة داخل المشهد — الحوار الحر الذي تقوم عليه اللعبة. */
    public function say(Game $game, User $user, string $text): void
    {
        $this->transaction($game->id, function (array $state, Effects $fx) use ($user, $text) {
            if (! $this->inPhase($state, MashhadPhase::Scene)) {
                return $state;
            }

            // المتفرّج يقرأ ولا يكتب: لا يحقّ له تحريك مشهد ليس فيه.
            if (! $this->isActivePlayer($state, $user->id)) {
                return $state;
            }

            $clean = preg_replace('/\s+/u', ' ', $text) ?? $text;
            $clean = mb_substr(trim($clean), 0, (int) config('mashhad.limits.message_length'));

            if ($clean === '') {
                return $state;
            }

            $message = [
                'id' => (string) Str::uuid(),
                'userId' => $user->id,
                'username' => $state['players'][$user->id]['username'],
                'role' => $state['round']['cast'][$user->id]['name'] ?? null,
                'text' => $clean,
                'at' => $this->nowMs(),
            ];

            $state['round']['transcript'][] = $message;

            // سقف الذاكرة لا سقف على اللاعب: نُسقط الأقدم ونُبقي المشهد حياً.
            $cap = (int) config('mashhad.limits.messages_per_scene');

            if (count($state['round']['transcript']) > $cap) {
                array_shift($state['round']['transcript']);
            }

            $state['players'][$user->id]['messageCount']++;

            $fx->emit('said', $message);

            return $state;
        });
    }

    /**
     * ادّعاء ما حقّقته.
     *
     * يُرسَل وأهدافك ما زالت سرّية عن غيرك — فلا أحد يبني ادّعاءه على ادّعائك.
     */
    public function claim(Game $game, User $user, bool $main, bool $bonus, bool $event): void
    {
        $this->transaction($game->id, function (array $state, Effects $fx) use ($user, $main, $bonus, $event) {
            if (! $this->inPhase($state, MashhadPhase::Claims) || ! $this->isActivePlayer($state, $user->id)) {
                return $state;
            }

            $role = $state['round']['cast'][$user->id] ?? null;

            if ($role === null) {
                return $state;
            }

            $state['round']['claims'][$user->id] = [
                // لا تدّعي ما لا وجود له: بلا هدف إضافي لا ادّعاء به.
                'main' => $main,
                'bonus' => $bonus && $role['bonus'] !== null,
                'event' => $event && $this->receivedPrivateEvent($state, $user->id),
            ];

            $fx->emit('claim_locked', [
                'claimed' => count($state['round']['claims']),
                'total' => count($this->activePlayerIds($state)),
            ]);

            return $this->closeIfEveryoneDone($state, $fx);
        });
    }

    /** اعتراض على ادّعاء — بعد أن انكشفت الأهداف للجميع. */
    public function challenge(Game $game, User $user, string $targetUserId, string $kind): void
    {
        $this->transaction($game->id, function (array $state, Effects $fx) use ($user, $targetUserId, $kind) {
            if (! $this->inPhase($state, MashhadPhase::Challenge) || ! $this->isActivePlayer($state, $user->id)) {
                return $state;
            }

            if (! in_array($kind, ['main', 'bonus', 'event'], true)) {
                return $state;
            }

            // لا تعترض على نفسك، ولا على ادّعاء لم يُقدَّم أصلاً.
            if ($targetUserId === $user->id || ! $this->isActivePlayer($state, $targetUserId)) {
                return $state;
            }

            if (($state['round']['claims'][$targetUserId][$kind] ?? false) !== true) {
                return $state;
            }

            $used = $state['round']['challengeCounts'][$user->id] ?? 0;

            if ($used >= (int) config('mashhad.limits.challenges_per_player')) {
                return $state;
            }

            $existing = $this->findChallenge($state, $targetUserId, $kind);

            if ($existing !== null) {
                // اعتراضات متعددة على الادّعاء نفسه = تصويت واحد؛ الأول هو
                // المعترض الرسمي والبقية شركاء بالنتيجة ربحاً وخسارة.
                $challenge = $state['round']['challenges'][$existing];

                if ($challenge['by'] === $user->id || in_array($user->id, $challenge['coChallengers'], true)) {
                    return $state;
                }

                $state['round']['challenges'][$existing]['coChallengers'][] = $user->id;
            } else {
                $state['round']['challenges'][] = [
                    'id' => (string) Str::uuid(),
                    'targetUserId' => $targetUserId,
                    'kind' => $kind,
                    'by' => $user->id,
                    'coChallengers' => [],
                    'votes' => [],
                    'verdict' => null,
                ];
            }

            $state['round']['challengeCounts'][$user->id] = $used + 1;

            $fx->emit('challenge_raised', [
                'by' => $user->id,
                'byUsername' => $state['players'][$user->id]['username'],
                'targetUserId' => $targetUserId,
                'kind' => $kind,
                'total' => count($state['round']['challenges']),
            ]);

            return $state;
        });
    }

    /** تصويت على الاعتراض المعروض: هل فعلاً حقّقه؟ */
    public function vote(Game $game, User $user, string $challengeId, bool $achieved): void
    {
        $this->transaction($game->id, function (array $state, Effects $fx) use ($user, $challengeId, $achieved) {
            if (! $this->inPhase($state, MashhadPhase::Verdict) || ! $this->isActivePlayer($state, $user->id)) {
                return $state;
            }

            $index = $state['round']['currentChallenge'];

            if ($index === null || ($state['round']['challenges'][$index]['id'] ?? null) !== $challengeId) {
                return $state;
            }

            $eligible = $this->eligibleVoters($state, $state['round']['challenges'][$index]);

            if (! in_array($user->id, $eligible, true)) {
                return $state;
            }

            $state['round']['challenges'][$index]['votes'][$user->id] = $achieved;
            $voted = count($state['round']['challenges'][$index]['votes']);

            $fx->emit('verdict_vote', [
                'challengeId' => $challengeId,
                'voted' => $voted,
                'eligible' => count($eligible),
            ]);

            if ($voted >= count($eligible)) {
                return $this->resolveCurrentChallenge($state, $fx);
            }

            return $state;
        });
    }

    /** جائزة نهاية المباراة — صوت واحد لكل فئة. */
    public function awardVote(Game $game, User $user, string $awardKey, string $targetUserId): void
    {
        $this->transaction($game->id, function (array $state, Effects $fx) use ($user, $awardKey, $targetUserId) {
            if (! $this->inPhase($state, MashhadPhase::Awards) || ! $this->isActivePlayer($state, $user->id)) {
                return $state;
            }

            if (! in_array($awardKey, array_column(config('mashhad.awards'), 'key'), true)) {
                return $state;
            }

            // لا تصوّت لنفسك بجائزة.
            if ($targetUserId === $user->id || ! isset($state['players'][$targetUserId])) {
                return $state;
            }

            $state['round']['awardVotes'][$awardKey][$user->id] = $targetUserId;

            $fx->emit('award_vote', [
                'awardKey' => $awardKey,
                'voted' => count($state['round']['awardVotes'][$awardKey]),
                'eligible' => count($this->activePlayerIds($state)),
            ]);

            return $this->closeIfEveryoneDone($state, $fx);
        });
    }

    public function endEarly(Game $game, User $user): void
    {
        $this->transaction($game->id, function (array $state, Effects $fx) use ($user) {
            if ($state['status'] !== Game::STATUS_PLAYING || $state['hostId'] !== $user->id) {
                return $state;
            }

            return $this->finishGame($state, $fx, endedEarly: true, reason: 'ended_early');
        });
    }

    // =========================================================================
    // مؤقّت السيرفر
    // =========================================================================

    public function handleTimeout(string $gameId, int $seq): void
    {
        $this->transaction($gameId, function (array $state, Effects $fx) use ($seq) {
            if ($state['seq'] !== $seq || $state['round'] === null) {
                return $state;
            }

            return match (MashhadPhase::from($state['round']['phase'])) {
                MashhadPhase::RoleReveal => $this->openScene($state, $fx),
                // المؤقّت داخل المشهد يخدم غرضين: إطلاق الأحداث ثم الإقفال.
                MashhadPhase::Scene => $this->tickScene($state, $fx),
                MashhadPhase::Claims => $this->openChallenges($state, $fx),
                MashhadPhase::Challenge => $this->startVerdicts($state, $fx),
                MashhadPhase::Verdict => $this->resolveCurrentChallenge($state, $fx),
                MashhadPhase::Scoreboard => $this->nextSceneOrFinish($state, $fx),
                MashhadPhase::Awards => $this->closeAwards($state, $fx),
            };
        });
    }

    /**
     * شبكة أمان للمؤقّتات — مثل نظيراتها في بقية الألعاب.
     *
     * @return int عدد الجلسات التي أُعيد تحريكها
     */
    public function sweepStalePhases(int $graceMs = 2000): int
    {
        $moved = 0;

        $gameIds = Game::query()
            ->where('game_type', self::TYPE)
            ->whereIn('status', [Game::STATUS_LOBBY, Game::STATUS_PLAYING])
            ->pluck('id');

        foreach ($gameIds as $gameId) {
            $state = $this->store->get($gameId);

            if ($state === null || $state['round'] === null) {
                continue;
            }

            // داخل المشهد المؤقّت يضرب على الأحداث لا على النهاية، فنقيس
            // بالموعد الفعلي للضربة التالية.
            $due = $state['round']['nextTickAt'] ?? $state['round']['deadline'];

            if ($due + $graceMs > $this->nowMs()) {
                continue;
            }

            $this->handleTimeout($gameId, $state['seq']);
            $moved++;
        }

        return $moved;
    }

    // =========================================================================
    // انتقالات المراحل
    // =========================================================================

    /** @return array<string, mixed> */
    private function beginScene(array $state, Effects $fx): array
    {
        $state['currentScene']++;

        $config = MashhadConfig::fromArray($state['config']);
        $scene = $this->scenes->draw($config->category, $state['usedSceneKeys']);
        $players = $this->activePlayerIds($state);

        $state['usedSceneKeys'][] = $scene['key'];
        $state['round'] = $this->emptyRound($state['currentScene'], $scene);
        $state['round']['cast'] = $this->scenes->assign($scene, $players, $config->familyMode);
        $state['round']['events'] = $this->scenes->scheduleEvents(
            $scene['events'],
            $config->sceneSeconds,
            $players,
        );

        // الحبكة عامة — الأدوار والأهداف لا. كلٌّ يقرأ دوره من لقطته وحده.
        $fx->emit('scene_started', [
            'scene' => $state['currentScene'],
            'totalScenes' => $state['config']['scenes'],
            'title' => $scene['title'],
            'setup' => $scene['setup'],
            'category' => $scene['category'],
            'categoryLabel' => $scene['categoryLabel'],
            'categoryEmoji' => $scene['categoryEmoji'],
        ]);

        return $this->enterPhase($state, $fx, MashhadPhase::RoleReveal);
    }

    /** @return array<string, mixed> */
    private function openScene(array $state, Effects $fx): array
    {
        $seconds = $state['config']['sceneSeconds'];

        $state = $this->enterPhase($state, $fx, MashhadPhase::Scene, $seconds);

        // نضبط المؤقّت على أول حدث لا على نهاية المشهد؛ و`deadline` يبقى
        // النهاية لأن عدّاد الشاشة يُحسب منه.
        return $this->armSceneTick($state, $fx);
    }

    /**
     * ضربة المؤقّت داخل المشهد: إمّا حدث مفاجئ وإمّا نهاية المشهد.
     *
     * الترتيب هو الحكَم لا ساعة الحائط: كل ضربة تُطلق الحدث التالي في
     * الطابور، وحين يفرغ الطابور تُقفل الضربةُ المشهدَ. الجدولة هي التي
     * تضبط **متى** تصل الضربة؛ أما **ماذا تفعل** فيقرّره الطابور وحده —
     * فلا تختلف النتيجة لو تأخّرت مهمة في الطابور ثانيةً أو ثانيتين.
     *
     * @return array<string, mixed>
     */
    private function tickScene(array $state, Effects $fx): array
    {
        $next = $this->nextPendingEvent($state);

        if ($next === null) {
            return $this->closeScene($state, $fx);
        }

        $event = $state['round']['events'][$next];

        $state['round']['events'][$next]['fired'] = true;
        $state['round']['events'][$next]['firedAt'] = $this->nowMs();

        // الحدث العام يُبَث للجميع؛ والخاص لا يُبَث إطلاقاً — يصل صاحبه في
        // لقطته وحده، وقراره بعدها: يكشفه أم يستغلّه أم يكذب به. ونُعلن
        // وصولَه للجميع بلا نصّه، فيعرفوا أن أحدهم يعرف شيئاً.
        if ($event['scope'] === 'all') {
            $fx->emit('scene_event', ['text' => $event['text'], 'scope' => 'all']);
        } else {
            $fx->emit('private_event_sent', ['userId' => $event['userId']]);
        }

        return $this->armSceneTick($state, $fx);
    }

    /**
     * جدولة الضربة التالية: موعد أقرب حدث لم يُطلق، أو نهاية المشهد.
     *
     * @return array<string, mixed>
     */
    private function armSceneTick(array $state, Effects $fx): array
    {
        $now = $this->nowMs();
        $next = $this->nextPendingEvent($state);

        $due = $next === null
            ? $state['round']['deadline']
            : $state['round']['sceneStartedAt'] + $state['round']['events'][$next]['at'] * 1000;

        // حدث فات موعده (مهمة تأخّرت في الطابور) يُطلق فوراً لا يُسقَط.
        $due = min(max($due, $now), $state['round']['deadline']);

        $state['round']['nextTickAt'] = $due;
        $state['seq']++;

        $fx->scheduleTimeout($state['seq'], max((int) ceil(($due - $now) / 1000), 1));

        return $state;
    }

    /** موقع أول حدث لم يُطلق بعد — بترتيب جدولته. */
    private function nextPendingEvent(array $state): ?int
    {
        foreach ($state['round']['events'] as $index => $event) {
            if (! $event['fired']) {
                return $index;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function closeScene(array $state, Effects $fx): array
    {
        $fx->emit('scene_ended', ['scene' => $state['currentScene']]);

        return $this->enterPhase($state, $fx, MashhadPhase::Claims);
    }

    /**
     * انتهت الادّعاءات: تُكشف الأهداف والادّعاءات معاً للجميع.
     *
     * @return array<string, mixed>
     */
    private function openChallenges(array $state, Effects $fx): array
    {
        $fx->emit('claims_revealed', ['rows' => $this->claimRows($state)]);

        return $this->enterPhase($state, $fx, MashhadPhase::Challenge);
    }

    /** @return array<string, mixed>|null */
    private function startVerdicts(array $state, Effects $fx): ?array
    {
        // تُناقَش أوائل الاعتراضات فقط؛ الباقي يسقط تلقائياً حمايةً للإيقاع
        // (وسقوطه في مصلحة المُدّعي — الشك لمصلحته كما في بقية الألعاب).
        $limit = (int) config('mashhad.limits.challenges_discussed');
        $discussable = [];

        foreach ($state['round']['challenges'] as $index => $challenge) {
            if (count($discussable) < $limit) {
                $discussable[] = $index;

                continue;
            }

            $state['round']['challenges'][$index]['verdict'] = 'auto_dropped';
        }

        $state['round']['discussable'] = $discussable;
        $state['round']['currentChallenge'] = null;

        return $this->openNextChallenge($state, $fx);
    }

    /** @return array<string, mixed>|null */
    private function openNextChallenge(array $state, Effects $fx): ?array
    {
        foreach ($state['round']['discussable'] as $position => $index) {
            if ($state['round']['challenges'][$index]['verdict'] !== null) {
                continue;
            }

            $state['round']['currentChallenge'] = $index;
            $challenge = $state['round']['challenges'][$index];
            $target = $challenge['targetUserId'];

            $fx->emit('verdict_opened', [
                'challengeId' => $challenge['id'],
                'by' => $challenge['by'],
                'byUsername' => $state['players'][$challenge['by']]['username'],
                'targetUserId' => $target,
                'targetUsername' => $state['players'][$target]['username'],
                'kind' => $challenge['kind'],
                'roleName' => $state['round']['cast'][$target]['name'] ?? '',
                'goal' => $this->goalText($state, $target, $challenge['kind']),
                'parties' => array_values(array_unique(array_merge(
                    [$challenge['by'], $target],
                    $challenge['coChallengers'],
                ))),
                'index' => $position + 1,
                'total' => count($state['round']['discussable']),
            ]);

            return $this->enterPhase($state, $fx, MashhadPhase::Verdict);
        }

        $state['round']['currentChallenge'] = null;

        return $this->scoreScene($state, $fx);
    }

    /** @return array<string, mixed>|null */
    private function resolveCurrentChallenge(array $state, Effects $fx): ?array
    {
        $index = $state['round']['currentChallenge'];

        if ($index === null) {
            return $this->scoreScene($state, $fx);
        }

        $votes = $state['round']['challenges'][$index]['votes'];
        $achieved = count(array_filter($votes));
        $denied = count($votes) - $achieved;

        // التعادل أو صفر أصوات = الادّعاء يمرّ. الشك لمصلحة المُدّعي، تماماً
        // كما في اعتراضات لعبة الحروف.
        $verdict = $denied > $achieved ? 'rejected' : 'upheld';

        $state['round']['challenges'][$index]['verdict'] = $verdict;

        $fx->emit('verdict_result', [
            'challengeId' => $state['round']['challenges'][$index]['id'],
            'targetUserId' => $state['round']['challenges'][$index]['targetUserId'],
            'kind' => $state['round']['challenges'][$index]['kind'],
            // rejected = الادّعاء سقط · upheld = الادّعاء صمد
            'verdict' => $verdict,
            'achievedVotes' => $achieved,
            'deniedVotes' => $denied,
        ]);

        return $this->openNextChallenge($state, $fx);
    }

    /** @return array<string, mixed> */
    private function scoreScene(array $state, Effects $fx): array
    {
        $playerIds = $this->activePlayerIds($state);

        $scores = $this->scoring->score(
            $state['round']['cast'],
            $state['round']['claims'],
            $state['round']['challenges'],
            $playerIds,
        );

        foreach ($scores as $userId => $row) {
            $state['players'][$userId]['total'] += $row['total'];

            if ($row['mainAchieved']) {
                $state['players'][$userId]['goalsAchieved']++;
            }
        }

        $state['round']['scores'] = $scores;

        $gameId = $state['gameId'];
        $round = $state['round'];
        $fx->defer(fn () => $this->persistRound($gameId, $round));

        $fx->emit('scene_scored', [
            'scene' => $state['currentScene'],
            'totalScenes' => $state['config']['scenes'],
            'rows' => $this->scoreRows($state, $scores),
            'standings' => $this->standings($state),
        ]);

        return $this->enterPhase($state, $fx, MashhadPhase::Scoreboard);
    }

    /** @return array<string, mixed>|null */
    private function nextSceneOrFinish(array $state, Effects $fx): ?array
    {
        if (count($this->activePlayerIds($state)) < (int) config('mashhad.limits.min_players')) {
            return $this->finishGame($state, $fx, endedEarly: true, reason: 'not_enough_players');
        }

        if ($state['currentScene'] >= $state['config']['scenes']) {
            return $this->startAwards($state, $fx);
        }

        return $this->beginScene($state, $fx);
    }

    /** @return array<string, mixed> */
    private function startAwards(array $state, Effects $fx): array
    {
        $state['round']['awardVotes'] = [];

        $fx->emit('awards_started', [
            'awards' => config('mashhad.awards'),
            'standings' => $this->standings($state),
        ]);

        return $this->enterPhase($state, $fx, MashhadPhase::Awards);
    }

    /** @return array<string, mixed>|null */
    private function closeAwards(array $state, Effects $fx): ?array
    {
        $points = (int) config('mashhad.points.award');

        foreach (config('mashhad.awards') as $award) {
            $votes = $state['round']['awardVotes'][$award['key']] ?? [];

            if ($votes === []) {
                continue;
            }

            $counts = array_count_values($votes);
            arsort($counts);

            $top = (int) reset($counts);
            $winners = array_keys($counts, $top, true);

            // تعادل على الجائزة: تُمنح للجميع. جائزة رمزية لا تستحق جولة حسم.
            foreach ($winners as $userId) {
                $state['players'][$userId]['total'] += $points;
                $state['players'][$userId]['awards'][] = $award['key'];
            }

            $state['awards'][] = [
                'key' => $award['key'],
                'emoji' => $award['emoji'],
                'label' => $award['label'],
                'winners' => array_map(fn ($id) => [
                    'userId' => $id,
                    'username' => $state['players'][$id]['username'] ?? '',
                ], $winners),
                'votes' => $top,
            ];
        }

        return $this->finishGame($state, $fx, endedEarly: false, reason: null);
    }

    /**
     * @return array<string, mixed>|null null تعني أن الحالة الحيّة مُسحت
     */
    private function finishGame(array $state, Effects $fx, bool $endedEarly, ?string $reason): ?array
    {
        $standings = $this->standings($state);

        $result = [
            'winnerId' => $standings[0]['userId'] ?? null,
            'finalScores' => $standings,
            'awards' => $state['awards'],
            'endedEarly' => $endedEarly,
            'reason' => $reason,
            'scenesPlayed' => $state['currentScene'],
            'totalScenes' => $state['config']['scenes'],
        ];

        $gameId = $state['gameId'];
        $players = $state['players'];

        $fx->defer(fn () => $this->persistFinish($gameId, $result, $players));
        $fx->emit('game_finished', ['result' => $result]);
        $fx->emitToChannel('active_game_changed', ['activeGame' => null]);

        $this->store->forget($gameId);

        return null;
    }

    /**
     * إقفال مبكّر حين لا يبقى من يُنتظر — في مراحل الانتظار وحدها.
     *
     * @return array<string, mixed>|null
     */
    private function closeIfEveryoneDone(array $state, Effects $fx): ?array
    {
        $active = $this->activePlayerIds($state);

        if ($active === []) {
            return $state;
        }

        if ($this->inPhase($state, MashhadPhase::Claims)) {
            $claimed = array_intersect_key($state['round']['claims'], array_flip($active));

            return count($claimed) >= count($active)
                ? $this->openChallenges($state, $fx)
                : $state;
        }

        if ($this->inPhase($state, MashhadPhase::Awards)) {
            foreach (config('mashhad.awards') as $award) {
                $votes = $state['round']['awardVotes'][$award['key']] ?? [];

                if (count(array_intersect_key($votes, array_flip($active))) < count($active)) {
                    return $state;
                }
            }

            return $this->closeAwards($state, $fx);
        }

        return $state;
    }

    /**
     * ختم بداية المرحلة ونهايتها بتوقيت السيرفر، وجدولة مؤقّتها.
     *
     * @return array<string, mixed>
     */
    private function enterPhase(array $state, Effects $fx, MashhadPhase $phase, ?int $seconds = null): array
    {
        $duration = $seconds ?? $phase->duration() ?? 0;
        $now = $this->nowMs();

        $state['seq']++;
        $state['round']['phase'] = $phase->value;
        $state['round']['phaseStartedAt'] = $now;
        $state['round']['deadline'] = $now + ($duration * 1000);
        $state['round']['nextTickAt'] = $state['round']['deadline'];

        if ($phase === MashhadPhase::Scene) {
            $state['round']['sceneStartedAt'] = $now;
        }

        $fx->scheduleTimeout($state['seq'], $duration);
        $fx->emit('phase_changed', $this->phasePayload($state));

        return $state;
    }

    // =========================================================================
    // لقطة الحالة
    // =========================================================================

    /**
     * الحالة كما يراها هذا المستخدم تحديداً.
     *
     * هنا تعيش أسرار اللعبة الثلاثة: هدفك، وسرّك، والحدث الخاص الذي وصلك
     * وحدك. لا شيء منها يمرّ في حدث مبثوث قبل مرحلة الكشف.
     *
     * @return array<string, mixed>|null
     */
    public function snapshot(Game $game, User $viewer): ?array
    {
        $state = $this->store->get($game->id);

        if ($state === null) {
            return null;
        }

        $me = $state['players'][$viewer->id] ?? null;
        $isSeated = $me !== null && ! $me['isSpectator'];
        $round = $state['round'];
        $seated = $this->seatedPlayerIds($state);

        $snapshot = [
            'gameId' => $state['gameId'],
            'channelId' => $state['channelId'],
            'gameType' => self::TYPE,
            'status' => $state['status'],
            'config' => $state['config'],
            'hostId' => $state['hostId'],
            'players' => $this->playersPayload($state),
            'order' => $state['order'],
            'currentScene' => $state['currentScene'],
            'totalScenes' => $state['config']['scenes'],
            'standings' => $this->standings($state),
            'awards' => $state['awards'],
            'estimatedSeconds' => $this->estimateSeconds($state['config'], count($seated)),
            'canStart' => count($seated) >= (int) config('mashhad.limits.min_players'),
            'me' => [
                'isPlayer' => $isSeated,
                'isSpectator' => ! $isSeated,
                'isHost' => $state['hostId'] === $viewer->id,
                'canEndEarly' => $state['hostId'] === $viewer->id,
                'total' => $me['total'] ?? 0,
                'goalsAchieved' => $me['goalsAchieved'] ?? 0,
            ],
            'serverTime' => $this->nowMs(),
            'round' => null,
        ];

        if ($round === null || $state['status'] === Game::STATUS_LOBBY) {
            return $snapshot;
        }

        $phase = MashhadPhase::from($round['phase']);
        $revealed = in_array(
            $phase,
            [MashhadPhase::Challenge, MashhadPhase::Verdict, MashhadPhase::Scoreboard, MashhadPhase::Awards],
            true,
        );

        $myRole = $round['cast'][$viewer->id] ?? null;

        $snapshot['round'] = [
            'no' => $round['no'],
            'phase' => $round['phase'],
            'phaseStartedAt' => $round['phaseStartedAt'],
            'deadline' => $round['deadline'],
            'title' => $round['scene']['title'],
            'setup' => $round['scene']['setup'],
            'categoryLabel' => $round['scene']['categoryLabel'],
            'categoryEmoji' => $round['scene']['categoryEmoji'],
            // دوري أنا — ولا أحد غيري يراه قبل الكشف.
            'myRole' => $myRole,
            'transcript' => $round['transcript'],
            // الأحداث العامة للجميع، والخاصة لصاحبها وحده.
            'myEvents' => $this->visibleEvents($round, $viewer->id),
            'myClaim' => $round['claims'][$viewer->id] ?? null,
            'claimedCount' => count($round['claims']),
            'activeCount' => count($this->activePlayerIds($state)),
            'canClaimEvent' => $this->receivedPrivateEvent($state, $viewer->id),
            // الكشف الكبير: الأدوار والأهداف والادّعاءات دفعةً واحدة.
            'rows' => $revealed ? $this->claimRows($state) : [],
            'myChallengesLeft' => (int) config('mashhad.limits.challenges_per_player')
                - ($round['challengeCounts'][$viewer->id] ?? 0),
            'challenge' => $this->currentChallengePayload($state, $viewer),
            'sceneScores' => $phase === MashhadPhase::Scoreboard
                ? $this->scoreRows($state, $round['scores'] ?? [])
                : [],
            'awards' => $phase === MashhadPhase::Awards ? config('mashhad.awards') : [],
            // (object) لا مصفوفة: الخريطة الفارغة تُسلسَل `[]` في PHP فينكسر
            // التحليل على الجهاز قبل أن يصوّت اللاعب لأي جائزة.
            'myAwardVotes' => (object) $this->myAwardVotes($round, $viewer->id),
        ];

        return $snapshot;
    }

    // =========================================================================
    // ترحيل إلى القاعدة
    // =========================================================================

    /** @param array<string, mixed> $round */
    private function persistRound(string $gameId, array $round): void
    {
        DB::table('mashhad_rounds')->updateOrInsert(
            ['game_id' => $gameId, 'scene_no' => $round['no']],
            [
                'scene_key' => $round['scene']['key'],
                'title' => $round['scene']['title'],
                'cast' => json_encode($round['cast'], JSON_UNESCAPED_UNICODE),
                'transcript' => json_encode($round['transcript'], JSON_UNESCAPED_UNICODE),
                'events' => json_encode($round['events'], JSON_UNESCAPED_UNICODE),
                'claims' => json_encode((object) $round['claims'], JSON_UNESCAPED_UNICODE),
                'scores' => json_encode((object) ($round['scores'] ?? []), JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, array<string, mixed>>  $players
     */
    private function persistFinish(string $gameId, array $result, array $players): void
    {
        DB::transaction(function () use ($gameId, $result, $players) {
            $game = Game::find($gameId);

            if (! $game) {
                return;
            }

            $game->forceFill([
                'status' => Game::STATUS_FINISHED,
                'finished_at' => now(),
                'result' => $result,
            ])->save();

            foreach ($players as $userId => $player) {
                DB::table('game_players')
                    ->where('game_id', $gameId)
                    ->where('user_id', $userId)
                    ->update([
                        'final_score' => $player['isSpectator'] ? null : $player['total'],
                        'is_spectator' => $player['isSpectator'],
                    ]);
            }

            Channel::where('id', $game->channel_id)
                ->where('active_game_id', $gameId)
                ->update(['active_game_id' => null]);
        });
    }

    private function abandonGame(Game $game): void
    {
        DB::transaction(function () use ($game) {
            $game->forceFill([
                'status' => Game::STATUS_ABANDONED,
                'finished_at' => now(),
            ])->save();

            Channel::where('id', $game->channel_id)
                ->where('active_game_id', $game->id)
                ->update(['active_game_id' => null]);
        });
    }

    /** @param array<string, mixed> $player */
    private function persistPlayer(Game $game, User $user, array $player): void
    {
        DB::table('game_players')->updateOrInsert(
            ['game_id' => $game->id, 'user_id' => $user->id],
            ['join_order' => $player['joinOrder'], 'is_spectator' => $player['isSpectator']],
        );
    }

    // =========================================================================
    // مساعدات
    // =========================================================================

    /**
     * @param  array<string, mixed>  $scene
     * @return array<string, mixed>
     */
    private function emptyRound(int $no, array $scene): array
    {
        return [
            'no' => $no,
            'phase' => MashhadPhase::RoleReveal->value,
            'phaseStartedAt' => 0,
            'deadline' => 0,
            'nextTickAt' => 0,
            'sceneStartedAt' => 0,
            'scene' => $scene,
            'cast' => [],
            'transcript' => [],
            'events' => [],
            'claims' => [],
            'challenges' => [],
            'challengeCounts' => [],
            'currentChallenge' => null,
            'discussable' => [],
            'scores' => null,
            'awardVotes' => [],
        ];
    }

    /** هل وصل هذا اللاعب حدثٌ خاص أُطلق فعلاً؟ */
    private function receivedPrivateEvent(array $state, string $userId): bool
    {
        foreach ($state['round']['events'] ?? [] as $event) {
            if ($event['fired'] && $event['scope'] === 'one' && $event['userId'] === $userId) {
                return true;
            }
        }

        return false;
    }

    /**
     * ما يراه هذا اللاعب من الأحداث: العامة كلها، وخاصّته وحدها.
     *
     * @param  array<string, mixed>  $round
     * @return array<int, array<string, mixed>>
     */
    private function visibleEvents(array $round, string $userId): array
    {
        $visible = [];

        foreach ($round['events'] as $event) {
            if (! $event['fired']) {
                continue;
            }

            if ($event['scope'] === 'all' || $event['userId'] === $userId) {
                $visible[] = [
                    'text' => $event['text'],
                    'scope' => $event['scope'],
                    'at' => $event['firedAt'] ?? 0,
                ];
            }
        }

        return $visible;
    }

    private function goalText(array $state, string $userId, string $kind): string
    {
        $role = $state['round']['cast'][$userId] ?? [];

        return match ($kind) {
            'bonus' => (string) ($role['bonus'] ?? ''),
            'event' => 'استغلّ الحدث المفاجئ',
            default => (string) ($role['goal'] ?? ''),
        };
    }

    /**
     * الكشف الكبير: لكل لاعب دوره وهدفه وما ادّعاه.
     *
     * @return array<int, array<string, mixed>>
     */
    private function claimRows(array $state): array
    {
        $rows = [];

        foreach ($this->activePlayerIds($state) as $userId) {
            $role = $state['round']['cast'][$userId] ?? [];
            $claim = $state['round']['claims'][$userId] ?? [];

            $rows[] = [
                'userId' => $userId,
                'username' => $state['players'][$userId]['username'],
                'roleName' => $role['name'] ?? '',
                'goal' => $role['goal'] ?? '',
                'bonus' => $role['bonus'] ?? null,
                'secret' => $role['secret'] ?? null,
                'difficulty' => $role['difficulty'] ?? 'medium',
                'claimedMain' => (bool) ($claim['main'] ?? false),
                'claimedBonus' => (bool) ($claim['bonus'] ?? false),
                'claimedEvent' => (bool) ($claim['event'] ?? false),
                'messageCount' => $state['players'][$userId]['messageCount'],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, array<string, mixed>>  $scores
     * @return array<int, array<string, mixed>>
     */
    private function scoreRows(array $state, array $scores): array
    {
        $rows = [];

        foreach ($this->activePlayerIds($state) as $userId) {
            $score = $scores[$userId] ?? null;
            $role = $state['round']['cast'][$userId] ?? [];

            $rows[] = [
                'userId' => $userId,
                'username' => $state['players'][$userId]['username'],
                'roleName' => $role['name'] ?? '',
                'goal' => $role['goal'] ?? '',
                'mainAchieved' => (bool) ($score['mainAchieved'] ?? false),
                'bonusAchieved' => (bool) ($score['bonusAchieved'] ?? false),
                'eventAchieved' => (bool) ($score['eventAchieved'] ?? false),
                'penalties' => (int) ($score['penalties'] ?? 0),
                'points' => (int) ($score['total'] ?? 0),
            ];
        }

        usort($rows, fn ($a, $b) => $b['points'] <=> $a['points']);

        return $rows;
    }

    /** @return array<string, mixed>|null */
    private function currentChallengePayload(array $state, User $viewer): ?array
    {
        $index = $state['round']['currentChallenge'] ?? null;

        if ($index === null) {
            return null;
        }

        $challenge = $state['round']['challenges'][$index];
        $target = $challenge['targetUserId'];
        $parties = array_merge([$challenge['by'], $target], $challenge['coChallengers']);

        return [
            'challengeId' => $challenge['id'],
            'by' => $challenge['by'],
            'byUsername' => $state['players'][$challenge['by']]['username'],
            'targetUserId' => $target,
            'targetUsername' => $state['players'][$target]['username'],
            'kind' => $challenge['kind'],
            'roleName' => $state['round']['cast'][$target]['name'] ?? '',
            'goal' => $this->goalText($state, $target, $challenge['kind']),
            // الطرفان والشركاء يرون الشاشة بلا أزرار: كلهم أصحاب مصلحة.
            'isParty' => in_array($viewer->id, $parties, true),
            'hasVoted' => isset($challenge['votes'][$viewer->id]),
        ];
    }

    /** @return array<string, string> */
    private function myAwardVotes(array $round, string $userId): array
    {
        $mine = [];

        foreach ($round['awardVotes'] ?? [] as $key => $votes) {
            if (isset($votes[$userId])) {
                $mine[$key] = $votes[$userId];
            }
        }

        return $mine;
    }

    private function findChallenge(array $state, string $targetUserId, string $kind): ?int
    {
        foreach ($state['round']['challenges'] as $index => $challenge) {
            if ($challenge['targetUserId'] === $targetUserId && $challenge['kind'] === $kind) {
                return $index;
            }
        }

        return null;
    }

    /**
     * طرفا الاعتراض لا يصوّتان — كلاهما صاحب مصلحة، والشركاء مثلهما.
     *
     * @param  array<string, mixed>  $challenge
     * @return array<int, string>
     */
    private function eligibleVoters(array $state, array $challenge): array
    {
        $parties = array_merge([$challenge['by'], $challenge['targetUserId']], $challenge['coChallengers']);

        return array_values(array_diff($this->activePlayerIds($state), $parties));
    }

    /** @return array<int, string> */
    private function activePlayerIds(array $state): array
    {
        $ids = [];

        foreach ($state['order'] as $userId) {
            $player = $state['players'][$userId] ?? null;

            if ($player && ! $player['isSpectator'] && $player['leftAtScene'] === null) {
                $ids[] = $userId;
            }
        }

        return $ids;
    }

    /** @return array<int, string> */
    private function seatedPlayerIds(array $state): array
    {
        $players = array_filter(
            $state['players'],
            fn ($p) => ! $p['isSpectator'] && $p['leftAtScene'] === null,
        );

        uasort($players, fn ($a, $b) => $a['joinOrder'] <=> $b['joinOrder']);

        return array_keys($players);
    }

    private function isActivePlayer(array $state, string $userId): bool
    {
        return in_array($userId, $this->activePlayerIds($state), true);
    }

    private function inPhase(array $state, MashhadPhase $phase): bool
    {
        return $state['status'] === Game::STATUS_PLAYING
            && $state['round'] !== null
            && $state['round']['phase'] === $phase->value;
    }

    /** @return array<string, mixed> */
    private function addPlayer(array $state, User $user, bool $spectator): array
    {
        $state['players'][$user->id] = [
            'id' => $user->id,
            'username' => $user->username,
            'fullName' => $user->full_name,
            'photoUrl' => $user->photo_url,
            'avatarId' => $user->avatar_id,
            'gender' => $user->gender,
            'joinOrder' => count($state['players']) + 1,
            'isSpectator' => $spectator,
            'leftAtScene' => null,
            'total' => 0,
            'goalsAchieved' => 0,
            'messageCount' => 0,
            'awards' => [],
        ];

        if (! $spectator) {
            $state['order'][] = $user->id;
        }

        return $state;
    }

    /** @return array<string, mixed> */
    private function reassignHostIfNeeded(array $state, string $leavingUserId): array
    {
        if ($state['hostId'] !== $leavingUserId) {
            return $state;
        }

        $remaining = $state['status'] === Game::STATUS_LOBBY
            ? $this->seatedPlayerIds($state)
            : $this->activePlayerIds($state);

        if ($remaining !== []) {
            $state['hostId'] = $remaining[0];
        }

        return $state;
    }

    /** @return array<int, array<string, mixed>> */
    private function standings(array $state): array
    {
        $rows = [];

        foreach ($state['players'] as $userId => $player) {
            if ($player['isSpectator']) {
                continue;
            }

            $rows[] = [
                'userId' => $userId,
                'username' => $player['username'],
                'photoUrl' => $player['photoUrl'],
                'avatarId' => $player['avatarId'],
                'total' => $player['total'],
                'goalsAchieved' => $player['goalsAchieved'],
                'messageCount' => $player['messageCount'],
                'awards' => $player['awards'],
                'left' => $player['leftAtScene'] !== null,
                'joinOrder' => $player['joinOrder'],
            ];
        }

        usort($rows, fn ($a, $b) => [$b['total'], $a['joinOrder']] <=> [$a['total'], $b['joinOrder']]);

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function playersPayload(array $state): array
    {
        $players = array_values($state['players']);

        usort($players, fn ($a, $b) => $a['joinOrder'] <=> $b['joinOrder']);

        return $players;
    }

    /** @return array<string, mixed> */
    private function phasePayload(array $state): array
    {
        $round = $state['round'];

        return [
            'scene' => $round['no'],
            'totalScenes' => $state['config']['scenes'],
            'phase' => $round['phase'],
            'phaseStartedAt' => $round['phaseStartedAt'],
            'deadline' => $round['deadline'],
        ];
    }

    /** @return array<string, mixed> */
    private function lobbyPayload(array $state): array
    {
        $seated = $this->seatedPlayerIds($state);

        return [
            'players' => $this->playersPayload($state),
            'hostId' => $state['hostId'],
            'estimatedSeconds' => $this->estimateSeconds($state['config'], count($seated)),
            'canStart' => count($seated) >= (int) config('mashhad.limits.min_players'),
        ];
    }

    /**
     * المدة المتوقعة — السطر الحي في اللوبي.
     *
     * @param  array<string, mixed>  $config
     */
    public function estimateSeconds(array $config, int $playerCount): int
    {
        $phases = config('mashhad.phases');
        $scenes = (int) ($config['scenes'] ?? 2);

        // نفترض اعتراضاً واحداً وسطياً في كل مشهد.
        $perScene = $phases['role_reveal']
            + (int) ($config['sceneSeconds'] ?? 180)
            + $phases['claims']
            + $phases['challenge']
            + $phases['verdict']
            + $phases['scoreboard'];

        return $scenes * $perScene + $phases['awards'];
    }

    private function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /**
     * @param  Closure(array<string, mixed>, Effects): (array<string, mixed>|null)  $mutator
     * @return array<string, mixed>|null
     */
    private function transaction(string $gameId, Closure $mutator): ?array
    {
        $fx = new Effects(fn (string $id, int $seq) => new AdvanceMashhadPhase($id, $seq));
        $channelId = null;

        $next = $this->store->mutate($gameId, function (?array $state) use ($mutator, $fx, &$channelId) {
            if ($state === null) {
                return [null, null];
            }

            $channelId = $state['channelId'];

            $updated = $mutator($state, $fx);

            return [$updated, $updated];
        });

        $fx->flush($gameId, $channelId);

        return $next;
    }
}
