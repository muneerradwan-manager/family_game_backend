<?php

use App\Games\Spy\SpyEngine;
use App\Models\Channel;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

// المؤقّتات مهام مؤجّلة؛ في الاختبار نوقفها ونحرّك المراحل يدوياً
// حتى نفحص كل مرحلة على حدة بدل أن تتتالى كلها في طرفة عين.
beforeEach(function () {
    Queue::fake();
});

// =============================================================================
// الغرفة والشروط
// =============================================================================

it('يفتح غرفة جاسوس ويقفل القناة على لعبة واحدة', function () {
    ['owner' => $owner, 'channel' => $channel] = makeTable();

    $game = $this->postJson("/api/channels/{$channel->id}/games", [
        'gameType' => 'spy',
        'config' => ['category' => 'animals', 'maxRounds' => 3],
    ], authHeaders($owner))->assertCreated()->json();

    expect($channel->fresh()->active_game_id)->toBe($game['game']['id'])
        ->and($game['state']['status'])->toBe(Game::STATUS_LOBBY)
        ->and($game['state']['config']['category'])->toBe('animals')
        ->and($game['state']['config']['maxRounds'])->toBe(3);
});

it('يردّ الشروط غير المعروفة إلى الافتراضي', function () {
    ['owner' => $owner, 'channel' => $channel] = makeTable();

    $state = $this->postJson("/api/channels/{$channel->id}/games", [
        'gameType' => 'spy',
        'config' => ['category' => 'كائنات فضائية', 'turnSeconds' => 7, 'maxRounds' => 99],
    ], authHeaders($owner))->json('state');

    expect($state['config']['category'])->toBeNull()
        ->and($state['config']['turnSeconds'])->toBe(config('spy.defaults.turn_seconds'))
        ->and($state['config']['maxRounds'])->toBe(config('spy.defaults.max_rounds'));
});

it('يعرض لعبة الجاسوس في كتالوج الألعاب', function () {
    ['owner' => $owner] = makeTable();

    $games = collect($this->getJson('/api/games/catalog', authHeaders($owner))->json('games'));
    $spy = $games->firstWhere('gameType', 'spy');

    expect($spy)->not->toBeNull()
        ->and($spy['icon'])->toBe('🕵️')
        ->and($spy['minPlayers'])->toBe(3)
        ->and(collect($spy['configSchema'])->pluck('key')->all())
        ->toBe(['category', 'turnSeconds', 'maxRounds', 'lastGuess']);
});

it('لا يسرّب بنك الكلمات في نقطة المرجع', function () {
    ['owner' => $owner] = makeTable();

    $reference = $this->getJson('/api/spy/reference', authHeaders($owner))->assertOk()->json();

    expect(json_encode($reference, JSON_UNESCAPED_UNICODE))->not->toContain('فيل')
        ->and($reference['categories'][0])->toHaveKeys(['key', 'label', 'emoji', 'wordCount']);
});

// =============================================================================
// البدء وتوزيع الأدوار
// =============================================================================

it('يرفض البدء بأقل من ثلاثة لاعبين', function () {
    ['owner' => $owner, 'channel' => $channel, 'players' => $players] = makeTable();
    $game = openSpyRoom($this, $owner, $channel);

    joinGame($this, $players[1], $game);
    $this->postJson("/api/games/{$game}/start", [], authHeaders($owner))->assertOk();

    expect(gameState($game)['status'])->toBe(Game::STATUS_LOBBY);
});

it('يختار كلمة وجاسوساً واحداً ويخلط ترتيب الأدوار لحظة البدء', function () {
    $table = seatedSpyGame($this);
    $state = gameState($table['game']);

    expect($state['status'])->toBe(Game::STATUS_PLAYING)
        ->and($state['secret']['word'])->toBeIn(config('spy.categories.animals.words'))
        ->and($state['spyUserId'])->toBeIn(collect($table['players'])->pluck('id')->all())
        ->and($state['turnOrder'])->toHaveCount(3)
        ->and(array_unique($state['turnOrder']))->toHaveCount(3)
        ->and($state['round']['phase'])->toBe('role_reveal');
});

it('يعطي الكلمة لكل اللاعبين إلا الجاسوس', function () {
    $table = seatedSpyGame($this);
    $word = gameState($table['game'])['secret']['word'];
    $spyId = gameState($table['game'])['spyUserId'];

    $sawWord = 0;
    $sawSpy = 0;

    foreach ($table['players'] as $player) {
        $me = spySnapshot($this, $player, $table['game'])['me'];

        if ($me['isSpy']) {
            $sawSpy++;
            expect($me['word'])->toBeNull();
        } else {
            $sawWord++;
            expect($me['word'])->toBe($word);
        }

        expect($me['isSpy'])->toBe($player->id === $spyId);
    }

    expect($sawSpy)->toBe(1)->and($sawWord)->toBe(2);
});

it('لا يسرّب الكلمة ولا هوية الجاسوس في أي لقطة قبل النهاية', function () {
    $table = seatedSpyGame($this);
    $state = gameState($table['game']);
    $word = $state['secret']['word'];
    $spy = collect($table['players'])->firstWhere('id', $state['spyUserId']);

    // لقطة الجاسوس نفسه: المجموعة معروفة، والكلمة ليست فيها إطلاقاً.
    $snapshot = spySnapshot($this, $spy, $table['game']);

    expect($snapshot['categoryLabel'])->toBe('حيوانات')
        ->and(json_encode($snapshot, JSON_UNESCAPED_UNICODE))->not->toContain($word);

    // ولقطة لاعب عادي لا تشير لأحد على أنه الجاسوس.
    //
    // معرّف الجاسوس نفسه موجود طبعاً — هو لاعب في القائمة كالبقية، وهذا
    // بالضبط ما يجعل اللعبة لعبة. المطلوب ألا يكون في اللقطة أي حقل يميّزه.
    $innocent = collect($table['players'])->first(fn (User $p) => $p->id !== $spy->id);

    expect(spyMarkers(spySnapshot($this, $innocent, $table['game'])))->toBe([]);

    // وحتى لقطة الجاسوس نفسه لا تقول له من هو غيره.
    expect(spyMarkers(spySnapshot($this, $spy, $table['game'])))->toBe(['me.isSpy']);
});

it('يحجب الكلمة عن المتفرّج المتأخر', function () {
    $table = seatedSpyGame($this);
    $latecomer = makeUser('late');
    $table['channel']->members()->attach($latecomer->id, ['role' => 'member', 'joined_at' => now()]);

    $state = $this->postJson("/api/games/{$table['game']}/join", [], authHeaders($latecomer))
        ->assertOk()->json('state');

    expect($state['me']['isSpectator'])->toBeTrue()
        ->and($state['me']['word'])->toBeNull()
        ->and($state['me']['isSpy'])->toBeFalse();
});

// =============================================================================
// دورة السؤال والجواب
// =============================================================================

it('يبدأ جولة الأسئلة بعد كشف الأدوار ويعطي الدور لأول الترتيب', function () {
    $table = seatedSpyGame($this);
    advanceSpy($table['game']); // كشف الأدوار ← الجولة الأولى

    $state = gameState($table['game']);

    expect($state['currentRound'])->toBe(1)
        ->and($state['round']['phase'])->toBe('asking')
        ->and($state['round']['currentAskerId'])->toBe($state['turnOrder'][0])
        ->and($state['round']['askQueue'])->toHaveCount(2);
});

it('يمنع غير صاحب الدور من طرح السؤال', function () {
    $table = seatedSpyGame($this);
    advanceSpy($table['game']);

    $state = gameState($table['game']);
    $intruder = playerNotIn($table, [$state['round']['currentAskerId']]);

    ask($this, $intruder, $table['game'], $state['turnOrder'][1], 'سؤال مدسوس؟');

    expect(gameState($table['game'])['turns'])->toBe([])
        ->and(gameState($table['game'])['round']['phase'])->toBe('asking');
});

it('يمنع اللاعب من سؤال نفسه', function () {
    $table = seatedSpyGame($this);
    advanceSpy($table['game']);

    $asker = gameState($table['game'])['round']['currentAskerId'];
    ask($this, userById($table, $asker), $table['game'], $asker, 'بسأل حالي؟');

    expect(gameState($table['game'])['turns'])->toBe([]);
});

it('ينتقل للجواب بعد السؤال ثم للدور التالي بعد الجواب', function () {
    $table = seatedSpyGame($this);
    $game = $table['game'];
    advanceSpy($game);

    $state = gameState($game);
    $asker = $state['round']['currentAskerId'];
    $target = $state['round']['askQueue'][0];

    ask($this, userById($table, $asker), $game, $target, 'بتلاقيه بحديقة حيوان؟');

    $state = gameState($game);
    expect($state['round']['phase'])->toBe('answering')
        ->and($state['turns'])->toHaveCount(1)
        ->and($state['turns'][0]['question'])->toBe('بتلاقيه بحديقة حيوان؟')
        ->and($state['turns'][0]['answer'])->toBeNull();

    answerAs($this, userById($table, $target), $game, 'أكيد، وبيجذب الناس');

    $state = gameState($game);
    expect($state['turns'][0]['answer'])->toBe('أكيد، وبيجذب الناس')
        ->and($state['round']['phase'])->toBe('asking')
        ->and($state['round']['currentAskerId'])->toBe($target === $state['turnOrder'][1] ? $state['turnOrder'][1] : $state['turnOrder'][1]);
});

it('يمنع غير المسؤول من الإجابة', function () {
    $table = seatedSpyGame($this);
    $game = $table['game'];
    advanceSpy($game);

    $state = gameState($game);
    $asker = $state['round']['currentAskerId'];
    $target = $state['round']['askQueue'][0];

    ask($this, userById($table, $asker), $game, $target, 'سؤال؟');
    answerAs($this, userById($table, $asker), $game, 'برد عن غيري');

    expect(gameState($game)['turns'][0]['answer'])->toBeNull()
        ->and(gameState($game)['round']['phase'])->toBe('answering');
});

it('يسحب الدور حين ينتهي وقت السؤال ويسجّله في السجل', function () {
    $table = seatedSpyGame($this);
    $game = $table['game'];
    advanceSpy($game);

    $skipped = gameState($game)['round']['currentAskerId'];
    advanceSpy($game); // انتهى وقت السؤال

    $state = gameState($game);

    expect($state['turns'])->toHaveCount(1)
        ->and($state['turns'][0]['skipped'])->toBeTrue()
        ->and($state['turns'][0]['askerId'])->toBe($skipped)
        ->and($state['round']['currentAskerId'])->not->toBe($skipped);
});

it('يسجّل الصمت حين ينتهي وقت الجواب ويكمل', function () {
    $table = seatedSpyGame($this);
    $game = $table['game'];
    advanceSpy($game);

    $state = gameState($game);
    ask($this, userById($table, $state['round']['currentAskerId']), $game, $state['round']['askQueue'][0], 'سؤال؟');

    advanceSpy($game); // انتهى وقت الجواب

    expect(gameState($game)['turns'][0]['unanswered'])->toBeTrue()
        ->and(gameState($game)['round']['phase'])->toBe('asking');
});

it('ينتقل للتصويت بعد أن يسأل الجميع', function () {
    $table = seatedSpyGame($this);
    $game = $table['game'];

    askEveryone($this, $table);

    $state = gameState($game);

    expect($state['round']['phase'])->toBe('voting')
        ->and($state['round']['askQueue'])->toBe([])
        ->and($state['turns'])->toHaveCount(3);
});

// =============================================================================
// التصويت والإخراج
// =============================================================================

it('يخرج صاحب أعلى الأصوات ويكشف إن كان الجاسوس', function () {
    $table = seatedSpyGame($this);
    $game = $table['game'];
    askEveryone($this, $table);

    $spyId = gameState($game)['spyUserId'];

    // كل من ليس الجاسوس يصوّت عليه: صوتان مقابل صفر.
    foreach ($table['players'] as $player) {
        if ($player->id !== $spyId) {
            vote($this, $player, $game, $spyId);
        }
    }

    // بقي صوت الجاسوس، فالجولة تنتظر مؤقّتها.
    advanceSpy($game);

    $state = gameState($game);

    expect($state['round']['phase'])->toBe('vote_result')
        ->and($state['round']['ejectedUserId'])->toBe($spyId)
        ->and($state['round']['tie'])->toBeFalse()
        ->and($state['ejections'][0]['wasSpy'])->toBeTrue()
        ->and($state['players'][$spyId]['outAtRound'])->toBe(1);
});

it('لا يخرج أحداً عند التعادل أو غياب الأصوات', function () {
    $table = seatedSpyGame($this);
    $game = $table['game'];
    askEveryone($this, $table);

    advanceSpy($game); // انتهى وقت التصويت بلا صوت واحد

    $state = gameState($game);

    expect($state['round']['tie'])->toBeTrue()
        ->and($state['round']['ejectedUserId'])->toBeNull()
        ->and($state['ejections'])->toBe([]);
});

it('يمنع التصويت على النفس', function () {
    $table = seatedSpyGame($this);
    $game = $table['game'];
    askEveryone($this, $table);

    vote($this, $table['players'][0], $game, $table['players'][0]->id);

    expect(gameState($game)['round']['votes'])->toBe([]);
});

it('يحجب من صوّت لمن عن باقي اللاعبين', function () {
    $table = seatedSpyGame($this);
    $game = $table['game'];
    askEveryone($this, $table);

    vote($this, $table['players'][0], $game, $table['players'][1]->id);

    $mine = spySnapshot($this, $table['players'][0], $game)['round'];
    $theirs = spySnapshot($this, $table['players'][2], $game)['round'];

    expect($mine['myVote'])->toBe($table['players'][1]->id)
        ->and($theirs['myVote'])->toBeNull()
        ->and($theirs['votedCount'])->toBe(1);
});

// =============================================================================
// شروط الفوز
// =============================================================================

it('يفوز اللاعبون حين ينكشف الجاسوس بلا فرصة أخيرة', function () {
    $table = seatedSpyGame($this, ['lastGuess' => false]);
    $game = $table['game'];

    $spyId = ejectSpy($this, $table);
    advanceSpy($game); // لوحة التصويت ← النهاية

    $record = Game::find($game);

    expect($record->status)->toBe(Game::STATUS_FINISHED)
        ->and($record->result['winner'])->toBe('players')
        ->and($record->result['reason'])->toBe('spy_caught')
        ->and($record->result['spyUserId'])->toBe($spyId)
        ->and($record->result['word'])->toBeIn(config('spy.categories.animals.words'));
});

it('يعطي الجاسوس المنكشف فرصة أخيرة فيها الكلمة الحقيقية', function () {
    $table = seatedSpyGame($this);
    $game = $table['game'];

    ejectSpy($this, $table);
    advanceSpy($game); // لوحة التصويت ← فرصة الجاسوس

    $state = gameState($game);

    expect($state['round']['phase'])->toBe('spy_guess')
        ->and($state['round']['guessOptions'])->toHaveCount(config('spy.limits.guess_options'))
        ->and($state['round']['guessOptions'])->toContain($state['secret']['word']);
});

it('يفوز الجاسوس إذا خمّن الكلمة رغم انكشافه', function () {
    $table = seatedSpyGame($this);
    $game = $table['game'];

    ejectSpy($this, $table);
    advanceSpy($game);

    $state = gameState($game);
    $spy = userById($table, $state['spyUserId']);

    $this->postJson("/api/games/{$game}/spy/guess", ['word' => $state['secret']['word']], authHeaders($spy))
        ->assertStatus(202);

    $result = Game::find($game)->result;

    expect($result['winner'])->toBe('spy')
        ->and($result['reason'])->toBe('spy_guessed_word')
        ->and($result['spyGuess']['correct'])->toBeTrue();
});

it('يخسر الجاسوس إذا خمّن غلط', function () {
    $table = seatedSpyGame($this);
    $game = $table['game'];

    ejectSpy($this, $table);
    advanceSpy($game);

    $state = gameState($game);
    $spy = userById($table, $state['spyUserId']);
    $wrong = collect($state['round']['guessOptions'])->first(fn ($w) => $w !== $state['secret']['word']);

    $this->postJson("/api/games/{$game}/spy/guess", ['word' => $wrong], authHeaders($spy))
        ->assertStatus(202);

    $result = Game::find($game)->result;

    expect($result['winner'])->toBe('players')
        ->and($result['spyGuess']['correct'])->toBeFalse();
});

it('يتجاهل تخمين غير الجاسوس وتخميناً خارج الخيارات', function () {
    $table = seatedSpyGame($this);
    $game = $table['game'];

    ejectSpy($this, $table);
    advanceSpy($game);

    $state = gameState($game);
    $spy = userById($table, $state['spyUserId']);
    $other = playerNotIn($table, [$state['spyUserId']]);

    $this->postJson("/api/games/{$game}/spy/guess", ['word' => $state['secret']['word']], authHeaders($other));
    $this->postJson("/api/games/{$game}/spy/guess", ['word' => 'كلمة مش بالخيارات'], authHeaders($spy));

    expect(gameState($game)['round']['phase'])->toBe('spy_guess')
        ->and(Game::find($game)->status)->toBe(Game::STATUS_PLAYING);
});

it('ينجو الجاسوس حين ينزل عدد اللاعبين لاثنين', function () {
    $table = seatedSpyGame($this);
    $game = $table['game'];

    // ثلاثة لاعبين: إخراج بريء واحد يترك اثنين — والتصويت بينهما بلا معنى.
    $spyId = gameState($game)['spyUserId'];
    $victim = playerNotIn($table, [$spyId]);

    askEveryone($this, $table);

    foreach ($table['players'] as $player) {
        if ($player->id !== $victim->id) {
            vote($this, $player, $game, $victim->id);
        }
    }

    advanceSpy($game); // إقفال التصويت
    advanceSpy($game); // لوحة التصويت ← النهاية

    $result = Game::find($game)->result;

    expect($result['winner'])->toBe('spy')
        ->and($result['reason'])->toBe('spy_survived')
        ->and($result['ejections'][0]['wasSpy'])->toBeFalse();
});

it('ينجو الجاسوس حين تخلص الجولات', function () {
    $table = seatedSpyGame($this, ['maxRounds' => 3], playerCount: 5);
    $game = $table['game'];

    // تعادل في كل جولة = لا إخراج، فتنفد الجولات والجاسوس بمكانه.
    for ($round = 1; $round <= 3; $round++) {
        askEveryone($this, $table);
        advanceSpy($game); // التصويت ينتهي بلا أصوات
        advanceSpy($game); // لوحة التصويت ← الجولة التالية أو النهاية
    }

    $result = Game::find($game)->result;

    expect($result['winner'])->toBe('spy')
        ->and($result['reason'])->toBe('rounds_exhausted')
        ->and($result['roundsPlayed'])->toBe(3);
});

it('يفوز اللاعبون إذا غادر الجاسوس', function () {
    $table = seatedSpyGame($this, playerCount: 5);
    $game = $table['game'];

    $spy = userById($table, gameState($game)['spyUserId']);
    $this->postJson("/api/games/{$game}/leave", [], authHeaders($spy))->assertOk();

    $result = Game::find($game)->result;

    expect($result['winner'])->toBe('players')
        ->and($result['reason'])->toBe('spy_left');
});

// =============================================================================
// المتانة
// =============================================================================

it('لا يتجمّد حين يغادر صاحب الدور', function () {
    $table = seatedSpyGame($this, playerCount: 5);
    $game = $table['game'];
    advanceSpy($game);

    $state = gameState($game);
    $asker = $state['round']['currentAskerId'];

    // نتفادى نهاية اللعبة بمغادرة الجاسوس: نختار صاحب دور ليس هو.
    if ($asker === $state['spyUserId']) {
        ask($this, userById($table, $asker), $game, $state['round']['askQueue'][0], 'سؤال؟');
        answerAs($this, userById($table, $state['round']['askQueue'][0]), $game, 'جواب');
        $asker = gameState($game)['round']['currentAskerId'];
    }

    $this->postJson("/api/games/{$game}/leave", [], authHeaders(userById($table, $asker)))->assertOk();

    $state = gameState($game);

    expect($state['round']['currentAskerId'])->not->toBe($asker)
        ->and($state['round']['phase'])->toBeIn(['asking', 'voting']);
});

it('يرحّل الجولة إلى الأرشيف وينظّف الحالة الحيّة ويحرّر القناة', function () {
    $table = seatedSpyGame($this, ['lastGuess' => false]);
    $game = $table['game'];

    ejectSpy($this, $table);
    advanceSpy($game);

    $record = Game::find($game);

    expect(gameState($game))->toBeNull()
        ->and(Channel::find($record->channel_id)->active_game_id)->toBeNull()
        ->and(DB::table('spy_rounds')->where('game_id', $game)->count())->toBe(1);

    $archived = DB::table('spy_rounds')->where('game_id', $game)->first();

    expect(json_decode($archived->turns, true))->toHaveCount(3)
        ->and($archived->ejected_user_id)->toBe($record->result['spyUserId']);
});

it('يحفظ نتيجة الفوز في سجل اللاعبين', function () {
    $table = seatedSpyGame($this, ['lastGuess' => false]);
    $game = $table['game'];

    $spyId = ejectSpy($this, $table);
    advanceSpy($game);

    $scores = DB::table('game_players')->where('game_id', $game)->pluck('final_score', 'user_id');

    expect((int) $scores[$spyId])->toBe(0);

    foreach ($table['players'] as $player) {
        if ($player->id !== $spyId) {
            expect((int) $scores[$player->id])->toBe(1);
        }
    }
});

it('يرفض إنهاء مبكّر قبل الجولة الثانية', function () {
    $table = seatedSpyGame($this);
    advanceSpy($table['game']); // الجولة الأولى

    $this->postJson("/api/games/{$table['game']}/end-early", [], authHeaders($table['owner']))->assertOk();

    expect(gameState($table['game'])['status'])->toBe(Game::STATUS_PLAYING);
});

it('يمنع من ليس عضواً في القناة من إرسال أي نيّة', function () {
    $table = seatedSpyGame($this);
    $outsider = makeUser('outsider');

    $this->getJson("/api/games/{$table['game']}", authHeaders($outsider))->assertStatus(403);
    $this->postJson("/api/games/{$table['game']}/spy/vote", [
        'suspectUserId' => $table['players'][0]->id,
    ], authHeaders($outsider))->assertStatus(403);
});

it('يتجاهل نوايا الجاسوس على لعبة حروف والعكس', function () {
    $table = seatedSpyGame($this);

    $this->postJson("/api/games/{$table['game']}/harf/draw", [], authHeaders($table['owner']))
        ->assertStatus(404);
});

// =============================================================================
// مساعدات
// =============================================================================

function openSpyRoom($test, User $owner, Channel $channel, array $config = []): string
{
    return $test->postJson("/api/channels/{$channel->id}/games", [
        'gameType' => 'spy',
        // مجموعة ثابتة في الاختبار: الكلمة تبقى عشوائية، لكن المجموعة معروفة
        // فنستطيع التحقّق من الخيارات والتسميات.
        'config' => $config + ['category' => 'animals', 'maxRounds' => 4],
    ], authHeaders($owner))->json('game.id');
}

/** طاولة جاهزة: لعبة جاسوس بدأت فعلاً وهي في مرحلة كشف الأدوار. */
function seatedSpyGame($test, array $config = [], int $playerCount = 3): array
{
    $table = makeTable($playerCount);
    $game = openSpyRoom($test, $table['owner'], $table['channel'], $config);

    foreach (array_slice($table['players'], 1) as $player) {
        joinGame($test, $player, $game);
    }

    $test->postJson("/api/games/{$game}/start", [], authHeaders($table['owner']));

    return $table + ['game' => $game];
}

/** يحاكي انتهاء مهلة المرحلة الحالية تماماً كما يفعل مؤقّت السيرفر. */
function advanceSpy(string $gameId): void
{
    $state = gameState($gameId);

    if ($state !== null) {
        app(SpyEngine::class)->handleTimeout($gameId, $state['seq']);
    }
}

function spySnapshot($test, User $user, string $gameId): array
{
    return $test->getJson("/api/games/{$gameId}", authHeaders($user))->json('state');
}

function ask($test, User $user, string $gameId, string $targetId, string $question): void
{
    $test->postJson("/api/games/{$gameId}/spy/ask", [
        'targetUserId' => $targetId, 'question' => $question,
    ], authHeaders($user));
}

function answerAs($test, User $user, string $gameId, string $answer): void
{
    $test->postJson("/api/games/{$gameId}/spy/answer", ['answer' => $answer], authHeaders($user));
}

function vote($test, User $user, string $gameId, string $suspectId): void
{
    $test->postJson("/api/games/{$gameId}/spy/vote", ['suspectUserId' => $suspectId], authHeaders($user));
}

/**
 * كل حقل في اللقطة يشي بهوية الجاسوس ويحمل قيمة.
 *
 * تفتيش بالمفتاح لا بالقيمة: قيمة معرّف الجاسوس موجودة حتماً (هو لاعب في
 * القائمة)، والتسريب هو أن يقول حقلٌ ما "هذا هو".
 *
 * @param  array<string, mixed>  $data
 * @return array<int, string> مسارات الحقول المسرِّبة — فارغة تعني لا تسريب
 */
function spyMarkers(array $data, string $path = ''): array
{
    $found = [];

    foreach ($data as $key => $value) {
        $here = $path === '' ? (string) $key : $path.'.'.$key;

        if (is_array($value)) {
            $found = array_merge($found, spyMarkers($value, $here));

            continue;
        }

        if (preg_match('/spy/i', (string) $key) && $value !== null && $value !== false) {
            $found[] = $here;
        }
    }

    return $found;
}

function userById(array $table, string $userId): User
{
    return collect($table['players'])->firstWhere('id', $userId);
}

/** @param array<int, string> $excluded */
function playerNotIn(array $table, array $excluded): User
{
    return collect($table['players'])->first(fn (User $p) => ! in_array($p->id, $excluded, true));
}

/** جولة أسئلة كاملة: كل لاعب يسأل غيره ويتلقّى جواباً، حتى يفتح التصويت. */
function askEveryone($test, array $table): void
{
    $game = $table['game'];

    if (gameState($game)['round']['phase'] === 'role_reveal') {
        advanceSpy($game);
    }

    while (gameState($game)['round']['phase'] !== 'voting') {
        $round = gameState($game)['round'];

        if ($round['phase'] === 'asking') {
            $asker = $round['currentAskerId'];
            $target = playerNotIn($table, [$asker])->id;

            ask($test, userById($table, $asker), $game, $target, 'سؤالك يا بطل؟');

            continue;
        }

        $turn = collect(gameState($game)['turns'])->last();
        answerAs($test, userById($table, $turn['targetId']), $game, 'جوابي محسوب');
    }
}

/** يوصل اللعبة إلى لحظة إخراج الجاسوس بالتصويت، ويعيد معرّفه. */
function ejectSpy($test, array $table): string
{
    $game = $table['game'];
    askEveryone($test, $table);

    $spyId = gameState($game)['spyUserId'];

    foreach ($table['players'] as $player) {
        if ($player->id !== $spyId) {
            vote($test, $player, $game, $spyId);
        }
    }

    // صوت الجاسوس ناقص: المؤقّت هو من يقفل الصندوق.
    advanceSpy($game);

    return $spyId;
}
