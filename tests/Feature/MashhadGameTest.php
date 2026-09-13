<?php

use App\Games\Mashhad\MashhadEngine;
use App\Games\Mashhad\Scenes\SceneLibrary;
use App\Models\Channel;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// المؤقّتات مهام مؤجّلة؛ في الاختبار نوقفها ونحرّك المراحل يدوياً.
// وبنك المشاهد لازم يكون موجوداً قبل أي جلسة.
beforeEach(function () {
    Queue::fake();
    $this->artisan('mashhad:import');
});

// =============================================================================
// الغرفة والشروط
// =============================================================================

it('يفتح غرفة مشهد ويقفل القناة على لعبة واحدة', function () {
    ['owner' => $owner, 'channel' => $channel] = makeTable();

    $game = $this->postJson("/api/channels/{$channel->id}/games", [
        'gameType' => 'mashhad',
        'config' => ['category' => 'daily', 'scenes' => 1, 'sceneSeconds' => 120],
    ], authHeaders($owner))->assertCreated()->json();

    expect($channel->fresh()->active_game_id)->toBe($game['game']['id'])
        ->and($game['state']['config']['category'])->toBe('daily')
        ->and($game['state']['config']['scenes'])->toBe(1)
        ->and($game['state']['config']['sceneSeconds'])->toBe(120);
});

it('يفرض الوضع العائلي وقتاً أطول', function () {
    ['owner' => $owner, 'channel' => $channel] = makeTable();

    $state = $this->postJson("/api/channels/{$channel->id}/games", [
        'gameType' => 'mashhad',
        'config' => ['familyMode' => true, 'sceneSeconds' => 120],
    ], authHeaders($owner))->json('state');

    expect($state['config']['sceneSeconds'])->toBe(config('mashhad.family.scene_seconds'))
        ->and($state['config']['familyMode'])->toBeTrue();
});

it('يعرض لعبة المشهد في كتالوج الألعاب', function () {
    ['owner' => $owner] = makeTable();

    $games = collect($this->getJson('/api/games/catalog', authHeaders($owner))->json('games'));
    $mashhad = $games->firstWhere('gameType', 'mashhad');

    expect($mashhad)->not->toBeNull()
        ->and($mashhad['icon'])->toBe('🎬')
        ->and($mashhad['maxPlayers'])->toBe(12)
        ->and(collect($mashhad['configSchema'])->pluck('key')->all())
        ->toBe(['category', 'scenes', 'sceneSeconds', 'familyMode']);
});

it('لا يسرّب المشاهد ولا الأدوار في نقطة المرجع', function () {
    ['owner' => $owner] = makeTable();

    $reference = $this->getJson('/api/mashhad/reference', authHeaders($owner))->assertOk()->json();

    expect(json_encode($reference, JSON_UNESCAPED_UNICODE))->not->toContain('انقطعت الكهرباء')
        ->and($reference['categories'][0])->toHaveKeys(['key', 'label', 'emoji', 'sceneCount']);
});

// =============================================================================
// توزيع الأدوار وسرّيتها
// =============================================================================

it('يوزّع دوراً وهدفاً لكل لاعب لحظة البدء', function () {
    $table = seatedMashhadGame($this);
    $state = gameState($table['game']);

    expect($state['round']['phase'])->toBe('role_reveal')
        ->and($state['round']['cast'])->toHaveCount(3);

    foreach ($table['players'] as $player) {
        $role = $state['round']['cast'][$player->id];

        expect($role['name'])->not->toBeEmpty()
            ->and($role['goal'])->not->toBeEmpty();
    }

    // الأدوار متمايزة: لا يأخذ لاعبان الشخصية نفسها.
    $names = array_column($state['round']['cast'], 'name');
    expect(array_unique($names))->toHaveCount(3);
});

it('لا يسرّب هدف لاعب لغيره قبل الكشف', function () {
    $table = seatedMashhadGame($this);
    $game = $table['game'];
    $state = gameState($game);

    [$me, $other] = $table['players'];
    $theirGoal = $state['round']['cast'][$other->id]['goal'];

    $snapshot = mashhadSnapshot($this, $me, $game);

    expect($snapshot['round']['myRole']['goal'])
        ->toBe($state['round']['cast'][$me->id]['goal'])
        ->and(json_encode($snapshot, JSON_UNESCAPED_UNICODE))->not->toContain($theirGoal)
        ->and($snapshot['round']['rows'])->toBe([]);
});

it('يكشف كل الأهداف والادّعاءات معاً في مرحلة الاعتراض', function () {
    $table = seatedMashhadGame($this);
    $game = $table['game'];

    reachClaims($this, $game);
    advanceMashhad($game); // الادّعاءات ← الكشف والاعتراض

    $rows = mashhadSnapshot($this, $table['players'][0], $game)['round']['rows'];

    expect($rows)->toHaveCount(3)
        ->and($rows[0])->toHaveKeys(['roleName', 'goal', 'claimedMain', 'messageCount']);
});

it('يحجب الدور عن المتفرّج المتأخر', function () {
    $table = seatedMashhadGame($this);
    $latecomer = makeUser('late-mashhad');
    $table['channel']->members()->attach($latecomer->id, ['role' => 'member', 'joined_at' => now()]);

    $state = $this->postJson("/api/games/{$table['game']}/join", [], authHeaders($latecomer))
        ->assertOk()->json('state');

    expect($state['me']['isSpectator'])->toBeTrue()
        ->and($state['round']['myRole'])->toBeNull();
});

// =============================================================================
// المشهد والشات
// =============================================================================

it('يفتح الشات بعد كشف الأدوار ويسجّل الرسائل', function () {
    $table = seatedMashhadGame($this);
    $game = $table['game'];

    advanceMashhad($game); // كشف الأدوار ← المشهد

    expect(gameState($game)['round']['phase'])->toBe('scene');

    say($this, $table['players'][0], $game, 'أنا متأكد المشكلة من القاطع');
    say($this, $table['players'][1], $game, 'لا، شفت الكهربا شغالة قبل شوي');

    $transcript = gameState($game)['round']['transcript'];

    expect($transcript)->toHaveCount(2)
        ->and($transcript[0]['text'])->toBe('أنا متأكد المشكلة من القاطع')
        ->and($transcript[0]['userId'])->toBe($table['players'][0]->id)
        ->and($transcript[0]['role'])->not->toBeNull();
});

it('يمنع الكتابة خارج المشهد ويمنع المتفرّج منها', function () {
    $table = seatedMashhadGame($this);
    $game = $table['game'];

    // ما زلنا في كشف الأدوار.
    say($this, $table['players'][0], $game, 'رسالة مبكرة');
    expect(gameState($game)['round']['transcript'])->toBe([]);

    advanceMashhad($game);

    $spectator = makeUser('watcher-mashhad');
    $table['channel']->members()->attach($spectator->id, ['role' => 'member', 'joined_at' => now()]);
    $this->postJson("/api/games/{$game}/join", [], authHeaders($spectator));

    say($this, $spectator, $game, 'أنا متفرّج بس بدي احكي');

    expect(gameState($game)['round']['transcript'])->toBe([]);
});

it('يجدول أحداثاً مفاجئة داخل المشهد ويطلقها بوقتها', function () {
    $table = seatedMashhadGame($this);
    $game = $table['game'];

    advanceMashhad($game); // ← المشهد

    $events = gameState($game)['round']['events'];

    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        expect($event['fired'])->toBeFalse()
            ->and($event['at'])->toBeGreaterThan(0)
            ->and($event['scope'])->toBeIn(['all', 'one']);

        if ($event['scope'] === 'one') {
            expect($event['userId'])->toBeIn(collect($table['players'])->pluck('id')->all());
        }
    }

    // ضربة المؤقّت التالية تطلق ما حان وقته.
    advanceMashhad($game);

    expect(collect(gameState($game)['round']['events'])->where('fired', true))->not->toBeEmpty();
});

it('يوصل الحدث الخاص لصاحبه وحده', function () {
    $table = seatedMashhadGame($this);
    $game = $table['game'];

    advanceMashhad($game);
    fireAllEvents($game);

    $state = gameState($game);
    $private = collect($state['round']['events'])->firstWhere('scope', 'one');

    if ($private === null) {
        // المشهد المسحوب بلا حدث خاص — لا شيء نفحصه.
        expect(true)->toBeTrue();

        return;
    }

    $owner = collect($table['players'])->firstWhere('id', $private['userId']);
    $stranger = collect($table['players'])->first(fn (User $p) => $p->id !== $private['userId']);

    $mine = mashhadSnapshot($this, $owner, $game)['round']['myEvents'];
    $theirs = mashhadSnapshot($this, $stranger, $game)['round']['myEvents'];

    expect(collect($mine)->pluck('text'))->toContain($private['text'])
        ->and(collect($theirs)->pluck('text'))->not->toContain($private['text']);
});

// =============================================================================
// الادّعاء والاعتراض والحكم
// =============================================================================

it('يسجّل الادّعاء ويقفل المرحلة حين يدّعي الجميع', function () {
    $table = seatedMashhadGame($this);
    $game = $table['game'];

    reachClaims($this, $game);

    foreach ($table['players'] as $player) {
        claim($this, $player, $game, main: true);
    }

    expect(gameState($game)['round']['phase'])->toBe('challenge');
});

it('يمنع ادّعاء هدف إضافي غير موجود', function () {
    $table = seatedMashhadGame($this);
    $game = $table['game'];

    reachClaims($this, $game);

    $filler = collect($table['players'])->first(
        fn (User $p) => (gameState($game)['round']['cast'][$p->id]['bonus'] ?? null) === null,
    );

    if ($filler === null) {
        expect(true)->toBeTrue();

        return;
    }

    claim($this, $filler, $game, main: true, bonus: true);

    expect(gameState($game)['round']['claims'][$filler->id]['bonus'])->toBeFalse();
});

it('يُسقط الادّعاء حين تصوّت الأغلبية بأنه لم يتحقق', function () {
    $table = seatedMashhadGame($this);
    $game = $table['game'];
    [$target, $challenger, $judge] = $table['players'];

    reachClaims($this, $game);

    foreach ($table['players'] as $player) {
        claim($this, $player, $game, main: true);
    }

    challenge($this, $challenger, $game, $target->id, 'main');
    advanceMashhad($game); // الاعتراض ← التصويت

    $challengeId = gameState($game)['round']['challenges'][0]['id'];

    // المعترِض والمعترَض عليه لا يصوّتان: صوت الحكم يحسم.
    voteVerdict($this, $judge, $game, $challengeId, achieved: false);

    $state = gameState($game);
    $points = config('mashhad.points');

    expect($state['round']['challenges'][0]['verdict'])->toBe('rejected')
        ->and($state['round']['scores'][$target->id]['main'])->toBe(0)
        ->and($state['round']['scores'][$target->id]['penalties'])->toBe($points['false_claim'])
        ->and($state['round']['scores'][$challenger->id]['main'])->toBe($points['main_goal']);
});

it('يعاقب الاعتراض الفاشل ويمرّر الادّعاء', function () {
    $table = seatedMashhadGame($this);
    $game = $table['game'];
    [$target, $challenger, $judge] = $table['players'];

    reachClaims($this, $game);

    foreach ($table['players'] as $player) {
        claim($this, $player, $game, main: true);
    }

    challenge($this, $challenger, $game, $target->id, 'main');
    advanceMashhad($game);

    $challengeId = gameState($game)['round']['challenges'][0]['id'];
    voteVerdict($this, $judge, $game, $challengeId, achieved: true);

    $scores = gameState($game)['round']['scores'];

    expect(gameState($game)['round']['challenges'][0]['verdict'])->toBe('upheld')
        ->and($scores[$target->id]['main'])->toBe(config('mashhad.points.main_goal'))
        ->and($scores[$challenger->id]['penalties'])->toBe(config('mashhad.points.failed_challenge'));
});

it('يمرّر الادّعاء عند انتهاء وقت التصويت بلا أصوات', function () {
    $table = seatedMashhadGame($this);
    $game = $table['game'];
    [$target, $challenger] = $table['players'];

    reachClaims($this, $game);

    foreach ($table['players'] as $player) {
        claim($this, $player, $game, main: true);
    }

    challenge($this, $challenger, $game, $target->id, 'main');
    advanceMashhad($game); // ← التصويت
    advanceMashhad($game); // انتهى الوقت بلا صوت

    expect(gameState($game)['round']['challenges'][0]['verdict'])->toBe('upheld');
});

it('يمنع الاعتراض على النفس وعلى ادّعاء لم يُقدَّم', function () {
    $table = seatedMashhadGame($this);
    $game = $table['game'];
    [$first, $second] = $table['players'];

    reachClaims($this, $game);

    claim($this, $first, $game, main: true);
    claim($this, $second, $game, main: false);
    claim($this, $table['players'][2], $game, main: true);

    challenge($this, $first, $game, $first->id, 'main');
    expect(gameState($game)['round']['challenges'])->toBe([]);

    // الثاني ما ادّعى شيئاً — فلا محلّ للاعتراض.
    challenge($this, $first, $game, $second->id, 'main');
    expect(gameState($game)['round']['challenges'])->toBe([]);
});

it('يقفل زر الاعتراض بعد استنفاد الرصيد', function () {
    $table = seatedMashhadGame($this, playerCount: 5);
    $game = $table['game'];
    $challenger = $table['players'][0];
    $limit = (int) config('mashhad.limits.challenges_per_player');

    reachClaims($this, $game);

    foreach ($table['players'] as $player) {
        claim($this, $player, $game, main: true);
    }

    foreach (array_slice($table['players'], 1) as $target) {
        challenge($this, $challenger, $game, $target->id, 'main');
    }

    expect(gameState($game)['round']['challenges'])->toHaveCount($limit);
});

// =============================================================================
// النقاط والنهاية
// =============================================================================

it('يمنح نقاط الهدف الرئيسي والإضافي والدور الصعب', function () {
    $table = seatedMashhadGame($this);
    $game = $table['game'];
    $points = config('mashhad.points');

    reachClaims($this, $game);

    $cast = gameState($game)['round']['cast'];
    $hard = collect($table['players'])->first(
        fn (User $p) => $cast[$p->id]['difficulty'] === 'hard',
    );

    foreach ($table['players'] as $player) {
        claim(
            $this,
            $player,
            $game,
            main: true,
            bonus: $cast[$player->id]['bonus'] !== null,
        );
    }

    advanceMashhad($game); // الاعتراض (بلا اعتراضات) ← اللوحة

    $scores = gameState($game)['round']['scores'];

    foreach ($table['players'] as $player) {
        $row = $scores[$player->id];
        $expected = $points['main_goal']
            + ($cast[$player->id]['bonus'] !== null ? $points['bonus_goal'] : 0)
            + ($cast[$player->id]['difficulty'] === 'hard' ? $points['hard_role'] : 0);

        expect($row['total'])->toBe($expected);
    }

    if ($hard !== null) {
        expect($scores[$hard->id]['hard'])->toBe($points['hard_role']);
    }
});

it('ينهي المباراة بجوائز ونتيجة محفوظة', function () {
    $table = seatedMashhadGame($this, ['scenes' => 1]);
    $game = $table['game'];

    playScene($this, $table);
    advanceMashhad($game); // اللوحة ← الجوائز

    expect(gameState($game)['round']['phase'])->toBe('awards');

    // كل واحد يصوّت لزميله في كل الجوائز.
    foreach (config('mashhad.awards') as $award) {
        foreach ($table['players'] as $index => $player) {
            $target = $table['players'][($index + 1) % count($table['players'])];
            awardVote($this, $player, $game, $award['key'], $target->id);
        }
    }

    $record = Game::find($game);

    expect($record->status)->toBe(Game::STATUS_FINISHED)
        ->and($record->result['winnerId'])->not->toBeNull()
        ->and($record->result['awards'])->toHaveCount(count(config('mashhad.awards')))
        ->and($record->result['scenesPlayed'])->toBe(1)
        ->and(DB::table('mashhad_rounds')->where('game_id', $game)->count())->toBe(1);

    expect(gameState($game))->toBeNull()
        ->and(Channel::find($record->channel_id)->active_game_id)->toBeNull();
});

it('يرحّل المشهد إلى الأرشيف بنصّه وأدواره', function () {
    $table = seatedMashhadGame($this, ['scenes' => 1]);
    $game = $table['game'];

    advanceMashhad($game); // ← المشهد
    say($this, $table['players'][0], $game, 'شو صار؟');
    fireAllEvents($game);
    advanceMashhad($game); // ← الادّعاءات

    foreach ($table['players'] as $player) {
        claim($this, $player, $game, main: true);
    }

    advanceMashhad($game); // ← اللوحة

    $archived = DB::table('mashhad_rounds')->where('game_id', $game)->first();

    expect($archived)->not->toBeNull()
        ->and(json_decode($archived->transcript, true))->toHaveCount(1)
        ->and(json_decode($archived->cast, true))->toHaveCount(3)
        ->and($archived->title)->not->toBeEmpty();
});

it('يلعب أكثر من مشهد بأدوار جديدة في كل مرة', function () {
    $table = seatedMashhadGame($this, ['scenes' => 2]);
    $game = $table['game'];

    playScene($this, $table);
    $first = gameState($game)['round']['scene']['key'];

    advanceMashhad($game); // اللوحة ← المشهد الثاني

    $state = gameState($game);

    expect($state['currentScene'])->toBe(2)
        ->and($state['round']['phase'])->toBe('role_reveal')
        ->and($state['round']['scene']['key'])->not->toBe($first)
        ->and($state['round']['transcript'])->toBe([]);
});

it('ينهي اللعبة إذا نزل عدد اللاعبين عن ثلاثة', function () {
    $table = seatedMashhadGame($this);

    $this->postJson("/api/games/{$table['game']}/leave", [], authHeaders($table['players'][2]))
        ->assertOk();

    expect(Game::find($table['game'])->result['reason'])->toBe('not_enough_players');
});

it('يمنع من ليس عضواً في القناة من الكتابة', function () {
    $table = seatedMashhadGame($this);
    $outsider = makeUser('outsider-mashhad');

    $this->postJson("/api/games/{$table['game']}/mashhad/say", [
        'text' => 'مرحبا',
    ], authHeaders($outsider))->assertStatus(403);
});

it('لا يخلط نوايا الألعاب ببعضها', function () {
    $table = seatedMashhadGame($this);

    $this->postJson("/api/games/{$table['game']}/hadaf/answer", [
        'choice' => 0,
    ], authHeaders($table['owner']))->assertStatus(404);
});

// =============================================================================
// مكتبة المشاهد
// =============================================================================

it('يوزّع أدوار الحشو حين يزيد اللاعبون عن أدوار المشهد', function () {
    $library = app(SceneLibrary::class);
    $scene = $library->draw('daily');

    $players = [];
    for ($i = 0; $i < 10; $i++) {
        $players[] = "player-{$i}";
    }

    $cast = $library->assign($scene, $players);

    expect($cast)->toHaveCount(10);

    $filler = collect($cast)->where('filler', true);

    expect($filler->count())->toBe(10 - count($scene['roles']));

    // كل دور له هدف مهما كان مصدره.
    foreach ($cast as $role) {
        expect($role['goal'])->not->toBeEmpty();
    }
});

it('يبسّط الأدوار في الوضع العائلي', function () {
    $library = app(SceneLibrary::class);
    $scene = $library->draw('daily');

    $cast = $library->assign($scene, ['a', 'b', 'c'], simplified: true);

    foreach ($cast as $role) {
        expect($role['bonus'])->toBeNull()
            ->and($role['secret'])->toBeNull();
    }
});

it('لا يعيد مشهداً لُعب في الجلسة نفسها', function () {
    $library = app(SceneLibrary::class);

    $seen = [];

    for ($i = 0; $i < 10; $i++) {
        $scene = $library->draw(null, $seen);
        $seen[] = $scene['key'];
    }

    expect(array_unique($seen))->toHaveCount(10);
});

it('يستورد المشاهد بلا تكرار مهما أُعيد', function () {
    $before = DB::table('mashhad_scenes')->count();

    $this->artisan('mashhad:import')->assertSuccessful();

    expect($before)->toBe(15)
        ->and(DB::table('mashhad_scenes')->count())->toBe($before);
});

// =============================================================================
// مساعدات
// =============================================================================

function openMashhadRoom($test, User $owner, Channel $channel, array $config = []): string
{
    return $test->postJson("/api/channels/{$channel->id}/games", [
        'gameType' => 'mashhad',
        'config' => $config + ['scenes' => 1, 'sceneSeconds' => 120],
    ], authHeaders($owner))->json('game.id');
}

/** طاولة جاهزة: لعبة مشهد بدأت وهي في مرحلة كشف الأدوار. */
function seatedMashhadGame($test, array $config = [], int $playerCount = 3): array
{
    $table = makeTable($playerCount);
    $game = openMashhadRoom($test, $table['owner'], $table['channel'], $config);

    foreach (array_slice($table['players'], 1) as $player) {
        joinGame($test, $player, $game);
    }

    $test->postJson("/api/games/{$game}/start", [], authHeaders($table['owner']));

    return $table + ['game' => $game];
}

/** يحاكي انتهاء مهلة المرحلة الحالية تماماً كما يفعل مؤقّت السيرفر. */
function advanceMashhad(string $gameId): void
{
    $state = gameState($gameId);

    if ($state !== null) {
        app(MashhadEngine::class)->handleTimeout($gameId, $state['seq']);
    }
}

/**
 * داخل المشهد يضرب المؤقّت مرة لكل حدث ثم مرة للنهاية؛ ندفعه حتى تُطلق
 * الأحداث كلها ونبقى في المشهد.
 */
function fireAllEvents(string $gameId): void
{
    for ($i = 0; $i < 6; $i++) {
        $state = gameState($gameId);

        if ($state === null || $state['round']['phase'] !== 'scene') {
            return;
        }

        if (collect($state['round']['events'])->where('fired', false)->isEmpty()) {
            return;
        }

        advanceMashhad($gameId);
    }
}

/** يوصل اللعبة إلى مرحلة الادّعاءات. */
function reachClaims($test, string $gameId): void
{
    advanceMashhad($gameId); // كشف الأدوار ← المشهد

    for ($i = 0; $i < 8; $i++) {
        $state = gameState($gameId);

        if ($state === null || $state['round']['phase'] === 'claims') {
            return;
        }

        advanceMashhad($gameId);
    }
}

/** مشهد كامل حتى لوحة النقاط. */
function playScene($test, array $table): void
{
    $game = $table['game'];

    reachClaims($test, $game);

    foreach ($table['players'] as $player) {
        claim($test, $player, $game, main: true);
    }

    advanceMashhad($game); // الاعتراض ← اللوحة
}

function mashhadSnapshot($test, User $user, string $gameId): array
{
    return $test->getJson("/api/games/{$gameId}", authHeaders($user))->json('state');
}

function say($test, User $user, string $gameId, string $text): void
{
    $test->postJson("/api/games/{$gameId}/mashhad/say", ['text' => $text], authHeaders($user));
}

function claim(
    $test,
    User $user,
    string $gameId,
    bool $main,
    bool $bonus = false,
    bool $event = false,
): void {
    $test->postJson("/api/games/{$gameId}/mashhad/claim", [
        'main' => $main, 'bonus' => $bonus, 'event' => $event,
    ], authHeaders($user));
}

function challenge($test, User $user, string $gameId, string $targetId, string $kind): void
{
    $test->postJson("/api/games/{$gameId}/mashhad/challenge", [
        'targetUserId' => $targetId, 'kind' => $kind,
    ], authHeaders($user));
}

function voteVerdict($test, User $user, string $gameId, string $challengeId, bool $achieved): void
{
    $test->postJson("/api/games/{$gameId}/mashhad/vote", [
        'challengeId' => $challengeId, 'achieved' => $achieved,
    ], authHeaders($user));
}

function awardVote($test, User $user, string $gameId, string $awardKey, string $targetId): void
{
    $test->postJson("/api/games/{$gameId}/mashhad/award", [
        'awardKey' => $awardKey, 'targetUserId' => $targetId,
    ], authHeaders($user));
}
