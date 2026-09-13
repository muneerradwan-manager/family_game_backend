<?php

namespace App\Games\Harf;

use App\Games\Effects;
use App\Games\State\GameStateStore;
use App\Jobs\AdvanceHarfPhase;
use App\Jobs\NotifyDrawerTurn;
use App\Models\Channel;
use App\Models\Game;
use App\Models\User;
use App\Support\ArabicText;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * آلة حالات لعبة الحروف — السيرفر مرجع الحقيقة.
 *
 * الأجهزة ترسل نوايا (intents) فقط؛ هذا الصنف يقرّر المرحلة والحرف والأختام
 * الزمنية والنقاط. أي نيّة لا تحقّق شرطها تُتجاهل بصمت (لا رسالة خطأ تكشف
 * حالة اللعبة لمن لا يحقّ له معرفتها).
 *
 * كل تعديل يجري داخل transaction() أي تحت قفل حصري، فتنجح دائماً العملية
 * الأولى فقط عند التزامن (ضغطة سحب عند 9.9 ث ومؤقّت السيرفر معاً مثلاً).
 */
class HarfEngine
{
    public const TYPE = 'harf';

    private const MAX_ANSWER_LENGTH = 40;

    public function __construct(
        private readonly GameStateStore $store,
        private readonly HarfScoring $scoring,
    ) {}

    // =========================================================================
    // اللوبي
    // =========================================================================

    /** @return array<string, mixed> */
    public function openLobby(Game $game, User $host): array
    {
        $config = HarfConfig::fromArray($game->config);

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
            'totalRounds' => 0,
            'currentRound' => 0,
            'usedLetters' => [],
            'scoredRounds' => 0,
            'seq' => 0,
            'round' => null,
        ];

        $state = $this->addPlayer($state, $host, spectator: false);
        $this->store->put($game->id, $state);

        return $state;
    }

    /**
     * انضمام للوبي — أو عودة منقطع أثناء اللعب، أو تفرّج لمن تأخّر.
     *
     * @return array<string, mixed>|null
     */
    public function join(Game $game, User $user): ?array
    {
        return $this->transaction($game->id, function (array $state, Effects $fx) use ($game, $user) {
            if (isset($state['players'][$user->id])) {
                $player = $state['players'][$user->id];

                // العائد خلال جولتين يعود بنقاطه ومكانه في الدور.
                $graceRounds = (int) config('harf.limits.rejoin_grace_rounds');

                if ($player['leftAtRound'] !== null && $graceRounds >= $state['currentRound'] - $player['leftAtRound']) {
                    $state['players'][$user->id]['leftAtRound'] = null;
                    $fx->emit('player_returned', ['userId' => $user->id, 'username' => $player['username']]);
                    $fx->emit('lobby_updated', $this->lobbyPayload($state));
                }

                return $state;
            }

            if ($state['status'] === Game::STATUS_LOBBY) {
                if (count($this->seatedPlayerIds($state)) >= (int) config('harf.limits.max_players')) {
                    return $state;
                }

                $state = $this->addPlayer($state, $user, spectator: false);
                $fx->defer(fn () => $this->persistPlayer($game, $user, $state['players'][$user->id]));
                $fx->emit('lobby_updated', $this->lobbyPayload($state));

                return $state;
            }

            // اللعبة بلّشت: متفرّج يرى الحرف واللوحة، ولا يكتب ولا يعترض ولا يصوّت.
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

                // آخر من في اللوبي غادر: تُلغى اللعبة ويُحرَّر قفل القناة.
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

            // أثناء اللعب: يبقى في اللوحة بنقاطه المتجمّدة وجولته تُسحب تلقائياً.
            $state['players'][$user->id]['leftAtRound'] = $state['currentRound'];
            $state = $this->reassignHostIfNeeded($state, $user->id);

            $fx->emit('player_left', [
                'userId' => $user->id,
                'username' => $state['players'][$user->id]['username'],
                'activePlayers' => count($this->activePlayerIds($state)),
                'hostId' => $state['hostId'],
            ]);

            if (count($this->activePlayerIds($state)) < (int) config('harf.limits.min_players')) {
                return $this->finishGame($state, $fx, endedEarly: true, reason: 'not_enough_players');
            }

            return $state;
        });
    }

    /**
     * لحظة "ابدأ" = قفل نهائي: تُقفل القائمة، يُحسب الإجمالي، يُثبَّت ترتيب الأدوار.
     */
    public function start(Game $game, User $user): void
    {
        $this->transaction($game->id, function (array $state, Effects $fx) use ($game, $user) {
            if ($state['status'] !== Game::STATUS_LOBBY || $state['hostId'] !== $user->id) {
                return $state;
            }

            $seated = $this->seatedPlayerIds($state);

            if (count($seated) < (int) config('harf.limits.min_players')) {
                return $state;
            }

            $state['status'] = Game::STATUS_PLAYING;
            $state['order'] = $seated;
            $state['totalRounds'] = count($seated) * $state['config']['roundsPerPlayer'];
            $state['currentRound'] = 0;

            $fx->defer(function () use ($game) {
                $game->forceFill([
                    'status' => Game::STATUS_PLAYING,
                    'started_at' => now(),
                ])->save();
            });

            $fx->emit('game_started', [
                'config' => $state['config'],
                'order' => $state['order'],
                'totalRounds' => $state['totalRounds'],
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

            return $this->beginRound($state, $fx);
        });
    }

    // =========================================================================
    // نوايا اللاعبين أثناء الجولة
    // =========================================================================

    public function drawLetter(Game $game, User $user): void
    {
        $this->transaction($game->id, function (array $state, Effects $fx) use ($user) {
            if (! $this->inPhase($state, HarfPhase::AwaitingLetter)) {
                return $state;
            }

            if ($state['round']['drawerUserId'] !== $user->id) {
                return $state;
            }

            return $this->doDrawLetter($state, $fx, auto: false);
        });
    }

    public function updateAnswer(Game $game, User $user, string $column, string $text): void
    {
        $this->transaction($game->id, function (array $state, Effects $fx) use ($user, $column, $text) {
            $writing = $this->inPhase($state, HarfPhase::Writing);
            $grace = $this->inPhase($state, HarfPhase::Grace);

            if ((! $writing && ! $grace) || ! $this->isActivePlayer($state, $user->id)) {
                return $state;
            }

            if (! in_array($column, $state['config']['columns'], true)) {
                return $state;
            }

            // في مهلة الـ10 ثواني: إكمال الفارغ فقط، لا تعديل على المكتوب.
            if ($grace && trim((string) ($state['round']['answers'][$user->id][$column] ?? '')) !== '') {
                return $state;
            }

            $clean = preg_replace('/\s+/u', ' ', $text) ?? $text;
            $clean = mb_substr(trim($clean), 0, self::MAX_ANSWER_LENGTH);

            $state['round']['answers'][$user->id][$column] = $clean;

            return $this->trackCompletion($state, $fx, $user->id);
        });
    }

    public function pressStop(Game $game, User $user): void
    {
        $this->transaction($game->id, function (array $state, Effects $fx) use ($user) {
            if (! $this->inPhase($state, HarfPhase::Writing) || ! $this->isActivePlayer($state, $user->id)) {
                return $state;
            }

            // الزر مقفول حتى تمتلئ كل الخانات — والسيرفر يتحقق مجدداً لا الجهاز.
            if ($state['round']['stopBy'] !== null || ! $this->hasFilledEveryColumn($state, $user->id)) {
                return $state;
            }

            return $this->registerStop($state, $fx, $user->id, immediate: false);
        });
    }

    public function raiseObjection(Game $game, User $user, string $targetUserId, string $column): void
    {
        $this->transaction($game->id, function (array $state, Effects $fx) use ($user, $targetUserId, $column) {
            if (! $this->inPhase($state, HarfPhase::Objection) || ! $this->isActivePlayer($state, $user->id)) {
                return $state;
            }

            // لا اعتراض على إجابتك.
            if ($targetUserId === $user->id || ! isset($state['players'][$targetUserId])) {
                return $state;
            }

            if (! in_array($column, $state['config']['columns'], true)) {
                return $state;
            }

            // لا اعتراض على خانة فارغة — هي صفر أصلاً.
            if (trim((string) ($state['round']['answers'][$targetUserId][$column] ?? '')) === '') {
                return $state;
            }

            $used = $state['round']['objectionCounts'][$user->id] ?? 0;

            if ($used >= (int) config('harf.limits.objections_per_player')) {
                return $state;
            }

            $existing = $this->findObjectionIndex($state, $targetUserId, $column);

            if ($existing !== null) {
                // اعتراضات متعددة على نفس الخانة = تصويت واحد؛ الأول هو المعترض
                // الرسمي والبقية شركاء بالنتيجة ربحاً وخسارة.
                $objection = $state['round']['objections'][$existing];

                if ($objection['by'] === $user->id || in_array($user->id, $objection['coObjectors'], true)) {
                    return $state;
                }

                $state['round']['objections'][$existing]['coObjectors'][] = $user->id;
            } else {
                $state['round']['objections'][] = [
                    'id' => (string) Str::uuid(),
                    'targetUserId' => $targetUserId,
                    'column' => $column,
                    'by' => $user->id,
                    'coObjectors' => [],
                    'votes' => [],
                    'verdict' => null,
                ];
            }

            $state['round']['objectionCounts'][$user->id] = $used + 1;

            $fx->emit('objection_raised', [
                'by' => $user->id,
                'targetUserId' => $targetUserId,
                'column' => $column,
                'objectionCount' => count($state['round']['objections']),
            ]);

            return $state;
        });
    }

    public function castVote(Game $game, User $user, string $objectionId, bool $answerIsValid): void
    {
        $this->transaction($game->id, function (array $state, Effects $fx) use ($user, $objectionId, $answerIsValid) {
            if (! $this->inPhase($state, HarfPhase::Voting) || ! $this->isActivePlayer($state, $user->id)) {
                return $state;
            }

            $index = $state['round']['currentObjection'];

            if ($index === null || ($state['round']['objections'][$index]['id'] ?? null) !== $objectionId) {
                return $state;
            }

            $eligible = $this->eligibleVoters($state, $state['round']['objections'][$index]);

            if (! in_array($user->id, $eligible, true)) {
                return $state;
            }

            $state['round']['objections'][$index]['votes'][$user->id] = $answerIsValid;
            $voted = count($state['round']['objections'][$index]['votes']);

            $fx->emit('vote_cast', [
                'objectionId' => $objectionId,
                'voted' => $voted,
                'eligible' => count($eligible),
            ]);

            // صوّت الجميع: لا معنى لانتظار بقية الـ15 ثانية.
            if ($voted >= count($eligible)) {
                return $this->resolveCurrentObjection($state, $fx);
            }

            return $state;
        });
    }

    /** جولة الحسم عند تعادل الصدارة: أول إجابة صحيحة تصل بختم السيرفر تحسم. */
    public function submitTiebreak(Game $game, User $user, string $text): void
    {
        $this->transaction($game->id, function (array $state, Effects $fx) use ($user, $text) {
            if (! $this->inPhase($state, HarfPhase::Tiebreak)) {
                return $state;
            }

            if (! in_array($user->id, $state['round']['tiedPlayers'] ?? [], true)) {
                return $state;
            }

            if (! ArabicText::startsWithLetter($text, $state['round']['letter'])) {
                return $state;
            }

            $fx->emit('tiebreak_answered', [
                'userId' => $user->id,
                'username' => $state['players'][$user->id]['username'],
                'answer' => trim($text),
            ]);

            return $this->finishGame($state, $fx, endedEarly: false, reason: null, winnerOverride: $user->id);
        });
    }

    /** زر "إنهاء مبكّر" — للمنشئ، وينتقل لأقدم لاعب إن غادر. */
    public function endEarly(Game $game, User $user): void
    {
        $this->transaction($game->id, function (array $state, Effects $fx) use ($user) {
            if ($state['status'] !== Game::STATUS_PLAYING || $state['hostId'] !== $user->id) {
                return $state;
            }

            if ($state['currentRound'] < (int) config('harf.limits.early_end_after_round')) {
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
            // ختم قديم: تقدّمت المرحلة قبل موعد هذا المؤقّت.
            if ($state['seq'] !== $seq || $state['round'] === null) {
                return $state;
            }

            return match (HarfPhase::from($state['round']['phase'])) {
                HarfPhase::AwaitingLetter => $this->doDrawLetter($state, $fx, auto: true),
                HarfPhase::RevealLetter => $this->startWriting($state, $fx),
                // انتهاء الوقت الأصلي: إقفال مباشر بلا إضافة — الجميع أخذ وقتاً متساوياً.
                HarfPhase::Writing, HarfPhase::Grace => $this->closeWriting($state, $fx),
                HarfPhase::Reveal => $this->enterPhase($state, $fx, HarfPhase::Objection),
                HarfPhase::Objection => $this->startVotingOrScore($state, $fx),
                HarfPhase::Voting => $this->resolveCurrentObjection($state, $fx),
                HarfPhase::Scoreboard => $this->nextRoundOrFinish($state, $fx),
                HarfPhase::Tiebreak => $this->finishGame($state, $fx, endedEarly: false, reason: null),
            };
        });
    }

    /**
     * شبكة أمان للمؤقّتات.
     *
     * المهام المؤجّلة تعيش في Redis فتنجو من إعادة تشغيل السيرفر، لكن قد
     * يسقط عامل الطابور أو تُمسح مهمة. `phaseStartedAt` و`deadline` مخزّنان
     * في الحالة، فيُعاد بناء ما فات منهما هنا ولا تتجمّد لعبة على أحد.
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

            if ($state['round']['deadline'] + $graceMs > $this->nowMs()) {
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
    private function beginRound(array $state, Effects $fx): array
    {
        $state['currentRound']++;

        $order = $state['order'];
        $drawer = $order[($state['currentRound'] - 1) % count($order)];

        $state['round'] = $this->emptyRound($state['currentRound'], $drawer);

        $fx->emit('round_started', [
            'round' => $state['currentRound'],
            'totalRounds' => $state['totalRounds'],
            'drawerUserId' => $drawer,
        ]);

        // "دورك تسحب" — بعد ثوانٍ، ولمن لم يسحب بعد فقط.
        $fx->dispatchLater(new NotifyDrawerTurn($state['gameId'], $state['currentRound']), 4);

        return $this->enterPhase($state, $fx, HarfPhase::AwaitingLetter);
    }

    /**
     * السيرفر يسحب دائماً — بطلب اللاعب أو بانتهاء المهلة. لو اختار الجهاز
     * الحرف لأمكن التلاعب بالطلب. والحرف يُبَث مرة واحدة للجميع معاً.
     *
     * @return array<string, mixed>
     */
    private function doDrawLetter(array $state, Effects $fx, bool $auto): array
    {
        $bank = config('harf.letters');
        $available = array_values(array_diff($bank, $state['usedLetters']));

        // نفد البنك (20 لاعباً × 3 جولات يتجاوز عدد الحروف): تبدأ دورة جديدة.
        if ($available === []) {
            $state['usedLetters'] = [];
            $available = $bank;
        }

        $letter = $available[random_int(0, count($available) - 1)];

        $state['usedLetters'][] = $letter;
        $state['round']['letter'] = $letter;

        $fx->emit('letter_drawn', [
            'round' => $state['currentRound'],
            'letter' => $letter,
            'drawerUserId' => $state['round']['drawerUserId'],
            'auto' => $auto,
        ]);

        return $this->enterPhase($state, $fx, HarfPhase::RevealLetter);
    }

    /** @return array<string, mixed> */
    private function startWriting(array $state, Effects $fx): array
    {
        return $this->enterPhase($state, $fx, HarfPhase::Writing, $state['config']['writeSeconds']);
    }

    /** @return array<string, mixed> */
    private function registerStop(array $state, Effects $fx, string $userId, bool $immediate): array
    {
        $state['round']['stopBy'] = $userId;
        $state['round']['stopAt'] = $this->nowMs();

        $fx->emit('stop_pressed', [
            'byUserId' => $userId,
            'byUsername' => $state['players'][$userId]['username'],
            'immediate' => $immediate,
        ]);

        // اكتمال الجميع يوقف الجولة فوراً؛ الستوب اليدوي يمنح 10 ث لإكمال الفارغ.
        return $immediate
            ? $this->closeWriting($state, $fx)
            : $this->enterPhase($state, $fx, HarfPhase::Grace);
    }

    /** @return array<string, mixed> */
    private function closeWriting(array $state, Effects $fx): array
    {
        $fx->emit('answers_revealed', [
            'round' => $state['currentRound'],
            'letter' => $state['round']['letter'],
            'columns' => $state['config']['columns'],
            'stopBy' => $state['round']['stopBy'],
            'rows' => $this->answerRows($state),
        ]);

        // مرحلة العرض: قراءة وضحك بلا أزرار — مفصولة عمداً عن الاعتراض.
        return $this->enterPhase($state, $fx, HarfPhase::Reveal);
    }

    /** @return array<string, mixed> */
    private function startVotingOrScore(array $state, Effects $fx): array
    {
        // تُناقَش أول 4 اعتراضات فقط بترتيب الورود؛ الباقي يُقبل تلقائياً بلا عقوبة.
        $limit = (int) config('harf.limits.objections_discussed');
        $discussable = [];

        foreach ($state['round']['objections'] as $index => $objection) {
            if (count($discussable) < $limit) {
                $discussable[] = $index;

                continue;
            }

            $state['round']['objections'][$index]['verdict'] = 'auto_accepted';
        }

        $state['round']['discussable'] = $discussable;
        $state['round']['currentObjection'] = null;

        return $this->openNextObjection($state, $fx);
    }

    /**
     * الاعتراضات تُعرض واحداً تلو الآخر لا دفعة واحدة.
     *
     * @return array<string, mixed>
     */
    private function openNextObjection(array $state, Effects $fx): array
    {
        foreach ($state['round']['discussable'] as $position => $index) {
            if ($state['round']['objections'][$index]['verdict'] !== null) {
                continue;
            }

            $state['round']['currentObjection'] = $index;
            $objection = $state['round']['objections'][$index];

            $fx->emit('objection_opened', [
                'objectionId' => $objection['id'],
                'by' => $objection['by'],
                'byUsername' => $state['players'][$objection['by']]['username'],
                'targetUserId' => $objection['targetUserId'],
                'targetUsername' => $state['players'][$objection['targetUserId']]['username'],
                'column' => $objection['column'],
                'columnLabel' => $state['config']['columnLabels'][$objection['column']] ?? $objection['column'],
                'answer' => $state['round']['answers'][$objection['targetUserId']][$objection['column']] ?? '',
                // الطرفان والشركاء يرون الشاشة بلا أزرار: "أنت طرف بهالاعتراض".
                'parties' => array_values(array_unique(array_merge(
                    [$objection['by'], $objection['targetUserId']],
                    $objection['coObjectors'],
                ))),
                'index' => $position + 1,
                'total' => count($state['round']['discussable']),
            ]);

            return $this->enterPhase($state, $fx, HarfPhase::Voting);
        }

        $state['round']['currentObjection'] = null;

        return $this->scoreRound($state, $fx);
    }

    /** @return array<string, mixed> */
    private function resolveCurrentObjection(array $state, Effects $fx): array
    {
        $index = $state['round']['currentObjection'];

        if ($index === null) {
            return $this->scoreRound($state, $fx);
        }

        $votes = $state['round']['objections'][$index]['votes'];
        $forValid = count(array_filter($votes));
        $forInvalid = count($votes) - $forValid;

        // التعادل أو صفر أصوات = قبول الإجابة — الشك لمصلحة اللاعب.
        $verdict = $forInvalid > $forValid ? 'invalid' : 'valid';
        $state['round']['objections'][$index]['verdict'] = $verdict;

        $fx->emit('vote_result', [
            'objectionId' => $state['round']['objections'][$index]['id'],
            'targetUserId' => $state['round']['objections'][$index]['targetUserId'],
            'column' => $state['round']['objections'][$index]['column'],
            'verdict' => $verdict,
            'validVotes' => $forValid,
            'invalidVotes' => $forInvalid,
        ]);

        return $this->openNextObjection($state, $fx);
    }

    /** @return array<string, mixed> */
    private function scoreRound(array $state, Effects $fx): array
    {
        $playerIds = $this->activePlayerIds($state);

        $roundScores = $this->scoring->score(
            $state['config']['columns'],
            $state['round']['answers'],
            $state['round']['objections'],
            $playerIds,
            $state['round']['stopBy'],
        );

        foreach ($roundScores as $userId => $row) {
            $state['players'][$userId]['total'] += $row['total'];
        }

        $state['round']['scores'] = $roundScores;
        $state['scoredRounds']++;

        // ترحيل الجولة إلى الأرشيف الدائم فور انتهائها.
        $gameId = $state['gameId'];
        $round = $state['round'];
        $fx->defer(fn () => $this->persistRound($gameId, $round));

        $fx->emit('scoreboard', [
            'round' => $state['currentRound'],
            'totalRounds' => $state['totalRounds'],
            'letter' => $state['round']['letter'],
            'roundScores' => $roundScores,
            'standings' => $this->standings($state),
        ]);

        return $this->enterPhase($state, $fx, HarfPhase::Scoreboard);
    }

    /** @return array<string, mixed>|null */
    private function nextRoundOrFinish(array $state, Effects $fx): ?array
    {
        if (count($this->activePlayerIds($state)) < (int) config('harf.limits.min_players')) {
            return $this->finishGame($state, $fx, endedEarly: true, reason: 'not_enough_players');
        }

        if ($state['currentRound'] >= $state['totalRounds']) {
            return $this->finishGame($state, $fx, endedEarly: false, reason: null);
        }

        return $this->beginRound($state, $fx);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null null تعني أن الحالة الحيّة مُسحت
     */
    private function finishGame(
        array $state,
        Effects $fx,
        bool $endedEarly,
        ?string $reason,
        ?string $winnerOverride = null,
    ): ?array {
        $leaders = $this->leaders($state);
        $inTiebreak = ($state['round']['phase'] ?? null) === HarfPhase::Tiebreak->value;

        // من غادر يبقى في اللوحة بنقاطه، لكنه لا يستطيع لعب جولة حسم.
        $contenders = array_values(array_intersect($leaders, $this->activePlayerIds($state)));

        // جولة الحسم لها معنى فقط بعد جولة محتسَبة واحدة على الأقل، ولا معنى
        // لها حين تُقفل اللعبة لنقص اللاعبين أصلاً.
        $needsTiebreak = $winnerOverride === null
            && ! $inTiebreak
            && $reason !== 'not_enough_players'
            && ($state['scoredRounds'] ?? 0) > 0
            && count($contenders) > 1;

        if ($needsTiebreak) {
            return $this->startTiebreak($state, $fx, $contenders);
        }

        // انتهت مهلة جولة الحسم بلا إجابة: الأسبق انضماماً يحسم.
        $winnerId = $winnerOverride ?? ($leaders[0] ?? null);

        $result = [
            'winnerId' => $winnerId,
            'finalScores' => $this->standings($state),
            'endedEarly' => $endedEarly,
            'reason' => $reason,
            'roundsPlayed' => $state['currentRound'],
            'totalRounds' => $state['totalRounds'],
            'decidedByTiebreak' => $winnerOverride !== null,
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
     * @param  array<int, string>  $leaders
     * @return array<string, mixed>
     */
    private function startTiebreak(array $state, Effects $fx, array $leaders): array
    {
        $bank = config('harf.letters');
        $columns = $state['config']['columns'];

        $state['round'] = $this->emptyRound($state['currentRound'], $leaders[0]) + [];
        $state['round']['letter'] = $bank[random_int(0, count($bank) - 1)];
        $state['round']['tiedPlayers'] = $leaders;
        $state['round']['column'] = $columns[random_int(0, count($columns) - 1)];

        $fx->emit('tiebreak_started', [
            'letter' => $state['round']['letter'],
            'column' => $state['round']['column'],
            'columnLabel' => $state['config']['columnLabels'][$state['round']['column']] ?? $state['round']['column'],
            'tiedPlayers' => $leaders,
        ]);

        return $this->enterPhase($state, $fx, HarfPhase::Tiebreak);
    }

    /**
     * ختم بداية المرحلة ونهايتها بتوقيت السيرفر، وجدولة مؤقّتها.
     * الجهاز يحسب العدّاد المعروض من هذين الرقمين فقط.
     *
     * @return array<string, mixed>
     */
    private function enterPhase(array $state, Effects $fx, HarfPhase $phase, ?int $seconds = null): array
    {
        $duration = $seconds ?? $phase->duration() ?? 0;
        $now = $this->nowMs();

        $state['seq']++;
        $state['round']['phase'] = $phase->value;
        $state['round']['phaseStartedAt'] = $now;
        $state['round']['deadline'] = $now + ($duration * 1000);

        $fx->scheduleTimeout($state['seq'], $duration);
        $fx->emit('phase_changed', $this->phasePayload($state));

        return $state;
    }

    // =========================================================================
    // لقطة الحالة (انضمام / إعادة اتصال)
    // =========================================================================

    /**
     * الحالة كما يراها هذا المستخدم تحديداً: إجاباته هو أثناء الكتابة،
     * وإجابات الجميع فقط بعد أن يفتح السيرفر مرحلة العرض.
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
        $round = $state['round'];

        $snapshot = [
            'gameId' => $state['gameId'],
            'channelId' => $state['channelId'],
            'gameType' => self::TYPE,
            'status' => $state['status'],
            'config' => $state['config'],
            'hostId' => $state['hostId'],
            'players' => $this->playersPayload($state),
            'order' => $state['order'],
            'currentRound' => $state['currentRound'],
            'totalRounds' => $state['totalRounds'],
            'standings' => $this->standings($state),
            'estimatedSeconds' => $this->estimateSeconds($state['config'], count($this->seatedPlayerIds($state))),
            'canStart' => count($this->seatedPlayerIds($state)) >= (int) config('harf.limits.min_players'),
            'me' => [
                'isPlayer' => $me !== null && ! $me['isSpectator'],
                'isSpectator' => $me === null || $me['isSpectator'],
                'isHost' => $state['hostId'] === $viewer->id,
                'canEndEarly' => $state['hostId'] === $viewer->id
                    && $state['currentRound'] >= (int) config('harf.limits.early_end_after_round'),
                'total' => $me['total'] ?? 0,
            ],
            'serverTime' => $this->nowMs(),
            'round' => null,
        ];

        if ($round === null) {
            return $snapshot;
        }

        $phase = HarfPhase::from($round['phase']);
        $revealed = in_array($phase, [HarfPhase::Reveal, HarfPhase::Objection, HarfPhase::Voting, HarfPhase::Scoreboard], true);

        $snapshot['round'] = [
            'no' => $round['no'],
            'phase' => $round['phase'],
            'phaseStartedAt' => $round['phaseStartedAt'],
            'deadline' => $round['deadline'],
            'drawerUserId' => $round['drawerUserId'],
            'letter' => $phase === HarfPhase::AwaitingLetter ? null : $round['letter'],
            'stopBy' => $round['stopBy'],
            // (object) لا مصفوفة: PHP يسلسل المصفوفة الترابطية الفارغة إلى
            // `[]` لا `{}`، فينكسر التحليل على الجهاز حين لا يكون اللاعب قد
            // كتب شيئاً بعد. والعقد يجب أن يكون ثابتاً: خريطة تبقى خريطة.
            'myAnswers' => (object) ($round['answers'][$viewer->id] ?? []),
            'myObjectionsLeft' => (int) config('harf.limits.objections_per_player')
                - ($round['objectionCounts'][$viewer->id] ?? 0),
            'rows' => $revealed ? $this->answerRows($state) : [],
            'roundScores' => $phase === HarfPhase::Scoreboard
                ? (object) ($round['scores'] ?? [])
                : null,
            'objection' => $this->currentObjectionPayload($state, $viewer),
            'tiedPlayers' => $round['tiedPlayers'] ?? [],
            'tiebreakColumn' => $round['column'] ?? null,
        ];

        return $snapshot;
    }

    /** @return array<string, mixed>|null */
    private function currentObjectionPayload(array $state, User $viewer): ?array
    {
        $index = $state['round']['currentObjection'] ?? null;

        if ($index === null) {
            return null;
        }

        $objection = $state['round']['objections'][$index];
        $parties = array_merge([$objection['by'], $objection['targetUserId']], $objection['coObjectors']);

        return [
            'objectionId' => $objection['id'],
            'by' => $objection['by'],
            'byUsername' => $state['players'][$objection['by']]['username'],
            'targetUserId' => $objection['targetUserId'],
            'targetUsername' => $state['players'][$objection['targetUserId']]['username'],
            'column' => $objection['column'],
            'columnLabel' => $state['config']['columnLabels'][$objection['column']] ?? $objection['column'],
            'answer' => $state['round']['answers'][$objection['targetUserId']][$objection['column']] ?? '',
            'isParty' => in_array($viewer->id, $parties, true),
            'hasVoted' => isset($objection['votes'][$viewer->id]),
        ];
    }

    // =========================================================================
    // ترحيل إلى القاعدة
    // =========================================================================

    /** @param array<string, mixed> $round */
    private function persistRound(string $gameId, array $round): void
    {
        // جدولا الجولات واللاعبين بمفتاح مركّب لا مفرد، فنكتب عبر باني الاستعلام.
        DB::table('harf_rounds')->updateOrInsert(
            ['game_id' => $gameId, 'round_no' => $round['no']],
            [
                'drawer_user_id' => $round['drawerUserId'],
                'letter' => $round['letter'] ?? '?',
                'stop_by' => $round['stopBy'],
                'answers' => json_encode($round['answers'], JSON_UNESCAPED_UNICODE),
                'objections' => json_encode($round['objections'], JSON_UNESCAPED_UNICODE),
                'scores' => json_encode($round['scores'] ?? [], JSON_UNESCAPED_UNICODE),
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

            // تحرير قفل "لعبة نشطة واحدة" — بشرط أنه ما زال يشير لهذه اللعبة.
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

    /** @return array<string, mixed> */
    private function emptyRound(int $no, string $drawerUserId): array
    {
        return [
            'no' => $no,
            'phase' => HarfPhase::AwaitingLetter->value,
            'drawerUserId' => $drawerUserId,
            'letter' => null,
            'phaseStartedAt' => 0,
            'deadline' => 0,
            'answers' => [],
            'completedAt' => [],
            'stopBy' => null,
            'stopAt' => null,
            'objections' => [],
            'objectionCounts' => [],
            'currentObjection' => null,
            'discussable' => [],
            'scores' => null,
            'tiedPlayers' => [],
            'column' => null,
        ];
    }

    /**
     * اكتمال الجميع يوقف الجولة فوراً، وأول المكتملين يُعامَل كضاغط الستوب.
     *
     * @return array<string, mixed>
     */
    private function trackCompletion(array $state, Effects $fx, string $userId): array
    {
        $complete = $this->hasFilledEveryColumn($state, $userId);
        $already = isset($state['round']['completedAt'][$userId]);

        if ($complete && ! $already) {
            $state['round']['completedAt'][$userId] = $this->nowMs();
        } elseif (! $complete && $already) {
            unset($state['round']['completedAt'][$userId]);
        }

        if (! $this->inPhase($state, HarfPhase::Writing) || $state['round']['stopBy'] !== null) {
            return $state;
        }

        $active = $this->activePlayerIds($state);
        $completed = array_intersect_key($state['round']['completedAt'], array_flip($active));

        if (count($completed) < count($active)) {
            return $state;
        }

        asort($completed);

        return $this->registerStop($state, $fx, (string) array_key_first($completed), immediate: true);
    }

    /** @param array<string, mixed> $state */
    private function hasFilledEveryColumn(array $state, string $userId): bool
    {
        foreach ($state['config']['columns'] as $column) {
            if (trim((string) ($state['round']['answers'][$userId][$column] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $state */
    private function findObjectionIndex(array $state, string $targetUserId, string $column): ?int
    {
        foreach ($state['round']['objections'] as $index => $objection) {
            if ($objection['targetUserId'] === $targetUserId && $objection['column'] === $column) {
                return $index;
            }
        }

        return null;
    }

    /**
     * طرفا الاعتراض لا يصوّتان — كلاهما صاحب مصلحة، والشركاء بالنتيجة مثلهما.
     *
     * @param  array<string, mixed>  $objection
     * @return array<int, string>
     */
    private function eligibleVoters(array $state, array $objection): array
    {
        $parties = array_merge([$objection['by'], $objection['targetUserId']], $objection['coObjectors']);

        return array_values(array_diff($this->activePlayerIds($state), $parties));
    }

    /** @return array<int, string> */
    private function activePlayerIds(array $state): array
    {
        $ids = [];

        foreach ($state['order'] as $userId) {
            $player = $state['players'][$userId] ?? null;

            if ($player && ! $player['isSpectator'] && $player['leftAtRound'] === null) {
                $ids[] = $userId;
            }
        }

        return $ids;
    }

    /**
     * اللاعبون الجالسون بترتيب انضمامهم — منه يُشتق ترتيب الأدوار لحظة "ابدأ".
     *
     * @return array<int, string>
     */
    private function seatedPlayerIds(array $state): array
    {
        $players = array_filter($state['players'], fn ($p) => ! $p['isSpectator'] && $p['leftAtRound'] === null);

        uasort($players, fn ($a, $b) => $a['joinOrder'] <=> $b['joinOrder']);

        return array_keys($players);
    }

    private function isActivePlayer(array $state, string $userId): bool
    {
        return in_array($userId, $this->activePlayerIds($state), true);
    }

    private function inPhase(array $state, HarfPhase $phase): bool
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
            'leftAtRound' => null,
            'total' => 0,
        ];

        if (! $spectator) {
            $state['order'][] = $user->id;
        }

        return $state;
    }

    /**
     * غادر المضيف: "إنهاء مبكّر" تنتقل لأقدم لاعب باقٍ.
     *
     * @return array<string, mixed>
     */
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
                'left' => $player['leftAtRound'] !== null,
                'joinOrder' => $player['joinOrder'],
            ];
        }

        usort($rows, fn ($a, $b) => [$b['total'], $a['joinOrder']] <=> [$a['total'], $b['joinOrder']]);

        return $rows;
    }

    /** المتصدّرون بنفس أعلى نتيجة. @return array<int, string> */
    private function leaders(array $state): array
    {
        $standings = $this->standings($state);

        if ($standings === []) {
            return [];
        }

        $top = $standings[0]['total'];

        return array_values(array_map(
            fn ($row) => $row['userId'],
            array_filter($standings, fn ($row) => $row['total'] === $top),
        ));
    }

    /** @return array<int, array<string, mixed>> */
    private function answerRows(array $state): array
    {
        $rows = [];

        foreach ($this->activePlayerIds($state) as $userId) {
            $values = [];

            foreach ($state['config']['columns'] as $column) {
                $values[$column] = $state['round']['answers'][$userId][$column] ?? '';
            }

            $rows[] = [
                'userId' => $userId,
                'username' => $state['players'][$userId]['username'],
                'answers' => $values,
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function phasePayload(array $state): array
    {
        $round = $state['round'];

        return [
            'round' => $state['currentRound'],
            'totalRounds' => $state['totalRounds'],
            'phase' => $round['phase'],
            'phaseStartedAt' => $round['phaseStartedAt'],
            'deadline' => $round['deadline'],
            'drawerUserId' => $round['drawerUserId'],
            'letter' => $round['phase'] === HarfPhase::AwaitingLetter->value ? null : $round['letter'],
            'stopBy' => $round['stopBy'],
        ];
    }

    /** @return array<string, mixed> */
    private function lobbyPayload(array $state): array
    {
        $seated = $this->seatedPlayerIds($state);

        return [
            'players' => $this->playersPayload($state),
            'hostId' => $state['hostId'],
            'totalRounds' => count($seated) * $state['config']['roundsPerPlayer'],
            'estimatedSeconds' => $this->estimateSeconds($state['config'], count($seated)),
            'canStart' => count($seated) >= (int) config('harf.limits.min_players'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function playersPayload(array $state): array
    {
        $players = array_values($state['players']);

        usort($players, fn ($a, $b) => $a['joinOrder'] <=> $b['joinOrder']);

        return $players;
    }

    /**
     * المدة المتوقعة — السطر الحي في اللوبي يتحدّث منها مع كل انضمام.
     *
     * @param  array<string, mixed>  $config
     */
    public function estimateSeconds(array $config, int $playerCount): int
    {
        $phases = config('harf.phases');
        $rounds = max($playerCount, 0) * ($config['roundsPerPlayer'] ?? 1);

        // نفترض أن الكتابة تُقفل قبل نهاية وقتها (ستوب) وأن نصف الجولات تشهد اعتراضاً.
        $perRound = $phases['awaiting_letter']
            + $phases['reveal_letter']
            + (int) round(($config['writeSeconds'] ?? 90) * 0.7)
            + $phases['grace']
            + $phases['reveal']
            + $phases['objection']
            + (int) round($phases['voting'] * 1.5)
            + $phases['scoreboard'];

        return $rounds * $perRound;
    }

    private function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /**
     * قراءة-تعديل-كتابة تحت قفل، ثم تنفيذ الآثار الجانبية بعد تحريره.
     *
     * @param  Closure(array<string, mixed>, Effects): (array<string, mixed>|null)  $mutator
     * @return array<string, mixed>|null
     */
    private function transaction(string $gameId, Closure $mutator): ?array
    {
        $fx = new Effects(fn (string $id, int $seq) => new AdvanceHarfPhase($id, $seq));
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
