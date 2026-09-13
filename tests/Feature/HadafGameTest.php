<?php

use App\Games\Hadaf\HadafConfig;
use App\Games\Hadaf\HadafEngine;
use App\Games\Hadaf\Questions\QuestionPool;
use App\Models\Channel;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// المؤقّتات مهام مؤجّلة؛ في الاختبار نوقفها ونحرّك المراحل يدوياً.
beforeEach(function () {
    Queue::fake();
});

// =============================================================================
// الغرفة والشروط
// =============================================================================

it('يفتح غرفة هدف ويقفل القناة على لعبة واحدة', function () {
    ['owner' => $owner, 'channel' => $channel] = makeTable();

    $game = $this->postJson("/api/channels/{$channel->id}/games", [
        'gameType' => 'hadaf',
        'config' => ['category' => 'math', 'rounds' => 5, 'questionSeconds' => 15],
    ], authHeaders($owner))->assertCreated()->json();

    expect($channel->fresh()->active_game_id)->toBe($game['game']['id'])
        ->and($game['state']['config']['category'])->toBe('math')
        ->and($game['state']['config']['rounds'])->toBe(5)
        ->and($game['state']['config']['questionSeconds'])->toBe(15);
});

it('يفرض الوضع المرن وقتاً أطول وصعوبة سهلة', function () {
    ['owner' => $owner, 'channel' => $channel] = makeTable();

    $state = $this->postJson("/api/channels/{$channel->id}/games", [
        'gameType' => 'hadaf',
        'config' => ['flexibleMode' => true, 'questionSeconds' => 15, 'rounds' => 12],
    ], authHeaders($owner))->json('state');

    expect($state['config']['questionSeconds'])->toBe(config('hadaf.flexible.question_seconds'))
        ->and($state['config']['flexibleMode'])->toBeTrue();

    $config = new HadafConfig(null, 12, 40, true);

    // مرن = سهل حتى آخر جولة.
    expect($config->difficultyFor(1))->toBe('easy')
        ->and($config->difficultyFor(12))->toBe('easy');
});

it('يدرّج الصعوبة مع تقدّم الجولات', function () {
    $config = new HadafConfig(null, 8, 20, false);

    expect($config->difficultyFor(1))->toBe('easy')
        ->and($config->difficultyFor(8))->toBe('hard')
        ->and([$config->difficultyFor(1), $config->difficultyFor(4), $config->difficultyFor(8)])
        ->toBe(['easy', 'medium', 'hard']);
});

it('يعرض لعبة الهدف في كتالوج الألعاب', function () {
    ['owner' => $owner] = makeTable();

    $games = collect($this->getJson('/api/games/catalog', authHeaders($owner))->json('games'));
    $hadaf = $games->firstWhere('gameType', 'hadaf');

    expect($hadaf)->not->toBeNull()
        ->and($hadaf['icon'])->toBe('🎯')
        ->and($hadaf['minPlayers'])->toBe(2)
        ->and(collect($hadaf['configSchema'])->pluck('key')->all())
        ->toBe(['category', 'rounds', 'questionSeconds', 'flexibleMode']);
});

// =============================================================================
// السرّية: موقع الجواب
// =============================================================================

it('لا يسرّب الجواب الصحيح أثناء السباق ويكشفه بعد الإقفال', function () {
    $table = seatedHadafGame($this);
    $game = $table['game'];

    advanceHadaf($game); // الاستعداد ← السؤال

    $racing = hadafSnapshot($this, $table['players'][0], $game)['round'];

    expect($racing['phase'])->toBe('question')
        ->and($racing['question']['choices'])->toHaveCount(4)
        ->and($racing['question'])->not->toHaveKey('answerIndex')
        ->and($racing['answerIndex'])->toBeNull()
        ->and($racing['myCorrect'])->toBeNull();

    advanceHadaf($game); // انتهى الوقت ← الكشف

    $revealed = hadafSnapshot($this, $table['players'][0], $game)['round'];

    expect($revealed['phase'])->toBe('reveal')
        ->and($revealed['answerIndex'])->toBeInt();
});

it('لا يكشف السؤال في مرحلة الاستعداد', function () {
    $table = seatedHadafGame($this);

    $round = hadafSnapshot($this, $table['players'][0], $table['game'])['round'];

    expect($round['phase'])->toBe('ready')
        ->and($round['question'])->not->toHaveKey('prompt')
        ->and($round['question'])->not->toHaveKey('choices')
        // المجموعة والصعوبة تُعلَنان — تشويق بلا تسريب.
        ->and($round['question']['categoryLabel'])->not->toBeNull();
});

it('لا يقبل إجابة قبل أن يُفتح السؤال', function () {
    $table = seatedHadafGame($this);

    answerHadaf($this, $table['players'][0], $table['game'], 0);

    expect(gameState($table['game'])['round']['answers'])->toBe([]);
});

// =============================================================================
// السباق
// =============================================================================

it('يرتّب المصيبين بختم السيرفر لا بترتيب وصولهم المزعوم', function () {
    $table = seatedHadafGame($this);
    $game = $table['game'];
    advanceHadaf($game);

    $correct = correctChoice($game);

    // الثاني يجيب أولاً، فيأخذ هو مكافأة السرعة الأولى.
    answerHadaf($this, $table['players'][1], $game, $correct);
    answerHadaf($this, $table['players'][0], $game, $correct);
    answerHadaf($this, $table['players'][2], $game, $correct);

    $scores = gameState($game)['round']['scores'];
    $points = config('hadaf.points');

    expect($scores[$table['players'][1]->id]['rank'])->toBe(1)
        ->and($scores[$table['players'][1]->id]['speed'])->toBe($points['speed_bonus'][0])
        ->and($scores[$table['players'][0]->id]['rank'])->toBe(2)
        ->and($scores[$table['players'][0]->id]['speed'])->toBe($points['speed_bonus'][1])
        ->and($scores[$table['players'][2]->id]['speed'])->toBe($points['speed_bonus'][2]);
});

it('يقفل السؤال فوراً حين يجيب الجميع', function () {
    $table = seatedHadafGame($this);
    $game = $table['game'];
    advanceHadaf($game);

    foreach ($table['players'] as $player) {
        answerHadaf($this, $player, $game, 0);
    }

    expect(gameState($game)['round']['phase'])->toBe('reveal');
});

it('يمنع تبديل الإجابة بعد إرسالها', function () {
    $table = seatedHadafGame($this);
    $game = $table['game'];
    advanceHadaf($game);

    $correct = correctChoice($game);
    $wrong = ($correct + 1) % 4;

    answerHadaf($this, $table['players'][0], $game, $wrong);
    answerHadaf($this, $table['players'][0], $game, $correct);

    expect(gameState($game)['round']['answers'][$table['players'][0]->id]['choice'])->toBe($wrong);
});

it('يعطي صفراً لمن لم يجب حتى انتهى الوقت', function () {
    $table = seatedHadafGame($this);
    $game = $table['game'];
    advanceHadaf($game);

    answerHadaf($this, $table['players'][0], $game, correctChoice($game));
    advanceHadaf($game); // انتهى وقت السؤال

    $scores = gameState($game)['round']['scores'];

    expect($scores[$table['players'][1]->id]['total'])->toBe(0)
        ->and($scores[$table['players'][1]->id]['answered'])->toBeFalse()
        ->and($scores[$table['players'][0]->id]['total'])->toBeGreaterThan(0);
});

// =============================================================================
// ⚡ المخاطرة
// =============================================================================

it('يضاعف نقاط المخاطرة عند الإصابة وينقص رصيدها', function () {
    $table = seatedHadafGame($this);
    $game = $table['game'];
    advanceHadaf($game);

    $player = $table['players'][0];
    $risksBefore = gameState($game)['players'][$player->id]['risksLeft'];

    answerHadaf($this, $player, $game, correctChoice($game), risk: true);
    advanceHadaf($game);

    $score = gameState($game)['round']['scores'][$player->id];
    $plain = $score['base'] + $score['speed'] + $score['streak'];

    expect($score['usedRisk'])->toBeTrue()
        ->and($score['total'])->toBe($plain * config('hadaf.points.risk_multiplier'))
        ->and(gameState($game)['players'][$player->id]['risksLeft'])->toBe($risksBefore - 1);
});

it('يخصم عند المخاطرة الخاطئة', function () {
    $table = seatedHadafGame($this);
    $game = $table['game'];
    advanceHadaf($game);

    $player = $table['players'][0];
    $wrong = (correctChoice($game) + 1) % 4;

    answerHadaf($this, $player, $game, $wrong, risk: true);
    advanceHadaf($game);

    expect(gameState($game)['round']['scores'][$player->id]['total'])
        ->toBe(config('hadaf.points.risk_penalty'));
});

it('يتجاهل المخاطرة بعد نفاد رصيدها', function () {
    $table = seatedHadafGame($this, ['rounds' => 12]);
    $game = $table['game'];
    $player = $table['players'][0];
    $limit = (int) config('hadaf.limits.risks_per_game');

    // نستهلك الرصيد كاملاً، ثم نحاول مرة زائدة.
    for ($round = 1; $round <= $limit + 1; $round++) {
        advanceHadaf($game); // الاستعداد ← السؤال
        answerHadaf($this, $player, $game, correctChoice($game), risk: true);

        $used = gameState($game)['round']['answers'][$player->id]['risk'];

        expect($used)->toBe($round <= $limit);

        advanceHadaf($game); // ← الكشف
        advanceHadaf($game); // ← اللوحة
        advanceHadaf($game); // ← الجولة التالية
    }

    expect(gameState($game)['players'][$player->id]['risksLeft'])->toBe(0);
});

// =============================================================================
// السلسلة
// =============================================================================

it('يمنح مكافأة السلسلة من الإجابة الثالثة المتتالية', function () {
    $table = seatedHadafGame($this, ['rounds' => 5]);
    $game = $table['game'];
    $player = $table['players'][0];
    $bonus = (int) config('hadaf.points.streak_bonus');

    $awarded = [];

    for ($round = 1; $round <= 3; $round++) {
        advanceHadaf($game);
        answerHadaf($this, $player, $game, correctChoice($game));
        advanceHadaf($game);

        $awarded[] = gameState($game)['round']['scores'][$player->id]['streak'];

        advanceHadaf($game); // اللوحة
        advanceHadaf($game); // الجولة التالية
    }

    expect($awarded)->toBe([0, 0, $bonus]);
});

it('يصفّر السلسلة عند أول خطأ', function () {
    $table = seatedHadafGame($this, ['rounds' => 5]);
    $game = $table['game'];
    $player = $table['players'][0];

    advanceHadaf($game);
    answerHadaf($this, $player, $game, correctChoice($game));
    advanceHadaf($game);

    expect(gameState($game)['players'][$player->id]['streak'])->toBe(1);

    advanceHadaf($game);
    advanceHadaf($game); // جولة جديدة
    advanceHadaf($game); // ← السؤال

    answerHadaf($this, $player, $game, (correctChoice($game) + 1) % 4);
    advanceHadaf($game);

    expect(gameState($game)['players'][$player->id]['streak'])->toBe(0)
        ->and(gameState($game)['players'][$player->id]['bestStreak'])->toBe(1);
});

// =============================================================================
// النهاية
// =============================================================================

it('يجمع النقاط عبر الجولات وينهي اللعبة بنتيجة محفوظة', function () {
    $table = seatedHadafGame($this, ['rounds' => 5]);
    $game = $table['game'];

    // لاعب واحد يصيب دائماً: لا تعادل فلا جولة حسم.
    for ($round = 1; $round <= 5; $round++) {
        advanceHadaf($game);
        answerHadaf($this, $table['players'][0], $game, correctChoice($game));
        advanceHadaf($game); // ← الكشف
        advanceHadaf($game); // ← اللوحة
        advanceHadaf($game); // ← التالية أو النهاية
    }

    $record = Game::find($game);

    expect($record->status)->toBe(Game::STATUS_FINISHED)
        ->and($record->result['winnerId'])->toBe($table['players'][0]->id)
        ->and($record->result['roundsPlayed'])->toBe(5)
        ->and(DB::table('hadaf_rounds')->where('game_id', $game)->count())->toBe(5);

    expect(gameState($game))->toBeNull()
        ->and(Channel::find($record->channel_id)->active_game_id)->toBeNull();
});

it('يفتح جولة حسم عند تعادل الصدارة ويحسمها أول إجابة صحيحة', function () {
    $table = seatedHadafGame($this, ['rounds' => 5]);
    $game = $table['game'];

    // ما جاوب حدا طوال اللعبة: الكل على صفر — تعادل تام.
    for ($round = 1; $round <= 5; $round++) {
        advanceHadaf($game); // ← السؤال
        advanceHadaf($game); // ← الكشف
        advanceHadaf($game); // ← اللوحة
        advanceHadaf($game); // ← التالية أو جولة الحسم
    }

    $state = gameState($game);

    expect($state['round']['phase'])->toBe('tiebreak')
        ->and($state['round']['tiedPlayers'])->toHaveCount(3);

    answerHadaf($this, $table['players'][2], $game, correctChoice($game));

    $result = Game::find($game)->result;

    expect($result['winnerId'])->toBe($table['players'][2]->id)
        ->and($result['decidedByTiebreak'])->toBeTrue();
});

it('ينهي اللعبة إذا نزل عدد اللاعبين عن الحد', function () {
    $table = seatedHadafGame($this);
    $game = $table['game'];

    $this->postJson("/api/games/{$game}/leave", [], authHeaders($table['players'][1]))->assertOk();
    $this->postJson("/api/games/{$game}/leave", [], authHeaders($table['players'][2]))->assertOk();

    expect(Game::find($game)->result['reason'])->toBe('not_enough_players');
});

it('يمنع من ليس عضواً في القناة من الإجابة', function () {
    $table = seatedHadafGame($this);
    $outsider = makeUser('outsider-hadaf');

    $this->postJson("/api/games/{$table['game']}/hadaf/answer", [
        'choice' => 0,
    ], authHeaders($outsider))->assertStatus(403);
});

it('لا يخلط نوايا الألعاب ببعضها', function () {
    $table = seatedHadafGame($this);

    $this->postJson("/api/games/{$table['game']}/spy/vote", [
        'suspectUserId' => $table['players'][1]->id,
    ], authHeaders($table['owner']))->assertStatus(404);
});

// =============================================================================
// بنك الأسئلة والمولّدات
// =============================================================================

it('لا يكرّر سؤالاً مولَّداً في الجلسة الواحدة', function () {
    $pool = app(QuestionPool::class);

    $seen = [];

    for ($i = 0; $i < 40; $i++) {
        $question = $pool->draw('math', 'easy', $seen);
        $seen[] = $question->fingerprint();
    }

    expect(array_unique($seen))->toHaveCount(40);
});

it('يبني كل سؤال بأربعة خيارات متمايزة فيها الجواب', function () {
    $pool = app(QuestionPool::class);

    foreach (['easy', 'medium', 'hard'] as $difficulty) {
        for ($i = 0; $i < 25; $i++) {
            $question = $pool->draw('math', $difficulty);

            expect($question->choices)->toHaveCount(4)
                ->and(array_unique($question->choices))->toHaveCount(4)
                ->and($question->choices[$question->answerIndex])->toBe($question->answer());
        }
    }
});

it('يخلط موقع الجواب فلا يبقى ثابتاً', function () {
    $pool = app(QuestionPool::class);
    $positions = [];

    for ($i = 0; $i < 60; $i++) {
        $positions[] = $pool->draw('math', 'easy')->answerIndex;
    }

    // لو كان الموقع ثابتاً لحفظه اللاعبون بعد جولتين.
    expect(array_unique($positions))->toHaveCount(4);
});

it('لا يسرّب موقع الجواب في الحمولة العامة', function () {
    $question = app(QuestionPool::class)->draw('math', 'easy');

    expect($question->toPublicArray())->not->toHaveKey('answerIndex')
        ->and($question->toPublicArray())->not->toHaveKey('explanation')
        ->and($question->toArray())->toHaveKey('answerIndex');
});

it('يستورد بنك الأسئلة بلا تكرار مهما أُعيد', function () {
    DB::table('hadaf_questions')->delete();

    $this->artisan('hadaf:import')->assertSuccessful();
    $first = DB::table('hadaf_questions')->count();

    $this->artisan('hadaf:import')->assertSuccessful();

    expect($first)->toBeGreaterThan(150)
        ->and(DB::table('hadaf_questions')->count())->toBe($first);
});

it('يسحب من البنك للمجموعات غير المولَّدة', function () {
    $this->artisan('hadaf:import');

    $question = app(QuestionPool::class)->draw('geography', 'easy');

    expect($question->category)->toBe('geography')
        ->and($question->choices)->toHaveCount(4);
});

// =============================================================================
// مساعدات
// =============================================================================

function openHadafRoom($test, User $owner, Channel $channel, array $config = []): string
{
    return $test->postJson("/api/channels/{$channel->id}/games", [
        'gameType' => 'hadaf',
        // الرياضيات في الاختبار: مولَّدة فلا تعتمد على بنك مستورد.
        'config' => $config + ['category' => 'math', 'rounds' => 5, 'questionSeconds' => 15],
    ], authHeaders($owner))->json('game.id');
}

/** طاولة جاهزة: ثلاثة لاعبين في لعبة هدف بدأت وهي في مرحلة الاستعداد. */
function seatedHadafGame($test, array $config = []): array
{
    $table = makeTable();
    $game = openHadafRoom($test, $table['owner'], $table['channel'], $config);

    foreach (array_slice($table['players'], 1) as $player) {
        joinGame($test, $player, $game);
    }

    $test->postJson("/api/games/{$game}/start", [], authHeaders($table['owner']));

    return $table + ['game' => $game];
}

/** يحاكي انتهاء مهلة المرحلة الحالية تماماً كما يفعل مؤقّت السيرفر. */
function advanceHadaf(string $gameId): void
{
    $state = gameState($gameId);

    if ($state !== null) {
        app(HadafEngine::class)->handleTimeout($gameId, $state['seq']);
    }
}

function hadafSnapshot($test, User $user, string $gameId): array
{
    return $test->getJson("/api/games/{$gameId}", authHeaders($user))->json('state');
}

function answerHadaf($test, User $user, string $gameId, int $choice, bool $risk = false): void
{
    $test->postJson("/api/games/{$gameId}/hadaf/answer", [
        'choice' => $choice, 'risk' => $risk,
    ], authHeaders($user));
}

/** الجواب الصحيح من الحالة الداخلية — ما لا يراه أي لاعب. */
function correctChoice(string $gameId): int
{
    return gameState($gameId)['round']['question']['answerIndex'];
}
