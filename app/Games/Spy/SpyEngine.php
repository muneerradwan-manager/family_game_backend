<?php

namespace App\Games\Spy;

use App\Games\Effects;
use App\Games\State\GameStateStore;
use App\Jobs\AdvanceSpyPhase;
use App\Models\Channel;
use App\Models\Game;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * آلة حالات لعبة الجاسوس — السيرفر مرجع الحقيقة، والسرّ سرّه وحده.
 *
 * هذه اللعبة تختلف عن لعبة الحروف في شيء جوهري: إخفاء المعلومة ليس تحسيناً
 * للتجربة بل هو اللعبة نفسها. فالقاعدة الحاكمة هنا أن **الكلمة وهوية الجاسوس
 * لا تمرّان على القناة الحيّة أبداً** قبل انتهاء الجلسة — لا في حدث ولا في
 * حمولة عابرة. كلاهما يصل لكل لاعب في `snapshot()` وحده، مبنيّاً على هويته
 * هو، فما لا يحقّ له معرفته لا يصل جهازه أصلاً ولا يمكن استخراجه من السجل.
 *
 * وكما في بقية الألعاب: كل تعديل يجري تحت قفل حصري، وأي نيّة لا تحقّق شرطها
 * تُتجاهل بصمت — رسالة الخطأ نفسها تكشف حالة اللعبة لمن لا يحقّ له معرفتها.
 */
class SpyEngine
{
    public const TYPE = 'spy';

    public function __construct(private readonly GameStateStore $store) {}

    // =========================================================================
    // اللوبي
    // =========================================================================

    /** @return array<string, mixed> */
    public function openLobby(Game $game, User $host): array
    {
        $config = SpyConfig::fromArray($game->config);

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
            'turnOrder' => [],
            // السرّ يُولَد لحظة "ابدأ" لا لحظة فتح الغرفة: لا شيء سرّي يعيش
            // في الحالة أطول مما يلزم.
            'secret' => null,
            'spyUserId' => null,
            'currentRound' => 0,
            'turns' => [],
            'ejections' => [],
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
                $grace = (int) config('spy.limits.rejoin_grace_rounds');

                if ($player['leftAtRound'] !== null && $grace >= $state['currentRound'] - $player['leftAtRound']) {
                    $state['players'][$user->id]['leftAtRound'] = null;
                    $fx->emit('player_returned', ['userId' => $user->id, 'username' => $player['username']]);
                    $fx->emit('lobby_updated', $this->lobbyPayload($state));
                }

                return $state;
            }

            if ($state['status'] === Game::STATUS_LOBBY) {
                if (count($this->seatedPlayerIds($state)) >= (int) config('spy.limits.max_players')) {
                    return $state;
                }

                $state = $this->addPlayer($state, $user, spectator: false);
                $fx->defer(fn () => $this->persistPlayer($game, $user, $state['players'][$user->id]));
                $fx->emit('lobby_updated', $this->lobbyPayload($state));

                return $state;
            }

            // اللعبة بلّشت: متفرّج يقرأ السجل ولا يعرف الكلمة ولا يصوّت.
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

            $state['players'][$user->id]['leftAtRound'] = $state['currentRound'];
            $state = $this->reassignHostIfNeeded($state, $user->id);

            $fx->emit('player_left', [
                'userId' => $user->id,
                'username' => $state['players'][$user->id]['username'],
                'activePlayers' => count($this->activePlayerIds($state)),
                'hostId' => $state['hostId'],
            ]);

            // الجاسوس هرب: لا معنى لمطاردة من غادر، واللاعبون كشفوه بمغادرته.
            if ($user->id === $state['spyUserId']) {
                return $this->finishGame($state, $fx, 'players', 'spy_left');
            }

            if (count($this->activePlayerIds($state)) <= (int) config('spy.limits.spy_escapes_at')) {
                return $this->finishGame($state, $fx, 'spy', 'spy_survived');
            }

            // غاب صاحب الدور أو المسؤول: نُنهي دوره فوراً بدل انتظار مؤقّته.
            return $this->repairStalledTurn($state, $fx);
        });
    }

    /**
     * لحظة "ابدأ" = قفل نهائي: تُقفل القائمة، تُسحب الكلمة، يُختار الجاسوس،
     * ويُثبَّت ترتيب الأدوار مخلوطاً حتى لا يعرف أحد من سيبدأ.
     */
    public function start(Game $game, User $user): void
    {
        $this->transaction($game->id, function (array $state, Effects $fx) use ($game, $user) {
            if ($state['status'] !== Game::STATUS_LOBBY || $state['hostId'] !== $user->id) {
                return $state;
            }

            $seated = $this->seatedPlayerIds($state);

            if (count($seated) < (int) config('spy.limits.min_players')) {
                return $state;
            }

            $state['status'] = Game::STATUS_PLAYING;
            $state['order'] = $seated;
            $state['secret'] = $this->drawSecret($state['config']['category']);
            $state['spyUserId'] = $seated[random_int(0, count($seated) - 1)];
            $state['turnOrder'] = $this->shuffled($seated);
            $state['currentRound'] = 0;

            $fx->defer(function () use ($game) {
                $game->forceFill([
                    'status' => Game::STATUS_PLAYING,
                    'started_at' => now(),
                ])->save();
            });

            // لا كلمة ولا جاسوس في هذا الحدث: كل لاعب يقرأ دوره من لقطته وحده.
            $fx->emit('game_started', [
                'config' => $state['config'],
                'category' => $state['secret']['category'],
                'categoryLabel' => $state['secret']['label'],
                'categoryEmoji' => $state['secret']['emoji'],
                'turnOrder' => $state['turnOrder'],
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

            // مرحلة كشف الدور: كل واحد يفتح ورقته على جهازه قبل أن تبدأ الأسئلة.
            $state['round'] = $this->emptyRound(0, []);

            return $this->enterPhase($state, $fx, SpyPhase::RoleReveal);
        });
    }

    // =========================================================================
    // نوايا اللاعبين
    // =========================================================================

    /** صاحب الدور يختار لاعباً ويكتب سؤاله — بلا ذكر الكلمة طبعاً. */
    public function askQuestion(Game $game, User $user, string $targetUserId, string $question): void
    {
        $this->transaction($game->id, function (array $state, Effects $fx) use ($user, $targetUserId, $question) {
            if (! $this->inPhase($state, SpyPhase::Asking)) {
                return $state;
            }

            if ($state['round']['currentAskerId'] !== $user->id) {
                return $state;
            }

            // لا يسأل نفسه، ولا يسأل من خرج أو غادر.
            if ($targetUserId === $user->id || ! $this->isActivePlayer($state, $targetUserId)) {
                return $state;
            }

            $text = $this->clean($question, (int) config('spy.limits.question_length'));

            if ($text === '') {
                return $state;
            }

            $turn = [
                'id' => (string) Str::uuid(),
                'round' => $state['currentRound'],
                'askerId' => $user->id,
                'askerUsername' => $state['players'][$user->id]['username'],
                'targetId' => $targetUserId,
                'targetUsername' => $state['players'][$targetUserId]['username'],
                'question' => $text,
                'answer' => null,
                'skipped' => false,
                'unanswered' => false,
            ];

            $state['turns'][] = $turn;
            $state['round']['currentTurnId'] = $turn['id'];

            $fx->emit('question_asked', $turn);

            return $this->enterPhase($state, $fx, SpyPhase::Answering, $state['config']['turnSeconds']);
        });
    }

    /** المسؤول يجيب بما يثبت أنه يعرف الكلمة — دون أن يهديها للجاسوس. */
    public function answerQuestion(Game $game, User $user, string $answer): void
    {
        $this->transaction($game->id, function (array $state, Effects $fx) use ($user, $answer) {
            if (! $this->inPhase($state, SpyPhase::Answering)) {
                return $state;
            }

            $index = $this->currentTurnIndex($state);

            if ($index === null || $state['turns'][$index]['targetId'] !== $user->id) {
                return $state;
            }

            $text = $this->clean($answer, (int) config('spy.limits.answer_length'));

            if ($text === '') {
                return $state;
            }

            $state['turns'][$index]['answer'] = $text;

            $fx->emit('answer_given', [
                'turnId' => $state['turns'][$index]['id'],
                'answer' => $text,
                'unanswered' => false,
            ]);

            return $this->openNextTurn($state, $fx);
        });
    }

    public function castVote(Game $game, User $user, string $suspectUserId): void
    {
        $this->transaction($game->id, function (array $state, Effects $fx) use ($user, $suspectUserId) {
            if (! $this->inPhase($state, SpyPhase::Voting) || ! $this->isActivePlayer($state, $user->id)) {
                return $state;
            }

            // لا يصوّت على نفسه، ولا على من خرج أصلاً.
            if ($suspectUserId === $user->id || ! $this->isActivePlayer($state, $suspectUserId)) {
                return $state;
            }

            $state['round']['votes'][$user->id] = $suspectUserId;

            $eligible = count($this->activePlayerIds($state));
            $voted = count($state['round']['votes']);

            // من صوّت لمن يبقى سرّاً حتى إعلان النتيجة: الرقم وحده يُبَث.
            $fx->emit('vote_cast', ['voted' => $voted, 'eligible' => $eligible]);

            if ($voted >= $eligible) {
                return $this->resolveVotes($state, $fx);
            }

            return $state;
        });
    }

    /** فرصة الجاسوس الأخيرة: اختيار من كلمات مجموعته — لا كتابة حرة. */
    public function submitGuess(Game $game, User $user, string $word): void
    {
        $this->transaction($game->id, function (array $state, Effects $fx) use ($user, $word) {
            if (! $this->inPhase($state, SpyPhase::SpyGuess) || $user->id !== $state['spyUserId']) {
                return $state;
            }

            if (! in_array($word, $state['round']['guessOptions'], true)) {
                return $state;
            }

            $correct = $word === $state['secret']['word'];
            $state['round']['guess'] = ['word' => $word, 'correct' => $correct];

            return $correct
                ? $this->finishGame($state, $fx, 'spy', 'spy_guessed_word')
                : $this->finishGame($state, $fx, 'players', 'spy_caught');
        });
    }

    /** زر "إنهاء مبكّر" — للمنشئ، وينتقل لأقدم لاعب إن غادر. */
    public function endEarly(Game $game, User $user): void
    {
        $this->transaction($game->id, function (array $state, Effects $fx) use ($user) {
            if ($state['status'] !== Game::STATUS_PLAYING || $state['hostId'] !== $user->id) {
                return $state;
            }

            if ($state['currentRound'] < (int) config('spy.limits.early_end_after_round')) {
                return $state;
            }

            // إنهاء إداري لا انتصار لأحد — لكن الكلمة والجاسوس يُكشفان،
            // فلا أحد يغادر الجلسة وهو يتساءل.
            return $this->finishGame($state, $fx, 'none', 'ended_early');
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

            return match (SpyPhase::from($state['round']['phase'])) {
                SpyPhase::RoleReveal => $this->beginRound($state, $fx),
                SpyPhase::Asking => $this->skipTurn($state, $fx),
                SpyPhase::Answering => $this->closeUnanswered($state, $fx),
                SpyPhase::Voting => $this->resolveVotes($state, $fx),
                SpyPhase::VoteResult => $this->afterVoteResult($state, $fx),
                // انتهى وقت الجاسوس بلا تخمين: انكشف وخسر.
                SpyPhase::SpyGuess => $this->finishGame($state, $fx, 'players', 'spy_caught'),
            };
        });
    }

    /**
     * شبكة أمان للمؤقّتات — مثل نظيرتها في لعبة الحروف.
     *
     * المهام المؤجّلة تنجو من إعادة تشغيل السيرفر، لكن قد يسقط عامل الطابور
     * أو تُمسح مهمة. `deadline` مخزّن في الحالة، فيُعاد بناء ما فات منه هنا.
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

    /** @return array<string, mixed>|null */
    private function beginRound(array $state, Effects $fx): ?array
    {
        $state['currentRound']++;
        $state['round'] = $this->emptyRound($state['currentRound'], $this->askOrderFor($state));

        $fx->emit('round_started', [
            'round' => $state['currentRound'],
            'maxRounds' => $state['config']['maxRounds'],
            'askOrder' => $state['round']['askQueue'],
        ]);

        return $this->openNextTurn($state, $fx);
    }

    /**
     * الدور التالي في هذه الجولة، أو التصويت إن سأل الجميع.
     *
     * @return array<string, mixed>|null
     */
    private function openNextTurn(array $state, Effects $fx): ?array
    {
        $state['round']['currentTurnId'] = null;

        while ($state['round']['askQueue'] !== []) {
            $asker = array_shift($state['round']['askQueue']);

            // غادر أو خرج بين دورين: نتخطّاه بلا ضجيج.
            if (! $this->isActivePlayer($state, $asker)) {
                continue;
            }

            $state['round']['currentAskerId'] = $asker;

            $fx->emit('turn_started', [
                'askerId' => $asker,
                'askerUsername' => $state['players'][$asker]['username'],
                'remaining' => count($state['round']['askQueue']),
            ]);

            return $this->enterPhase($state, $fx, SpyPhase::Asking, $state['config']['turnSeconds']);
        }

        $state['round']['currentAskerId'] = null;

        return $this->startVoting($state, $fx);
    }

    /** @return array<string, mixed>|null */
    private function skipTurn(array $state, Effects $fx): ?array
    {
        $asker = $state['round']['currentAskerId'];

        if ($asker !== null) {
            $state['turns'][] = [
                'id' => (string) Str::uuid(),
                'round' => $state['currentRound'],
                'askerId' => $asker,
                'askerUsername' => $state['players'][$asker]['username'],
                'targetId' => null,
                'targetUsername' => null,
                'question' => null,
                'answer' => null,
                'skipped' => true,
                'unanswered' => false,
            ];

            $fx->emit('turn_skipped', [
                'askerId' => $asker,
                'askerUsername' => $state['players'][$asker]['username'],
            ]);
        }

        return $this->openNextTurn($state, $fx);
    }

    /** @return array<string, mixed>|null */
    private function closeUnanswered(array $state, Effects $fx): ?array
    {
        $index = $this->currentTurnIndex($state);

        if ($index !== null) {
            $state['turns'][$index]['unanswered'] = true;

            // الصمت نفسه معلومة: يبقى في السجل ظاهراً للجميع.
            $fx->emit('answer_given', [
                'turnId' => $state['turns'][$index]['id'],
                'answer' => null,
                'unanswered' => true,
            ]);
        }

        return $this->openNextTurn($state, $fx);
    }

    /** @return array<string, mixed> */
    private function startVoting(array $state, Effects $fx): array
    {
        $fx->emit('voting_started', [
            'round' => $state['currentRound'],
            'eligible' => count($this->activePlayerIds($state)),
        ]);

        return $this->enterPhase($state, $fx, SpyPhase::Voting);
    }

    /**
     * فرز الأصوات.
     *
     * التعادل على الصدارة — أو غياب الأصوات أصلاً — يعني ألا أحد يخرج:
     * إخراج لاعب بلا أغلبية واضحة عقوبة بلا سبب، والجولة التالية أعدل.
     *
     * @return array<string, mixed>
     */
    private function resolveVotes(array $state, Effects $fx): array
    {
        $counts = [];

        foreach ($this->activePlayerIds($state) as $userId) {
            $counts[$userId] = 0;
        }

        foreach ($state['round']['votes'] as $suspectId) {
            if (isset($counts[$suspectId])) {
                $counts[$suspectId]++;
            }
        }

        $top = $counts === [] ? 0 : max($counts);
        $leaders = array_keys($counts, $top, true);
        $tie = $top === 0 || count($leaders) > 1;

        $ejected = $tie ? null : $leaders[0];
        $wasSpy = $ejected !== null && $ejected === $state['spyUserId'];

        if ($ejected !== null) {
            $state['players'][$ejected]['outAtRound'] = $state['currentRound'];
            $state['ejections'][] = [
                'round' => $state['currentRound'],
                'userId' => $ejected,
                'username' => $state['players'][$ejected]['username'],
                'wasSpy' => $wasSpy,
                'votes' => $top,
            ];
        }

        $state['round']['ejectedUserId'] = $ejected;
        $state['round']['tie'] = $tie;

        $fx->emit('vote_result', [
            'round' => $state['currentRound'],
            'tie' => $tie,
            'ejectedUserId' => $ejected,
            'ejectedUsername' => $ejected === null ? null : $state['players'][$ejected]['username'],
            // إخراج لاعب يكشف دوره فوراً: هذا نصّ اللعبة لا تسريب.
            'wasSpy' => $wasSpy,
            'counts' => $this->voteCountsPayload($state, $counts),
        ]);

        return $this->enterPhase($state, $fx, SpyPhase::VoteResult);
    }

    /** @return array<string, mixed>|null */
    private function afterVoteResult(array $state, Effects $fx): ?array
    {
        $state = $this->persistCurrentRound($state, $fx);

        if ($state['round']['ejectedUserId'] !== null && $state['round']['ejectedUserId'] === $state['spyUserId']) {
            return $state['config']['lastGuess']
                ? $this->startSpyGuess($state, $fx)
                : $this->finishGame($state, $fx, 'players', 'spy_caught');
        }

        // خرج بريء (أو ما خرج حدا): هل بقي للّعبة معنى؟
        if (count($this->activePlayerIds($state)) <= (int) config('spy.limits.spy_escapes_at')) {
            return $this->finishGame($state, $fx, 'spy', 'spy_survived');
        }

        if ($state['currentRound'] >= $state['config']['maxRounds']) {
            return $this->finishGame($state, $fx, 'spy', 'rounds_exhausted');
        }

        return $this->beginRound($state, $fx);
    }

    /** @return array<string, mixed> */
    private function startSpyGuess(array $state, Effects $fx): array
    {
        $state['round']['guessOptions'] = $this->guessOptions($state['secret']);

        $fx->emit('spy_guess_started', [
            'spyUserId' => $state['spyUserId'],
            'spyUsername' => $state['players'][$state['spyUserId']]['username'],
            // الخيارات عامة: الباقون يعرفون الكلمة أصلاً، ومتعتهم أن يتفرّجوا.
            'options' => $state['round']['guessOptions'],
        ]);

        return $this->enterPhase($state, $fx, SpyPhase::SpyGuess);
    }

    /**
     * @param  string  $winner  players | spy | none
     * @return array<string, mixed>|null null تعني أن الحالة الحيّة مُسحت
     */
    private function finishGame(array $state, Effects $fx, string $winner, string $reason): ?array
    {
        // آخر جولة لم تُرحَّل بعد (انتهت اللعبة قبل نهايتها الطبيعية).
        $state = $this->persistCurrentRound($state, $fx);

        $spyId = $state['spyUserId'];

        $result = [
            'winner' => $winner,
            'reason' => $reason,
            'word' => $state['secret']['word'] ?? null,
            'category' => $state['secret']['category'] ?? null,
            'categoryLabel' => $state['secret']['label'] ?? null,
            'categoryEmoji' => $state['secret']['emoji'] ?? null,
            'spyUserId' => $spyId,
            'spyUsername' => $spyId === null ? null : ($state['players'][$spyId]['username'] ?? null),
            'spyGuess' => $state['round']['guess'] ?? null,
            'ejections' => $state['ejections'],
            'roundsPlayed' => $state['currentRound'],
            'maxRounds' => $state['config']['maxRounds'],
            'players' => $this->resultPlayersPayload($state, $winner),
            'headline' => $this->headline($state, $winner, $reason),
        ];

        $gameId = $state['gameId'];
        $players = $result['players'];

        $fx->defer(fn () => $this->persistFinish($gameId, $result, $players));
        $fx->emit('game_finished', ['result' => $result]);
        $fx->emitToChannel('active_game_changed', ['activeGame' => null]);

        $this->store->forget($gameId);

        return null;
    }

    /**
     * ختم بداية المرحلة ونهايتها بتوقيت السيرفر، وجدولة مؤقّتها.
     *
     * @return array<string, mixed>
     */
    private function enterPhase(array $state, Effects $fx, SpyPhase $phase, ?int $seconds = null): array
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

    /**
     * غادر صاحب الدور أو المسؤول عن السؤال: ننهي دوره الآن.
     *
     * بدون هذا تبقى الجلسة معلّقة حتى ينتهي مؤقّت مرحلةٍ لا أحد فيها —
     * دقيقة كاملة ينظر فيها الباقون إلى شاشة لا تتحرك.
     *
     * @return array<string, mixed>|null
     */
    private function repairStalledTurn(array $state, Effects $fx): ?array
    {
        if ($this->inPhase($state, SpyPhase::Asking)) {
            $asker = $state['round']['currentAskerId'];

            return $asker !== null && ! $this->isActivePlayer($state, $asker)
                ? $this->skipTurn($state, $fx)
                : $state;
        }

        if ($this->inPhase($state, SpyPhase::Answering)) {
            $index = $this->currentTurnIndex($state);
            $target = $index === null ? null : $state['turns'][$index]['targetId'];

            return $target !== null && ! $this->isActivePlayer($state, $target)
                ? $this->closeUnanswered($state, $fx)
                : $state;
        }

        // في التصويت: مغادرة أحدهم قد تكون صوته الناقص.
        if ($this->inPhase($state, SpyPhase::Voting)) {
            $eligible = $this->activePlayerIds($state);
            $votes = array_intersect_key($state['round']['votes'], array_flip($eligible));

            if ($votes !== [] && count($votes) >= count($eligible)) {
                $state['round']['votes'] = $votes;

                return $this->resolveVotes($state, $fx);
            }
        }

        return $state;
    }

    // =========================================================================
    // لقطة الحالة (انضمام / إعادة اتصال)
    // =========================================================================

    /**
     * الحالة كما يراها هذا المستخدم تحديداً.
     *
     * هنا يعيش سرّ اللعبة كله: `me.word` تصل لمن يعرف الكلمة وحده، و`me.isSpy`
     * لصاحبها وحده. لا شيء من الاثنين يمرّ في أي حدث مبثوث قبل النهاية.
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
        $isSpy = $isSeated && $viewer->id === $state['spyUserId'];
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
            'turnOrder' => $state['turnOrder'],
            'currentRound' => $state['currentRound'],
            'maxRounds' => $state['config']['maxRounds'],
            // المجموعة معروفة للجميع — الجاسوس يعرف أننا نتكلم عن حيوان،
            // ومن هنا تبدأ حيلته.
            'category' => $state['secret']['category'] ?? null,
            'categoryLabel' => $state['secret']['label'] ?? null,
            'categoryEmoji' => $state['secret']['emoji'] ?? null,
            'transcript' => $state['turns'],
            'ejections' => $state['ejections'],
            'estimatedSeconds' => $this->estimateSeconds($state['config'], count($seated)),
            'canStart' => count($seated) >= (int) config('spy.limits.min_players'),
            'me' => [
                'isPlayer' => $isSeated,
                'isSpectator' => ! $isSeated,
                'isHost' => $state['hostId'] === $viewer->id,
                'canEndEarly' => $state['hostId'] === $viewer->id
                    && $state['currentRound'] >= (int) config('spy.limits.early_end_after_round'),
                'isSpy' => $isSpy,
                'isOut' => $me !== null && $me['outAtRound'] !== null,
                'hasLeft' => $me !== null && $me['leftAtRound'] !== null,
                // الكلمة لمن يعرفها فقط: اللاعبون غير الجاسوس. المتفرّج لا
                // يعرفها (وإلا صار مصدر تسريب)، والجاسوس لا يعرفها بالتعريف.
                'word' => $isSeated && ! $isSpy ? ($state['secret']['word'] ?? null) : null,
            ],
            'serverTime' => $this->nowMs(),
            'round' => null,
        ];

        if ($round === null || $state['status'] === Game::STATUS_LOBBY) {
            return $snapshot;
        }

        $phase = SpyPhase::from($round['phase']);
        $turnIndex = $this->currentTurnIndex($state);
        $isActive = $this->isActivePlayer($state, $viewer->id);

        $snapshot['round'] = [
            'no' => $round['no'],
            'phase' => $round['phase'],
            'phaseStartedAt' => $round['phaseStartedAt'],
            'deadline' => $round['deadline'],
            'currentAskerId' => $round['currentAskerId'],
            'currentAskerUsername' => $round['currentAskerId'] === null
                ? null
                : ($state['players'][$round['currentAskerId']]['username'] ?? null),
            'currentTurn' => $turnIndex === null ? null : $state['turns'][$turnIndex],
            'askQueue' => $round['askQueue'],
            'votedCount' => count($round['votes']),
            'eligibleCount' => count($this->activePlayerIds($state)),
            // صوتك أنت وحده تراه؛ أصوات الباقين تظهر مع النتيجة للجميع معاً.
            'myVote' => $round['votes'][$viewer->id] ?? null,
            'canAsk' => $phase === SpyPhase::Asking && $round['currentAskerId'] === $viewer->id,
            'canAnswer' => $phase === SpyPhase::Answering
                && $turnIndex !== null
                && $state['turns'][$turnIndex]['targetId'] === $viewer->id,
            'canVote' => $phase === SpyPhase::Voting
                && $isActive
                && ! isset($round['votes'][$viewer->id]),
            'voteResult' => $phase === SpyPhase::VoteResult ? [
                'tie' => $round['tie'],
                'ejectedUserId' => $round['ejectedUserId'],
                'ejectedUsername' => $round['ejectedUserId'] === null
                    ? null
                    : ($state['players'][$round['ejectedUserId']]['username'] ?? null),
                'wasSpy' => $round['ejectedUserId'] !== null
                    && $round['ejectedUserId'] === $state['spyUserId'],
                'counts' => $this->voteCountsPayload($state, $this->tally($state)),
            ] : null,
            'guessOptions' => $phase === SpyPhase::SpyGuess ? $round['guessOptions'] : [],
            // في مرحلة التخمين انكشف الجاسوس أصلاً بالتصويت.
            'spyUserId' => $phase === SpyPhase::SpyGuess ? $state['spyUserId'] : null,
            'spyUsername' => $phase === SpyPhase::SpyGuess
                ? ($state['players'][$state['spyUserId']]['username'] ?? null)
                : null,
        ];

        return $snapshot;
    }

    // =========================================================================
    // ترحيل إلى القاعدة
    // =========================================================================

    /**
     * أرشفة الجولة الجارية — مرة واحدة لكل جولة.
     *
     * @return array<string, mixed>
     */
    private function persistCurrentRound(array $state, Effects $fx): array
    {
        $round = $state['round'];

        if ($round === null || $round['no'] < 1 || $round['persisted']) {
            return $state;
        }

        $state['round']['persisted'] = true;

        $gameId = $state['gameId'];
        $no = $round['no'];
        $turns = array_values(array_filter(
            $state['turns'],
            fn (array $turn) => $turn['round'] === $no,
        ));
        $votes = $round['votes'];
        $ejected = $round['ejectedUserId'];
        $tie = $round['tie'];

        $fx->defer(function () use ($gameId, $no, $turns, $votes, $ejected, $tie) {
            DB::table('spy_rounds')->updateOrInsert(
                ['game_id' => $gameId, 'round_no' => $no],
                [
                    'turns' => json_encode($turns, JSON_UNESCAPED_UNICODE),
                    'votes' => json_encode((object) $votes, JSON_UNESCAPED_UNICODE),
                    'ejected_user_id' => $ejected,
                    'tie' => $tie,
                    'created_at' => now(),
                ],
            );
        });

        return $state;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<int, array<string, mixed>>  $players
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

            // لا نقاط في هذه اللعبة، لكن سجل القناة يقرأ عموداً واحداً:
            // 1 لمن فاز و0 لمن خسر — فتبقى "بطولة العيلة" قابلة للجمع.
            foreach ($players as $player) {
                DB::table('game_players')
                    ->where('game_id', $gameId)
                    ->where('user_id', $player['userId'])
                    ->update(['final_score' => $player['won'] ? 1 : 0, 'is_spectator' => false]);
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
    // السرّ
    // =========================================================================

    /**
     * سحب الكلمة ومجموعتها.
     *
     * @return array<string, mixed>
     */
    private function drawSecret(?string $category): array
    {
        $categories = config('spy.categories');

        if ($category === null || ! isset($categories[$category])) {
            $keys = array_keys($categories);
            $category = $keys[random_int(0, count($keys) - 1)];
        }

        $words = $categories[$category]['words'];

        return [
            'category' => $category,
            'label' => $categories[$category]['label'],
            'emoji' => $categories[$category]['emoji'],
            'word' => $words[random_int(0, count($words) - 1)],
        ];
    }

    /**
     * خيارات التخمين: الكلمة الحقيقية + مضلِّلات من مجموعتها، مخلوطة.
     *
     * @param  array<string, mixed>  $secret
     * @return array<int, string>
     */
    private function guessOptions(array $secret): array
    {
        $limit = (int) config('spy.limits.guess_options');
        $pool = array_values(array_diff(
            config("spy.categories.{$secret['category']}.words"),
            [$secret['word']],
        ));

        shuffle($pool);

        return $this->shuffled(array_merge(
            [$secret['word']],
            array_slice($pool, 0, max($limit - 1, 0)),
        ));
    }

    // =========================================================================
    // مساعدات
    // =========================================================================

    /**
     * @param  array<int, string>  $askQueue
     * @return array<string, mixed>
     */
    private function emptyRound(int $no, array $askQueue): array
    {
        return [
            'no' => $no,
            'phase' => SpyPhase::RoleReveal->value,
            'phaseStartedAt' => 0,
            'deadline' => 0,
            'askQueue' => $askQueue,
            'currentAskerId' => null,
            'currentTurnId' => null,
            'votes' => [],
            'ejectedUserId' => null,
            'tie' => false,
            'guessOptions' => [],
            'guess' => null,
            'persisted' => false,
        ];
    }

    /**
     * ترتيب السؤال في هذه الجولة.
     *
     * الترتيب مخلوط مرة واحدة لحظة البدء، ونقطة البداية تتقدّم جولةً بجولة:
     * فلا يبدأ الشخص نفسه كل مرة، ولا يُعاد الخلط فيضيع ما بناه اللاعبون من
     * توقّع لتسلسل الأدوار.
     *
     * @return array<int, string>
     */
    private function askOrderFor(array $state): array
    {
        $order = array_values(array_filter(
            $state['turnOrder'],
            fn (string $id) => $this->isActivePlayer($state, $id),
        ));

        if ($order === []) {
            return [];
        }

        $offset = ($state['currentRound'] - 1) % count($order);

        return array_merge(array_slice($order, $offset), array_slice($order, 0, $offset));
    }

    /** @return array<string, int> */
    private function tally(array $state): array
    {
        $counts = [];

        foreach ($this->activePlayerIds($state) as $userId) {
            $counts[$userId] = 0;
        }

        // من خرج للتوّ لم يعد نشطاً، لكن أصواته يجب أن تظهر في اللوحة.
        $ejected = $state['round']['ejectedUserId'] ?? null;

        if ($ejected !== null) {
            $counts[$ejected] = 0;
        }

        foreach ($state['round']['votes'] ?? [] as $suspectId) {
            if (isset($counts[$suspectId])) {
                $counts[$suspectId]++;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<int, array<string, mixed>>
     */
    private function voteCountsPayload(array $state, array $counts): array
    {
        $rows = [];

        foreach ($counts as $userId => $votes) {
            $rows[] = [
                'userId' => $userId,
                'username' => $state['players'][$userId]['username'] ?? '',
                'votes' => $votes,
            ];
        }

        usort($rows, fn ($a, $b) => $b['votes'] <=> $a['votes']);

        return $rows;
    }

    private function currentTurnIndex(array $state): ?int
    {
        $turnId = $state['round']['currentTurnId'] ?? null;

        if ($turnId === null) {
            return null;
        }

        foreach ($state['turns'] as $index => $turn) {
            if ($turn['id'] === $turnId) {
                return $index;
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private function activePlayerIds(array $state): array
    {
        $ids = [];

        foreach ($state['order'] as $userId) {
            $player = $state['players'][$userId] ?? null;

            if ($player
                && ! $player['isSpectator']
                && $player['leftAtRound'] === null
                && $player['outAtRound'] === null
            ) {
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
            fn ($p) => ! $p['isSpectator'] && $p['leftAtRound'] === null,
        );

        uasort($players, fn ($a, $b) => $a['joinOrder'] <=> $b['joinOrder']);

        return array_keys($players);
    }

    private function isActivePlayer(array $state, string $userId): bool
    {
        return in_array($userId, $this->activePlayerIds($state), true);
    }

    private function inPhase(array $state, SpyPhase $phase): bool
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
            'outAtRound' => null,
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
    private function playersPayload(array $state): array
    {
        $players = array_values($state['players']);

        usort($players, fn ($a, $b) => $a['joinOrder'] <=> $b['joinOrder']);

        return $players;
    }

    /**
     * @param  string  $winner  players | spy | none
     * @return array<int, array<string, mixed>>
     */
    private function resultPlayersPayload(array $state, string $winner): array
    {
        $rows = [];

        foreach ($state['players'] as $userId => $player) {
            if ($player['isSpectator']) {
                continue;
            }

            $wasSpy = $userId === $state['spyUserId'];

            $rows[] = [
                'userId' => $userId,
                'username' => $player['username'],
                'photoUrl' => $player['photoUrl'],
                'avatarId' => $player['avatarId'],
                'wasSpy' => $wasSpy,
                'outAtRound' => $player['outAtRound'],
                'hasLeft' => $player['leftAtRound'] !== null,
                'won' => match ($winner) {
                    'spy' => $wasSpy,
                    'players' => ! $wasSpy,
                    default => false,
                },
                'joinOrder' => $player['joinOrder'],
            ];
        }

        // الجاسوس أولاً: هو أول ما تبحث عنه العين في شاشة النتيجة.
        usort($rows, fn ($a, $b) => [$b['wasSpy'], $a['joinOrder']] <=> [$a['wasSpy'], $b['joinOrder']]);

        return $rows;
    }

    private function headline(array $state, string $winner, string $reason): string
    {
        $spy = $state['spyUserId'] === null
            ? 'الجاسوس'
            : ($state['players'][$state['spyUserId']]['username'] ?? 'الجاسوس');

        return match ($reason) {
            'spy_caught' => "انكشف الجاسوس — {$spy}!",
            'spy_guessed_word' => "{$spy} انكشف بس خمّن الكلمة وفاز!",
            'spy_survived' => "{$spy} نجا — فاز الجاسوس!",
            'rounds_exhausted' => "خلصت الجولات و{$spy} لسا بينكم!",
            'spy_left' => "{$spy} طلع من اللعبة — كان هو الجاسوس.",
            'ended_early' => "انتهت اللعبة — الجاسوس كان {$spy}.",
            default => $winner === 'spy' ? 'فاز الجاسوس' : 'فاز اللاعبين',
        };
    }

    /**
     * المدة المتوقعة — السطر الحي في اللوبي يتحدّث منها مع كل انضمام.
     *
     * @param  array<string, mixed>  $config
     */
    public function estimateSeconds(array $config, int $playerCount): int
    {
        $phases = config('spy.phases');
        $turn = (int) ($config['turnSeconds'] ?? 45);
        $rounds = max((int) ($config['maxRounds'] ?? 4), 1);

        // نفترض أن اللاعبين يسألون ويجيبون قبل نهاية وقتهم، وأن الجاسوس
        // يُكتشف قبل آخر جولة — فنحسب ثلثي الجولات لا كلها.
        $perRound = max($playerCount, 1) * (int) round($turn * 2 * 0.55)
            + $phases['voting']
            + $phases['vote_result'];

        return $phases['role_reveal'] + (int) round($rounds * 0.7) * $perRound;
    }

    private function clean(string $text, int $maxLength): string
    {
        $clean = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return mb_substr(trim($clean), 0, $maxLength);
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    private function shuffled(array $values): array
    {
        shuffle($values);

        return array_values($values);
    }

    /** @return array<string, mixed> */
    private function phasePayload(array $state): array
    {
        $round = $state['round'];

        return [
            'round' => $round['no'],
            'maxRounds' => $state['config']['maxRounds'],
            'phase' => $round['phase'],
            'phaseStartedAt' => $round['phaseStartedAt'],
            'deadline' => $round['deadline'],
            'currentAskerId' => $round['currentAskerId'],
            'askQueue' => $round['askQueue'],
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
            'canStart' => count($seated) >= (int) config('spy.limits.min_players'),
        ];
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
        $fx = new Effects(fn (string $id, int $seq) => new AdvanceSpyPhase($id, $seq));
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
