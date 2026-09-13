<?php

use App\Http\Controllers\Admin\AuthController;
use App\Livewire\Admin\Admins;
use App\Livewire\Admin\Announcements;
use App\Livewire\Admin\Audit;
use App\Livewire\Admin\Channels;
use App\Livewire\Admin\Content;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Games;
use App\Livewire\Admin\Platform;
use App\Livewire\Admin\Profile;
use App\Livewire\Admin\Sessions;
use App\Livewire\Admin\Themes;
use App\Livewire\Admin\Users;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| لوحة الإدارة — /admin
|--------------------------------------------------------------------------
|
| مسجّلة من bootstrap/app.php بمجموعة web وبادئة admin واسم admin.
| كل الشاشات خلف حارس admin المنفصل عن مستخدمي التطبيق.
|
*/

Route::get('login', [AuthController::class, 'showLogin'])->name('login');
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:20,1')->name('login.attempt');
Route::post('logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware('auth:admin')->group(function () {
    Route::livewire('/', Dashboard::class)->name('dashboard');

    Route::livewire('users', Users\Index::class)->name('users.index');
    Route::livewire('channels', Channels\Index::class)->name('channels.index');
    Route::livewire('sessions', Sessions\Index::class)->name('sessions.index');
    Route::livewire('sessions/{game}', Sessions\Show::class)->name('sessions.show');

    Route::livewire('games', Games\Index::class)->name('games.index');
    Route::livewire('games/{type}/settings', Games\Settings::class)->name('games.settings');

    Route::livewire('content/harf', Content\HarfContent::class)->name('content.harf');
    Route::livewire('content/spy', Content\SpyWords::class)->name('content.spy');
    Route::livewire('content/hadaf', Content\HadafQuestions::class)->name('content.hadaf');
    Route::livewire('content/scenes', Content\MashhadScenes::class)->name('content.scenes');
    Route::livewire('content/mashhad-extras', Content\MashhadExtras::class)->name('content.mashhad-extras');

    Route::livewire('themes', Themes\Index::class)->name('themes.index');
    Route::livewire('announcements', Announcements\Index::class)->name('announcements.index');
    Route::livewire('platform', Platform::class)->name('platform');

    Route::livewire('admins', Admins\Index::class)->name('admins.index');
    Route::livewire('audit', Audit\Index::class)->name('audit.index');
    Route::livewire('profile', Profile::class)->name('profile');
});
