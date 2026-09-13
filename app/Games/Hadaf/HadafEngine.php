<?php

namespace App\Games\Hadaf;

use App\Games\Effects;
use App\Games\Hadaf\Questions\QuestionPool;
use App\Games\State\GameStateStore;
use App\Jobs\AdvanceHadafPhase;
use App\Models\Channel;
use App\Models\Game;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * آلة حالات لعبة الهدف — سباق بالمللي ثانية، والسيرفر وحده يملك الساعة.
 *
 * في لعبة تُحسم بالسرعة، **ختم الوصول هو اللعبة**. ولهذا لا يُقبل أي توقيت
 * من الجهاز إطلاقاً: اللاعب يرسل رقم الخيار فقط، والسيرفر يختم لحظة وصول
 * الطلب. جهاز يدّعي أنه أجاب في المللي ثانية الأولى لا يكسب شيئاً.
 *
 * والقاعدة الثانية: **موقع الجواب الصحيح لا يُبَث أثناء الإجابة** — لا في
 * حدث ولا في لقطة. من يفتح أدوات الشبكة يجب ألا يجد ما يفوز به.
 */
class HadafEngine
{
    public const TYPE = 'hadaf';

    public function __construct(
        private readonly GameStateStore $store,
        private readonly QuestionPool $questions,
        private readonly HadafScoring $scoring,
    ) {}

    // =========================================================================
    // اللوبي
    // =========================================================================

    /** @return array<string, mixed> */
    public function openLobby(Game $game, User $host): array
    {
        $config = HadafConfig::fromArray($game->config);

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
            'currentRound' => 0,
            'usedQuestionIds' => [],
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
                $grace = (int) config('hadaf.limits.rejoin_grace_rounds');

                if ($player['leftAtRound'] !== null && $grace >= $state['currentRound'] - $player['leftAtRound']) {
                    $state['players'][$user->id]['leftAtRound'] = null;
                    $fx->emit('player_returned', ['userId' => $user->id, 'username' => $player['username']]);
                    $fx->emit('lobby_updated', $this->lobbyPayload($state));
                }

                return $state;
            }

            if ($state['status'] === Game::STATUS_LOBBY) {
                if (count($this->seatedPlayerIds($state)) >= (int) config('hadaf.limits.max_players')) {
                    return $state;
                }

                $state = $this->addPlayer($state, $user, spectator: false);
                $fx->defer(fn () => $this->persistPlayer($game, $user, $state['players'][$user->id]));
                $fx->emit('lobby_updated', $this->lobbyPayload($state));

                return $state;
            }

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

            // أثناء اللعب: يبقى في اللوحة بنقاطه المتجمّدة.
            $state['players'][$user->id]['leftAtRound'] = $state['currentRound'];
            $state = $this->reassignHostIfNeeded($state, $user->id);

            $fx->emit('player_left', [
                'userId' => $user->id,
                'username' => $state['players'][$user->id]['username'],
                'activePlayers' => count($this->activePlayerIds($state)),
                'hostId' => $state['hostId'],
            ]);

            if (count($this->activePlayerIds($state)) < (int) config('hadaf.limits.min_players')) {
                return $this->finishGame($state, $fx, endedEarly: true, reason: 'not_enough_players');
            }

            // غادر آخر من لم يجب: لا معنى لانتظار مؤقّت لا ينتظره أحد.
            return $this->closeIfEveryoneAnswered($state, $fx);
        });
    }

    public function start(Game $game, User $user): void
    {
        $this->transaction($game->id, function (array $state, Effects $fx) use ($game, $user) {
            if ($state['status'] !== Game::STATUS_LOBBY || $state['hostId'] !== $user->id) {
                return $state;
            }

            $seated = $this->seatedPlayerIds($state);

            if (count($seated) < (int) config('hadaf.limits.min_players')) {
                return $state;
            }

            $state['status'] = Game::STATUS_PLAYING;
            $state['order'] = $seated;
            $state['currentRound'] = 0;

            $fx->defer(function () use ($game) {
                $game->forceFill([
                    'status' => Game::STATUS_PLAYING,
                    'started_at' => now(),
                ])->save();
            });

            $fx->emit('game_started', [
                'config' => $state['config'],
                'rounds' => $state['config']['rounds'],
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
    // نوايا اللاعبين
    // =========================================================================

    /**
     * إجابة اللاعب.
     *
     * الجهاز يرسل رقم الخيار فقط — **لا توقيت**. السيرفر يختم لحظة الوصول،
     * وهذا الختم وحده هو ما يرتّب المتسابقين.
     */
    public function answer(Game $game, User $user, int $choice, bool $risk): void
    {
        $this->transaction($game->id, function (array $state, Effects $fx) use ($user, $choice, $risk) {
            $racing = $this->inPhase($state, HadafPhase::Question)
                || $this->inPhase($state, HadafPhase::Tiebreak);

            if (! $racing || ! $this->isActivePlayer($state, $user->id)) {
                return $state;
            }

            // في جولة الحسم يتسابق المتعادلون وحدهم؛ البقية يتفرّجون.
            if ($this->inPhase($state, HadafPhase::Tiebreak)
                && ! in_array($user->id, $state['round']['tiedPlayers'], true)
            ) {
                return $state;
            }

            // إجابة واحدة لا تُبدَّل: وإلا صار بالإمكان تجربة الخيارات كلها.
            if (isset($state['round']['answers'][$user->id])) {
                return $state;
            }

            $question = $state['round']['question'];

            if ($choice < 0 || $choice >= count($question['choices'])) {
                return $state;
            }

            // ⚡ محدودة في الجلسة كلها: السقف هو ما يجعلها قراراً.
            $risk = $risk && $state['players'][$user->id]['risksLeft'] > 0;

            if ($risk) {
                $state['players'][$user->id]['risksLeft']--;
            }

            $state['round']['answers'][$user->id] = [
                'choice' => $choice,
                'correct' => $choice === $question['answerIndex'],
                'at' => $this->nowMs(),
                'risk' => $risk,
            ];

            // العدد وحده يُبَث: "من أجاب" معلومة محايدة، أما "من أصاب" فتكشف
            // الجواب لمن ينتظر.
            $fx->emit('answer_locked', [
                'answered' => count($state['round']['answers']),
                'total' => count($this->activePlayerIds($state)),
                'usedRisk' => $risk,
                'userId' => $user->id,
            ]);

            return $this->closeIfEveryoneAnswered($state, $fx);
        });
    }

    public function endEarly(Game $game, User $user): void
    {
        $this->transaction($game->id, function (array $state, Effects $fx) use ($user) {
            if ($state['status'] !== Game::STATUS_PLAYING || $state['hostId'] !== $user->id) {
                return $state;
            }

            if ($state['currentRound'] < (int) config('hadaf.limits.early_end_after_round')) {
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

            return match (HadafPhase::from($state['round']['phase'])) {
                HadafPhase::Ready => $this->openQuestion($state, $fx),
                HadafPhase::Question => $this->closeQuestion($state, $fx),
                HadafPhase::Reveal => $this->enterPhase($state, $fx, HadafPhase::Scoreboard),
                HadafPhase::Scoreboard => $this->nextRoundOrFinish($state, $fx),
                // انتهت جولة الحسم بلا إجابة صحيحة: الأسبق انضماماً يحسم.
                HadafPhase::Tiebreak => $this->resolveTiebreak($state, $fx),
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

        $config = HadafConfig::fromArray($state['config']);
        $question = $this->questions->draw(
            $config->category,
            $config->difficultyFor($state['currentRound']),
            $state['usedQuestionIds'],
        );

        $state['usedQuestionIds'][] = $question->fingerprint();
        $state['round'] = $this->emptyRound($state['currentRound'], $question->toArray());

        $fx->emit('round_started', [
            'round' => $state['currentRound'],
            'rounds' => $state['config']['rounds'],
            // المجموعة والصعوبة تُعلَن مبكراً: جزء من التشويق، وبلا تسريب.
            'category' => $question->category,
            'categoryLabel' => config("hadaf.categories.{$question->category}.label"),
            'categoryEmoji' => config("hadaf.categories.{$question->category}.emoji"),
            'difficulty' => $question->difficulty,
        ]);

        return $this->enterPhase($state, $fx, HadafPhase::Ready);
    }

    /**
     * فتح السؤال للجميع في اللحظة نفسها.
     *
     * الحمولة هنا `toPublicArray()` لا `toArray()`: الفرق بينهما حقل واحد هو
     * موقع الجواب الصحيح — وهو الفرق بين لعبة ولا لعبة.
     *
     * @return array<string, mixed>
     */
    private function openQuestion(array $state, Effects $fx): array
    {
        $fx->emit('question_opened', [
            'round' => $state['currentRound'],
            'question' => $this->publicQuestion($state['round']['question']),
        ]);

        $seconds = $state['round']['phase'] === HadafPhase::Tiebreak->value
            ? null
            : $state['config']['questionSeconds'];

        return $this->enterPhase($state, $fx, HadafPhase::Question, $seconds);
    }

    /**
     * إقفال مبكّر حين لا يبقى من يُنتظر.
     *
     * جولة الحسم استثناء: **أول إجابة صحيحة تحسمها فوراً** ولا تنتظر البقية
     * — هذا نصّ القاعدة، وانتظار من تأخّر بعد أن حُسمت لا معنى له.
     *
     * @return array<string, mixed>|null
     */
    private function closeIfEveryoneAnswered(array $state, Effects $fx): ?array
    {
        if ($this->inPhase($state, HadafPhase::Tiebreak)) {
            $tied = $state['round']['tiedPlayers'];

            $decided = array_filter(
                $state['round']['answers'],
                fn (array $answer, string $userId) => $answer['correct'] && in_array($userId, $tied, true),
                ARRAY_FILTER_USE_BOTH,
            ) !== [];

            $remaining = array_diff($tied, array_keys($state['round']['answers']));

            return $decided || $remaining === []
                ? $this->resolveTiebreak($state, $fx)
                : $state;
        }

        $active = $this->activePlayerIds($state);
        $answered = array_intersect_key($state['round']['answers'], array_flip($active));

        if ($active === [] || count($answered) < count($active)) {
            return $state;
        }

        return $this->closeQuestion($state, $fx);
    }

    /** @return array<string, mixed> */
    private function closeQuestion(array $state, Effects $fx): array
    {
        $playerIds = $this->activePlayerIds($state);

        // السلسلة تُحدَّث قبل الاحتساب: مكافأتها تخصّ هذه الجولة نفسها.
        foreach ($playerIds as $userId) {
            $correct = $state['round']['answers'][$userId]['correct'] ?? false;

            $state['players'][$userId]['streak'] = $correct
                ? $state['players'][$userId]['streak'] + 1
                : 0;

            $state['players'][$userId]['bestStreak'] = max(
                $state['players'][$userId]['bestStreak'],
                $state['players'][$userId]['streak'],
            );

            if ($correct) {
                $state['players'][$userId]['correctCount']++;
            }
        }

        $streaks = [];

        foreach ($playerIds as $userId) {
            $streaks[$userId] = $state['players'][$userId]['streak'];
        }

        $scores = $this->scoring->score($state['round']['answers'], $playerIds, $streaks);

        foreach ($scores as $userId => $row) {
            $state['players'][$userId]['total'] += $row['total'];
        }

        $state['round']['scores'] = $scores;

        $gameId = $state['gameId'];
        $round = $state['round'];
        $fx->defer(fn () => $this->persistRound($gameId, $round));

        $fx->emit('question_closed', [
            'round' => $state['currentRound'],
            'answerIndex' => $state['round']['question']['answerIndex'],
            'answer' => $state['round']['question']['choices'][$state['round']['question']['answerIndex']],
            'explanation' => $state['round']['question']['explanation'],
            'rows' => $this->revealRows($state, $scores),
            'standings' => $this->standings($state),
        ]);

        return $this->enterPhase($state, $fx, HadafPhase::Reveal);
    }

    /** @return array<string, mixed>|null */
    private function nextRoundOrFinish(array $state, Effects $fx): ?array
    {
        if (count($this->activePlayerIds($state)) < (int) config('hadaf.limits.min_players')) {
            return $this->finishGame($state, $fx, endedEarly: true, reason: 'not_enough_players');
        }

        if ($state['currentRound'] >= $state['config']['rounds']) {
            return $this->finishGame($state, $fx, endedEarly: false, reason: null);
        }

        return $this->beginRound($state, $fx);
    }

    /**
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
        $inTiebreak = ($state['round']['phase'] ?? null) === HadafPhase::Tiebreak->value;
        $contenders = array_values(array_intersect($leaders, $this->activePlayerIds($state)));

        $needsTiebreak = $winnerOverride === null
            && ! $inTiebreak
            && $reason !== 'not_enough_players'
            && $state['currentRound'] > 0
            && count($contenders) > 1;

        if ($needsTiebreak) {
            return $this->startTiebreak($state, $fx, $contenders);
        }

        $winnerId = $winnerOverride ?? ($leaders[0] ?? null);

        $result = [
            'winnerId' => $winnerId,
            'finalScores' => $this->standings($state),
            'endedEarly' => $endedEarly,
            'reason' => $reason,
            'roundsPlayed' => $state['currentRound'],
            'totalRounds' => $state['config']['rounds'],
            'decidedByTiebreak' => $winnerOverride !== null || $inTiebreak,
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
     * جولة الحسم: سؤال واحد للمتعادلين، وأول إجابة صحيحة تحسم.
     *
     * @param  array<int, string>  $leaders
     * @return array<string, mixed>
     */
    private function startTiebreak(array $state, Effects $fx, array $leaders): array
    {
        $config = HadafConfig::fromArray($state['config']);
        $question = $this->questions->draw($config->category, 'hard', $state['usedQuestionIds']);

        $state['usedQuestionIds'][] = $question->fingerprint();
        $state['round'] = $this->emptyRound($state['currentRound'], $question->toArray());
        $state['round']['tiedPlayers'] = $leaders;

        $fx->emit('tiebreak_started', ['tiedPlayers' => $leaders]);
        $fx->emit('question_opened', [
            'round' => $state['currentRound'],
            'question' => $this->publicQuestion($state['round']['question']),
        ]);

        return $this->enterPhase($state, $fx, HadafPhase::Tiebreak);
    }

    /** @return array<string, mixed>|null */
    private function resolveTiebreak(array $state, Effects $fx): ?array
    {
        $tied = $state['round']['tiedPlayers'] ?? [];

        $correct = array_filter(
            $state['round']['answers'],
            fn (array $answer, string $userId) => $answer['correct'] && in_array($userId, $tied, true),
            ARRAY_FILTER_USE_BOTH,
        );

        uasort($correct, fn (array $a, array $b) => $a['at'] <=> $b['at']);

        $winner = $correct === [] ? null : (string) array_key_first($correct);

        if ($winner !== null) {
            $fx->emit('tiebreak_decided', [
                'userId' => $winner,
                'username' => $state['players'][$winner]['username'],
            ]);
        }

        return $this->finishGame($state, $fx, endedEarly: false, reason: null, winnerOverride: $winner);
    }

    /**
     * ختم بداية المرحلة ونهايتها بتوقيت السيرفر، وجدولة مؤقّتها.
     *
     * @return array<string, mixed>
     */
    private function enterPhase(array $state, Effects $fx, HadafPhase $phase, ?int $seconds = null): array
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
    // لقطة الحالة
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function snapshot(Game $game, User $viewer): ?array
    {
        $state = $this->store->get($game->id);

        if ($state === null) {
            return null;
        }

        $me = $state['players'][$viewer->id] ?? null;
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
            'currentRound' => $state['currentRound'],
            'totalRounds' => $state['config']['rounds'],
            'standings' => $this->standings($state),
            'estimatedSeconds' => $this->estimateSeconds($state['config'], count($seated)),
            'canStart' => count($seated) >= (int) config('hadaf.limits.min_players'),
            'me' => [
                'isPlayer' => $me !== null && ! $me['isSpectator'],
                'isSpectator' => $me === null || $me['isSpectator'],
                'isHost' => $state['hostId'] === $viewer->id,
                'canEndEarly' => $state['hostId'] === $viewer->id
                    && $state['currentRound'] >= (int) config('hadaf.limits.early_end_after_round'),
                'total' => $me['total'] ?? 0,
                'streak' => $me['streak'] ?? 0,
                'risksLeft' => $me['risksLeft'] ?? 0,
                'correctCount' => $me['correctCount'] ?? 0,
            ],
            'serverTime' => $this->nowMs(),
            'round' => null,
        ];

        if ($round === null || $state['status'] === Game::STATUS_LOBBY) {
            return $snapshot;
        }

        $phase = HadafPhase::from($round['phase']);
        $revealed = in_array($phase, [HadafPhase::Reveal, HadafPhase::Scoreboard], true);
        $mine = $round['answers'][$viewer->id] ?? null;

        $snapshot['round'] = [
            'no' => $round['no'],
            'phase' => $round['phase'],
            'phaseStartedAt' => $round['phaseStartedAt'],
            'deadline' => $round['deadline'],
            // في مرحلة الاستعداد لا يصل السؤال أصلاً — ينطلق الجميع معاً.
            'question' => $phase === HadafPhase::Ready
                ? $this->teaser($round['question'])
                : $this->publicQuestion($round['question']),
            'answeredCount' => count($round['answers']),
            'activeCount' => count($this->activePlayerIds($state)),
            'myChoice' => $mine['choice'] ?? null,
            'myRisk' => $mine['risk'] ?? false,
            // نتيجتي أنا تصلني بعد الإقفال فقط — لا أثناء السباق.
            'myCorrect' => $revealed ? ($mine['correct'] ?? false) : null,
            'answerIndex' => $revealed ? $round['question']['answerIndex'] : null,
            'explanation' => $revealed ? $round['question']['explanation'] : null,
            'rows' => $revealed ? $this->revealRows($state, $round['scores'] ?? []) : [],
            'tiedPlayers' => $round['tiedPlayers'] ?? [],
        ];

        return $snapshot;
    }

    // =========================================================================
    // ترحيل إلى القاعدة
    // =========================================================================

    /** @param array<string, mixed> $round */
    private function persistRound(string $gameId, array $round): void
    {
        DB::table('hadaf_rounds')->updateOrInsert(
            ['game_id' => $gameId, 'round_no' => $round['no']],
            [
                'category' => $round['question']['category'],
                'difficulty' => $round['question']['difficulty'],
                'prompt' => $round['question']['prompt'],
                'answer' => $round['question']['choices'][$round['question']['answerIndex']],
                'answers' => json_encode($round['answers'], JSON_UNESCAPED_UNICODE),
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
     * @param  array<string, mixed>  $question
     * @return array<string, mixed>
     */
    private function emptyRound(int $no, array $question): array
    {
        return [
            'no' => $no,
            'phase' => HadafPhase::Ready->value,
            'phaseStartedAt' => 0,
            'deadline' => 0,
            'question' => $question,
            'answers' => [],
            'scores' => null,
            'tiedPlayers' => [],
        ];
    }

    /**
     * السؤال بلا الجواب — ما يُعرض أثناء السباق.
     *
     * @param  array<string, mixed>  $question
     * @return array<string, mixed>
     */
    private function publicQuestion(array $question): array
    {
        return array_diff_key($question, ['answerIndex' => null, 'explanation' => null]);
    }

    /**
     * مرحلة الاستعداد: المجموعة والصعوبة فقط، بلا نصّ السؤال ولا خياراته.
     *
     * @param  array<string, mixed>  $question
     * @return array<string, mixed>
     */
    private function teaser(array $question): array
    {
        return [
            'category' => $question['category'],
            'categoryLabel' => $question['categoryLabel'],
            'categoryEmoji' => $question['categoryEmoji'],
            'difficulty' => $question['difficulty'],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $scores
     * @return array<int, array<string, mixed>>
     */
    private function revealRows(array $state, array $scores): array
    {
        $rows = [];

        foreach ($this->activePlayerIds($state) as $userId) {
            $answer = $state['round']['answers'][$userId] ?? null;
            $score = $scores[$userId] ?? null;

            $rows[] = [
                'userId' => $userId,
                'username' => $state['players'][$userId]['username'],
                'choice' => $answer['choice'] ?? null,
                'correct' => $answer['correct'] ?? false,
                'usedRisk' => $answer['risk'] ?? false,
                'rank' => $score['rank'] ?? 0,
                'points' => $score['total'] ?? 0,
                'streak' => $state['players'][$userId]['streak'],
            ];
        }

        // الأسرع أولاً، ثم من لم يصب.
        usort($rows, function (array $a, array $b) {
            if ($a['correct'] !== $b['correct']) {
                return $b['correct'] <=> $a['correct'];
            }

            return ($a['rank'] ?: PHP_INT_MAX) <=> ($b['rank'] ?: PHP_INT_MAX);
        });

        return $rows;
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

    private function inPhase(array $state, HadafPhase $phase): bool
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
            'streak' => 0,
            'bestStreak' => 0,
            'correctCount' => 0,
            'risksLeft' => (int) config('hadaf.limits.risks_per_game'),
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
                'streak' => $player['streak'],
                'bestStreak' => $player['bestStreak'],
                'correctCount' => $player['correctCount'],
                'left' => $player['leftAtRound'] !== null,
                'joinOrder' => $player['joinOrder'],
            ];
        }

        usort($rows, fn ($a, $b) => [$b['total'], $a['joinOrder']] <=> [$a['total'], $b['joinOrder']]);

        return $rows;
    }

    /** @return array<int, string> */
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
            'round' => $round['no'],
            'totalRounds' => $state['config']['rounds'],
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
            'canStart' => count($seated) >= (int) config('hadaf.limits.min_players'),
        ];
    }

    /**
     * المدة المتوقعة — السطر الحي في اللوبي.
     *
     * لا تتأثر بعدد اللاعبين: الكل يجيب في الوقت نفسه، وهذا ما يميّز هذه
     * اللعبة عن أخواتها.
     *
     * @param  array<string, mixed>  $config
     */
    public function estimateSeconds(array $config, int $playerCount): int
    {
        $phases = config('hadaf.phases');
        $rounds = (int) ($config['rounds'] ?? 8);

        // نفترض أن الجميع يجيب قبل نهاية الوقت فتُقفل الجولة مبكراً.
        $perRound = $phases['ready']
            + (int) round(((int) ($config['questionSeconds'] ?? 20)) * 0.75)
            + $phases['reveal']
            + $phases['scoreboard'];

        return $rounds * $perRound;
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
        $fx = new Effects(fn (string $id, int $seq) => new AdvanceHadafPhase($id, $seq));
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
