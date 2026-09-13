<?php

use App\Auth\JwtService;
use App\Games\State\GameStateStore;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// اختبارات الوحدة تحتاج الحاوية لقراءة config('harf.*') لكنها لا تلمس القاعدة.
pest()->extend(TestCase::class)->in('Unit');

/**
 * مستخدم جاهز بالحقول الإلزامية كلها — يختصر تكرارها في كل اختبار.
 */
function makeUser(string $username, array $overrides = []): User
{
    static $counter = 0;
    $counter++;

    return User::create(array_merge([
        'phone' => '+9627'.str_pad((string) $counter, 8, '0', STR_PAD_LEFT),
        'password_hash' => 'secret123',
        'full_name' => 'مستخدم '.$username,
        'username' => $username,
        'gender' => User::GENDER_MALE,
    ], $overrides));
}

/**
 * ترويسة الطلب الموثّق لهذا المستخدم.
 *
 * @return array<string, string>
 */
function authHeaders(User $user): array
{
    $tokens = app(JwtService::class)->issueTokenPair($user);

    return ['Authorization' => 'Bearer '.$tokens['access_token']];
}

// =============================================================================
// مساعدات مشتركة بين كل وحدات الألعاب
// =============================================================================

/**
 * قناة جاهزة بأعضائها — نقطة البداية لأي لعبة.
 *
 * @return array{owner: User, players: array<int, User>, channel: Channel}
 */
function makeTable(int $count = 3): array
{
    $players = [];

    for ($i = 0; $i < $count; $i++) {
        $players[] = makeUser('player'.$i);
    }

    $channel = Channel::create([
        'name' => 'العيلة',
        'owner_id' => $players[0]->id,
        'invite_code' => Channel::generateInviteCode(),
    ]);

    foreach ($players as $index => $player) {
        $channel->members()->attach($player->id, [
            'role' => $index === 0 ? 'owner' : 'member',
            'joined_at' => now()->addSeconds($index),
        ]);
    }

    return ['owner' => $players[0], 'players' => $players, 'channel' => $channel];
}

function joinGame($test, User $user, string $gameId): void
{
    $test->postJson("/api/games/{$gameId}/join", [], authHeaders($user));
}

/**
 * الحالة الحيّة كما يراها السيرفر — بما فيها ما لا يُبَث لأحد.
 *
 * @return array<string, mixed>|null
 */
function gameState(string $gameId): ?array
{
    return app(GameStateStore::class)->get($gameId);
}
