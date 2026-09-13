<?php

use App\Games\Harf\HarfEngine;
use App\Models\Channel;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

// المؤقّتات مهام مؤجّلة؛ في الاختبار نوقفها ونحرّك المراحل يدوياً
// حتى نفحص كل مرحلة على حدة بدل أن تتتالى كلها في طرفة عين.
beforeEach(function () {
    Queue::fake();
});

it('يفتح غرفة ويقفل القناة على لعبة واحدة', function () {
    ['owner' => $owner, 'channel' => $channel] = makeTable();

    $game = $this->postJson("/api/channels/{$channel->id}/games", [
        'gameType' => 'harf',
        'config' => ['roundsPerPlayer' => 1, 'writeSeconds' => 90],
    ], authHeaders($owner))->assertCreated()->json();

    expect($channel->fresh()->active_game_id)->toBe($game['game']['id']);
    expect($game['state']['config']['columns'])->toBe(['name', 'animal', 'object', 'country', 'food']);
    expect($game['state']['status'])->toBe(Game::STATUS_LOBBY);
});

it('يفرض الوضع المرن ثلاثة أعمدة و120 ثانية', function () {
    ['owner' => $owner, 'channel' => $channel] = makeTable();

    $state = $this->postJson("/api/channels/{$channel->id}/games", [
        'gameType' => 'harf',
        'config' => ['flexibleMode' => true, 'writeSeconds' => 60, 'sixthColumn' => 'color'],
    ], authHeaders($owner))->json('state');

    expect($state['config']['columns'])->toBe(['name', 'animal', 'food'])
        ->and($state['config']['writeSeconds'])->toBe(120)
        ->and($state['config']['sixthColumn'])->toBeNull();
});

it('يضيف العمود السادس المختار فقط', function () {
    ['owner' => $owner, 'channel' => $channel] = makeTable();

    $state = $this->postJson("/api/channels/{$channel->id}/games", [
        'gameType' => 'harf',
        'config' => ['sixthColumn' => 'job'],
    ], authHeaders($owner))->json('state');

    expect($state['config']['columns'])->toBe(['name', 'animal', 'object', 'country', 'food', 'job'])
        ->and($state['config']['columnLabels']['job'])->toBe('مهنة');
});

it('يرفض البدء بأقل من ثلاثة لاعبين', function () {
    ['owner' => $owner, 'channel' => $channel, 'players' => $players] = makeTable();
    $game = openRoom($this, $owner, $channel);

    joinGame($this, $players[1], $game);

    $this->postJson("/api/games/{$game}/start", [], authHeaders($owner))->assertOk();

    expect(gameState($game)['status'])->toBe(Game::STATUS_LOBBY);
});

it('يقفل القائمة ويحسب الجولات لحظة البدء', function () {
    $table = seatedGame($this);

    $state = gameState($table['game']);

    expect($state['status'])->toBe(Game::STATUS_PLAYING)
        ->and($state['totalRounds'])->toBe(3)
        ->and($state['order'])->toHaveCount(3)
        ->and($state['round']['phase'])->toBe('awaiting_letter')
        ->and($state['round']['drawerUserId'])->toBe($table['players'][0]->id);
});

it('يمنع غير صاحب الدور من سحب الحرف', function () {
    $table = seatedGame($this);

    $this->postJson("/api/games/{$table['game']}/harf/draw", [], authHeaders($table['players'][1]))
        ->assertStatus(202);

    expect(gameState($table['game'])['round']['letter'])->toBeNull();
});

it('يسحب الحرف عند انتهاء المهلة بلا عقوبة', function () {
    $table = seatedGame($this);

    advancePhase($table['game']);

    $state = gameState($table['game']);

    expect($state['round']['letter'])->toBeIn(config('harf.letters'))
        ->and($state['round']['phase'])->toBe('reveal_letter');
});

it('لا يكرّر الحرف في نفس الجلسة', function () {
    $table = seatedGame($this);

    playRound($this, $table);
    $first = gameState($table['game'])['usedLetters'];

    // الجولة الثانية تبدأ بعد انتهاء اللوحة.
    advancePhase($table['game']);
    drawAndWrite($this, $table);

    $used = gameState($table['game'])['usedLetters'];

    expect($used)->toHaveCount(2)->and(array_unique($used))->toHaveCount(2)
        ->and($used[0])->toBe($first[0]);
});

it('يقفل الستوب حتى تمتلئ كل الخانات ثم يمنح عشر ثوانٍ', function () {
    $table = seatedGame($this);
    $game = $table['game'];
    $player = $table['players'][0];

    advancePhase($game); // سحب تلقائي
    advancePhase($game); // كشف الحرف ← الكتابة

    answer($this, $player, $game, 'name', 'أحمد');
    $this->postJson("/api/games/{$game}/harf/stop", [], authHeaders($player));

    expect(gameState($game)['round']['stopBy'])->toBeNull();

    fillAllColumns($this, $player, $game, 'أ');
    $this->postJson("/api/games/{$game}/harf/stop", [], authHeaders($player));

    $state = gameState($game);
    expect($state['round']['stopBy'])->toBe($player->id)
        ->and($state['round']['phase'])->toBe('grace');
});

it('يمنع تعديل المكتوب في مهلة العشر ثوانٍ ويسمح بإكمال الفارغ', function () {
    $table = seatedGame($this);
    $game = $table['game'];
    [$first, $second] = $table['players'];

    advancePhase($game);
    advancePhase($game);

    answer($this, $second, $game, 'name', 'أصلية');
    fillAllColumns($this, $first, $game, 'أ');
    $this->postJson("/api/games/{$game}/harf/stop", [], authHeaders($first));

    // مرحلة grace: خانة مكتوبة لا تتغيّر، وخانة فارغة تُقبل.
    answer($this, $second, $game, 'name', 'محاولة تعديل');
    answer($this, $second, $game, 'animal', 'أرنب');

    $answers = gameState($game)['round']['answers'][$second->id];

    expect($answers['name'])->toBe('أصلية')->and($answers['animal'])->toBe('أرنب');
});

it('يوقف الجولة فوراً حين يكمل الجميع ويعامل الأسبق كضاغط ستوب', function () {
    $table = seatedGame($this);
    $game = $table['game'];

    advancePhase($game);
    advancePhase($game);

    foreach ($table['players'] as $player) {
        fillAllColumns($this, $player, $game, 'أ');
    }

    $state = gameState($game);

    expect($state['round']['stopBy'])->toBe($table['players'][0]->id)
        ->and($state['round']['phase'])->toBe('reveal');
});

it('يحجب إجابات الآخرين قبل مرحلة العرض ويكشفها بعدها', function () {
    $table = seatedGame($this);
    $game = $table['game'];
    [$first, $second] = $table['players'];

    advancePhase($game);
    advancePhase($game);

    fillAllColumns($this, $second, $game, 'أ');

    $duringWriting = $this->getJson("/api/games/{$game}", authHeaders($first))->json('state');
    expect($duringWriting['round']['rows'])->toBe([])
        ->and($duringWriting['round']['myAnswers'])->toBe([]);

    foreach ($table['players'] as $player) {
        fillAllColumns($this, $player, $game, 'أ');
    }

    $afterReveal = $this->getJson("/api/games/{$game}", authHeaders($first))->json('state');
    expect($afterReveal['round']['rows'])->toHaveCount(3);
});

it('يسقط الإجابة حين يصوّت الأغلبية بأنها خطأ ويعاقب ضاغط الستوب', function () {
    $table = seatedGame($this);
    $game = $table['game'];
    [$target, $objector, $voter] = $table['players'];

    advancePhase($game);
    advancePhase($game);

    // الجميع يكتب إجابات مختلفة حتى تكون كل إجابة فريدة.
    fillAllColumns($this, $target, $game, 'أ');
    fillAllColumns($this, $objector, $game, 'ب');
    fillAllColumns($this, $voter, $game, 'ج');

    expect(gameState($game)['round']['stopBy'])->toBe($target->id);

    advancePhase($game); // العرض ← الاعتراض

    $this->postJson("/api/games/{$game}/harf/objection", [
        'targetUserId' => $target->id, 'column' => 'name',
    ], authHeaders($objector))->assertStatus(202);

    advancePhase($game); // فتح التصويت

    $objectionId = gameState($game)['round']['objections'][0]['id'];

    // المعترِض والمعترَض عليه لا يصوّتان: صوت واحد يحسم.
    $this->postJson("/api/games/{$game}/harf/vote", [
        'objectionId' => $objectionId, 'valid' => false,
    ], authHeaders($voter))->assertStatus(202);

    $state = gameState($game);
    $scores = $state['round']['scores'];

    expect($state['round']['objections'][0]['verdict'])->toBe('invalid')
        ->and($scores[$target->id]['perColumn']['name'])->toBe(0)
        ->and($scores[$target->id]['stopBonus'])->toBe(-10)
        ->and($scores[$objector->id]['penalties'])->toBe(0);
});

it('يعاقب الاعتراض الفاشل بخمس نقاط', function () {
    $table = seatedGame($this);
    $game = $table['game'];
    [$target, $objector, $voter] = $table['players'];

    advancePhase($game);
    advancePhase($game);

    fillAllColumns($this, $target, $game, 'أ');
    fillAllColumns($this, $objector, $game, 'ب');
    fillAllColumns($this, $voter, $game, 'ج');
    advancePhase($game);

    $this->postJson("/api/games/{$game}/harf/objection", [
        'targetUserId' => $target->id, 'column' => 'name',
    ], authHeaders($objector));

    advancePhase($game);
    $objectionId = gameState($game)['round']['objections'][0]['id'];

    $this->postJson("/api/games/{$game}/harf/vote", [
        'objectionId' => $objectionId, 'valid' => true,
    ], authHeaders($voter));

    $scores = gameState($game)['round']['scores'];

    expect($scores[$objector->id]['penalties'])->toBe(-5)
        ->and($scores[$target->id]['stopBonus'])->toBe(10);
});

it('يقبل الإجابة عند انتهاء وقت التصويت بلا أصوات', function () {
    $table = seatedGame($this);
    $game = $table['game'];
    [$target, $objector] = $table['players'];

    advancePhase($game);
    advancePhase($game);

    foreach ($table['players'] as $index => $player) {
        fillAllColumns($this, $player, $game, ['أ', 'ب', 'ج'][$index]);
    }
    advancePhase($game);

    $this->postJson("/api/games/{$game}/harf/objection", [
        'targetUserId' => $target->id, 'column' => 'name',
    ], authHeaders($objector));

    advancePhase($game); // فتح التصويت
    advancePhase($game); // انتهاء وقت التصويت بلا أصوات

    expect(gameState($game)['round']['objections'][0]['verdict'])->toBe('valid');
});

it('يمنع اللاعب من الاعتراض على إجابته أو تجاوز ثلاثة اعتراضات', function () {
    $table = seatedGame($this);
    $game = $table['game'];
    [$target, $objector] = $table['players'];

    advancePhase($game);
    advancePhase($game);
    foreach ($table['players'] as $index => $player) {
        fillAllColumns($this, $player, $game, ['أ', 'ب', 'ج'][$index]);
    }
    advancePhase($game);

    $this->postJson("/api/games/{$game}/harf/objection", [
        'targetUserId' => $objector->id, 'column' => 'name',
    ], authHeaders($objector));

    expect(gameState($game)['round']['objections'])->toBe([]);

    foreach (['name', 'animal', 'object', 'country'] as $column) {
        $this->postJson("/api/games/{$game}/harf/objection", [
            'targetUserId' => $target->id, 'column' => $column,
        ], authHeaders($objector));
    }

    expect(gameState($game)['round']['objections'])->toHaveCount(3);
});

it('يجمع النقاط عبر الجولات وينهي اللعبة بنتيجة محفوظة', function () {
    $table = seatedGame($this);
    $game = $table['game'];

    for ($round = 1; $round <= 3; $round++) {
        drawAndWrite($this, $table);
        advancePhase($game); // العرض ← الاعتراض
        advancePhase($game); // الاعتراض ← اللوحة (لا اعتراضات)

        if ($round < 3) {
            advancePhase($game); // اللوحة ← الجولة التالية
        }
    }

    advancePhase($game); // اللوحة الأخيرة ← النهاية

    $record = Game::find($game);

    expect($record->status)->toBe(Game::STATUS_FINISHED)
        ->and($record->result['winnerId'])->not->toBeNull()
        ->and($record->result['finalScores'])->toHaveCount(3)
        ->and($record->harfRounds()->count())->toBe(3);

    // الحالة الحيّة تُمسح بعد ترحيل النتيجة.
    expect(gameState($game))->toBeNull();

    // وقفل "لعبة واحدة نشطة" تحرّر.
    expect(Channel::find($record->channel_id)->active_game_id)->toBeNull();
});

it('ينهي اللعبة إذا نزل عدد اللاعبين عن ثلاثة', function () {
    $table = seatedGame($this);
    $game = $table['game'];

    $this->postJson("/api/games/{$game}/leave", [], authHeaders($table['players'][2]))->assertOk();

    $record = Game::find($game);

    expect($record->status)->toBe(Game::STATUS_FINISHED)
        ->and($record->result['reason'])->toBe('not_enough_players');
});

it('يجعل المتأخر متفرجاً لا لاعباً', function () {
    $table = seatedGame($this);
    $latecomer = makeUser('late');
    $table['channel']->members()->attach($latecomer->id, ['role' => 'member', 'joined_at' => now()]);

    $state = $this->postJson("/api/games/{$table['game']}/join", [], authHeaders($latecomer))
        ->assertOk()->json('state');

    expect($state['me']['isSpectator'])->toBeTrue()
        ->and($state['me']['isPlayer'])->toBeFalse()
        ->and(gameState($table['game'])['totalRounds'])->toBe(3);
});

it('يرفض إنهاء مبكّر قبل الجولة العاشرة', function () {
    $table = seatedGame($this);

    $this->postJson("/api/games/{$table['game']}/end-early", [], authHeaders($table['players'][0]))
        ->assertOk();

    expect(gameState($table['game'])['status'])->toBe(Game::STATUS_PLAYING);
});

it('يمنع من ليس عضواً في القناة من رؤية اللعبة', function () {
    $table = seatedGame($this);
    $outsider = makeUser('outsider');

    $this->getJson("/api/games/{$table['game']}", authHeaders($outsider))->assertStatus(403);
});

// =============================================================================
// مساعدات
// =============================================================================

function openRoom($test, User $owner, Channel $channel, array $config = []): string
{
    return $test->postJson("/api/channels/{$channel->id}/games", [
        'gameType' => 'harf',
        'config' => $config + ['roundsPerPlayer' => 1, 'writeSeconds' => 90],
    ], authHeaders($owner))->json('game.id');
}

/** طاولة جاهزة: ثلاثة لاعبين في لعبة بدأت فعلاً. */
function seatedGame($test, array $config = []): array
{
    $table = makeTable();
    $game = openRoom($test, $table['owner'], $table['channel'], $config);

    foreach (array_slice($table['players'], 1) as $player) {
        joinGame($test, $player, $game);
    }

    $test->postJson("/api/games/{$game}/start", [], authHeaders($table['owner']));

    return $table + ['game' => $game];
}

/** يحاكي انتهاء مهلة المرحلة الحالية تماماً كما يفعل مؤقّت السيرفر. */
function advancePhase(string $gameId): void
{
    $state = gameState($gameId);

    if ($state !== null) {
        app(HarfEngine::class)->handleTimeout($gameId, $state['seq']);
    }
}

function answer($test, User $user, string $gameId, string $column, string $text): void
{
    $test->postJson("/api/games/{$gameId}/harf/answer", [
        'column' => $column, 'text' => $text,
    ], authHeaders($user));
}

function fillAllColumns($test, User $user, string $gameId, string $prefix): void
{
    foreach (gameState($gameId)['config']['columns'] as $index => $column) {
        answer($test, $user, $gameId, $column, $prefix.'-'.$column.'-'.$index);
    }
}

/** جولة كاملة حتى اللوحة. */
function drawAndWrite($test, array $table): void
{
    $game = $table['game'];

    advancePhase($game); // سحب تلقائي
    advancePhase($game); // كشف ← كتابة

    foreach ($table['players'] as $index => $player) {
        fillAllColumns($test, $player, $game, ['أ', 'ب', 'ج'][$index] ?? 'د');
    }
}

function playRound($test, array $table): void
{
    drawAndWrite($test, $table);
    advancePhase($table['game']); // العرض ← الاعتراض
    advancePhase($table['game']); // الاعتراض ← اللوحة
}
