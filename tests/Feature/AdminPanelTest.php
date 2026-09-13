<?php

use App\Livewire\Admin\Admins\Index as AdminsIndex;
use App\Livewire\Admin\Content\SpyWords;
use App\Livewire\Admin\Games\Index as GamesIndex;
use App\Livewire\Admin\Games\Settings;
use App\Livewire\Admin\Platform;
use App\Livewire\Admin\Sessions\Index as SessionsIndex;
use App\Livewire\Admin\Users\Index as UsersIndex;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\Game;
use App\Models\HadafQuestion;
use App\Models\MashhadScene;
use App\Models\RefreshToken;
use App\Settings\SettingsStore;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function makeAdmin(bool $super = true): Admin
{
    static $counter = 0;
    $counter++;

    return Admin::create([
        'name' => 'مشرف '.$counter,
        'email' => "admin{$counter}@test.com",
        'password' => 'secret-password-1',
        'is_super' => $super,
    ]);
}

// =============================================================================
// الدخول والحماية
// =============================================================================

it('redirects guests to the admin login page', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
    $this->get('/admin/login')->assertOk()->assertSee('لوحة الإدارة');
});

it('logs an admin in and records it', function () {
    $admin = makeAdmin();

    $this->post('/admin/login', ['email' => strtoupper($admin->email), 'password' => 'secret-password-1'])
        ->assertRedirect('/admin');

    $this->assertAuthenticatedAs($admin, 'admin');
    expect(AdminAuditLog::where('action', 'login')->count())->toBe(1);
});

it('rejects a wrong password without revealing whether the email exists', function () {
    $admin = makeAdmin();

    $this->from('/admin/login')
        ->post('/admin/login', ['email' => $admin->email, 'password' => 'wrong'])
        ->assertRedirect('/admin/login')
        ->assertSessionHasErrors(['email' => 'الإيميل أو كلمة السر غير صحيحة.']);

    $this->assertGuest('admin');
});

it('does not let an app user token into the panel', function () {
    $user = makeUser('sneaky');

    $this->get('/admin', authHeaders($user))->assertRedirect('/admin/login');
});

it('renders every admin screen', function () {
    $admin = makeAdmin();
    ['channel' => $channel, 'owner' => $owner] = makeTable(3);

    $game = Game::create([
        'channel_id' => $channel->id,
        'game_type' => 'harf',
        'status' => Game::STATUS_FINISHED,
        'config' => [],
        'started_by' => $owner->id,
    ]);

    HadafQuestion::create([
        'category' => 'religion', 'difficulty' => 'easy', 'prompt' => 'سؤال تجريبي؟',
        'answer' => 'نعم', 'distractors' => ['لا', 'ربما', 'أبداً'], 'fingerprint' => sha1('test'),
    ]);

    MashhadScene::create([
        'key' => 'test_scene', 'category' => 'daily', 'title' => 'مشهد', 'setup' => 'قصة',
        'roles' => [['n' => 'أ', 'g' => 'ب', 'd' => 'easy']], 'events' => [],
    ]);

    $this->actingAs($admin, 'admin');

    foreach ([
        '/admin', '/admin/users', '/admin/channels', '/admin/sessions?status=all', "/admin/sessions/{$game->id}",
        '/admin/games', '/admin/games/harf/settings', '/admin/games/spy/settings',
        '/admin/games/hadaf/settings', '/admin/games/mashhad/settings',
        '/admin/content/harf', '/admin/content/spy', '/admin/content/hadaf',
        '/admin/content/scenes', '/admin/content/mashhad-extras',
        '/admin/themes', '/admin/announcements', '/admin/platform',
        '/admin/admins', '/admin/audit', '/admin/profile',
    ] as $url) {
        $this->get($url)->assertOk();
    }

    $this->get('/admin/games/unknown/settings')->assertNotFound();
});

it('keeps the admins screen for super admins only', function () {
    $this->actingAs(makeAdmin(super: false), 'admin')->get('/admin/admins')->assertForbidden();
});

// =============================================================================
// الإعدادات تسري فوراً
// =============================================================================

it('applies a game setting immediately and resets it to the default', function () {
    $admin = makeAdmin();

    Livewire::actingAs($admin, 'admin')
        ->test(Settings::class, ['type' => 'harf'])
        ->set('values.harf__points__unique', '25')
        ->call('save')
        ->assertHasNoErrors();

    expect(config('harf.points.unique'))->toBe(25)
        ->and(DB::table('settings')->where('key', 'harf.points.unique')->exists())->toBeTrue()
        ->and(AdminAuditLog::where('action', 'setting.updated')->count())->toBe(1);

    Livewire::actingAs($admin, 'admin')
        ->test(Settings::class, ['type' => 'harf'])
        ->call('resetField', 'harf.points.unique');

    expect(config('harf.points.unique'))->toBe(10)
        ->and(DB::table('settings')->where('key', 'harf.points.unique')->exists())->toBeFalse();
});

it('rejects a default that is not one of the offered options', function () {
    Livewire::actingAs(makeAdmin(), 'admin')
        ->test(Settings::class, ['type' => 'harf'])
        ->set('values.harf__defaults__write_seconds', '75')
        ->call('save')
        ->assertHasErrors('values.harf__defaults__write_seconds');

    expect(config('harf.defaults.write_seconds'))->toBe(90);
});

it('lets a long-running worker pick up settings changed elsewhere', function () {
    $store = app(SettingsStore::class);

    // عامل آخر (عملية منفصلة) يرى config الافتراضي.
    $worker = new SettingsStore(app('cache')->store(), app('config'));
    $worker->apply();

    $store->put('spy.phases.voting', 99);
    config()->set('spy.phases.voting', 40); // كأن ذاكرة العامل ما زالت قديمة

    $worker->applyIfStale();

    expect(config('spy.phases.voting'))->toBe(99);
});

it('refuses to edit settings outside the whitelist', function () {
    app(SettingsStore::class)->put('database.default', 'sqlite');
})->throws(InvalidArgumentException::class);

it('saves spy word categories and rejects too few words', function () {
    $component = Livewire::actingAs(makeAdmin(), 'admin')->test(SpyWords::class);

    $component->set('categories.0.words', "واحد\nاثنان")
        ->call('save')
        ->assertHasErrors('categories.0.words');

    $component->set('categories.0.words', implode("\n", ['أ1', 'أ2', 'أ3', 'أ4', 'أ5', 'أ6', 'أ7']))
        ->call('save')
        ->assertHasNoErrors();

    expect(config('spy.categories.animals.words'))->toHaveCount(7);
});

// =============================================================================
// الكتالوج والجلسات
// =============================================================================

it('hides a disabled game from the catalog and blocks new rooms', function () {
    ['owner' => $owner, 'channel' => $channel] = makeTable(3);

    Livewire::actingAs(makeAdmin(), 'admin')->test(GamesIndex::class)->call('toggle', 'spy');

    $types = collect($this->getJson('/api/games/catalog', authHeaders($owner))->assertOk()->json('games') ?? $this->getJson('/api/games/catalog', authHeaders($owner))->json())
        ->pluck('type')
        ->filter()
        ->all();

    expect($types)->not->toContain('spy');

    $this->postJson("/api/channels/{$channel->id}/games", ['gameType' => 'spy'], authHeaders($owner))
        ->assertStatus(422)
        ->assertJsonPath('errors.gameType.0', 'هذه اللعبة غير متاحة حالياً.');
});

it('terminates a live session and frees the channel', function () {
    ['owner' => $owner, 'channel' => $channel] = makeTable(3);

    $gameId = $this->postJson("/api/channels/{$channel->id}/games", ['gameType' => 'harf'], authHeaders($owner))
        ->assertCreated()
        ->json('gameId') ?? Game::latest()->value('id');

    Livewire::actingAs(makeAdmin(), 'admin')->test(SessionsIndex::class)->call('terminate', $gameId);

    $game = Game::find($gameId);

    expect($game->status)->toBe(Game::STATUS_ABANDONED)
        ->and($channel->fresh()->active_game_id)->toBeNull()
        ->and(gameState($gameId))->toBeNull();
});

// =============================================================================
// حماية التطبيق
// =============================================================================

it('bans a user, revokes their tokens, and blocks their requests', function () {
    $user = makeUser('troublemaker');
    $headers = authHeaders($user);

    Livewire::actingAs(makeAdmin(), 'admin')
        ->test(UsersIndex::class)
        ->call('startBan', $user->id)
        ->set('banReason', 'مخالفة')
        ->call('ban');

    expect($user->fresh()->isBanned())->toBeTrue()
        ->and(RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->count())->toBe(0);

    $this->getJson('/api/auth/me', $headers)->assertForbidden()->assertJsonPath('banned', true);
    $this->getJson('/api/channels', $headers)->assertForbidden();
});

it('closes the app during maintenance but keeps the config endpoint open', function () {
    $user = makeUser('waiting');

    Livewire::actingAs(makeAdmin(), 'admin')
        ->test(Platform::class)
        ->set('maintenanceEnabled', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->getJson('/api/channels', authHeaders($user))->assertStatus(503)->assertJsonPath('maintenance', true);
    $this->getJson('/api/app/config')->assertOk()->assertJsonPath('maintenance.enabled', true);
});

it('forces an upgrade for app versions below the minimum', function () {
    $user = makeUser('oldphone');
    app(SettingsStore::class)->put('platform.min_app_version.version', '1.2.0');

    $this->getJson('/api/channels', authHeaders($user) + ['X-App-Version' => '1.1.9+4'])
        ->assertStatus(426)
        ->assertJsonPath('upgradeRequired', true);

    $this->getJson('/api/channels', authHeaders($user) + ['X-App-Version' => '1.2.0+1'])->assertOk();
});

it('closes registration when the admin turns it off', function () {
    app(SettingsStore::class)->put('platform.registration.enabled', false);

    $this->postJson('/api/auth/register', [])->assertForbidden()->assertJsonPath('registrationClosed', true);
});

it('never lets the last super admin lose their role', function () {
    $admin = makeAdmin();

    Livewire::actingAs($admin, 'admin')
        ->test(AdminsIndex::class)
        ->call('edit', $admin->id)
        ->set('form.is_super', false)
        ->call('save')
        ->assertHasErrors('form.is_super');

    expect($admin->fresh()->is_super)->toBeTrue();
});
