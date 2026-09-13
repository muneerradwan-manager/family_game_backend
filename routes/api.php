<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChannelController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Api\HadafController;
use App\Http\Controllers\Api\HarfController;
use App\Http\Controllers\Api\MashhadController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SpyController;
use App\Http\Controllers\Api\UploadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| مسارات الـ API
|--------------------------------------------------------------------------
|
| الأجهزة لا تصل لقاعدة البيانات إطلاقاً. كل شيء يمر من هنا أو من القناة
| الحيّة، والسيرفر يتحقق من الصلاحية قبل كل حدث.
|
| النوايا أثناء اللعب (سحب، إجابة، ستوب، اعتراض، تصويت) تصل عبر REST
| والنتيجة تُبَث للجميع معاً عبر WebSocket — لا رسائل صاعدة على السوكت.
|
*/

// عرض الصور المرفوعة: بلا مصادقة — تظهر داخل قوائم الأعضاء ولوحات النتائج
// وقد تُشارَك، واسم الملف عشوائي لا يمكن تخمينه.
Route::get('uploads/{path}', [UploadController::class, 'show'])
    ->where('path', '.*')
    ->name('uploads.show');

// إعدادات التطبيق من لوحة الإدارة (صيانة، تسجيل، نسخة، ثيمات): قبل الدخول،
// ومفتوحة حتى في وضع الصيانة — وإلا ما عرف الجهاز أن هناك صيانة.
Route::get('app/config', [PlatformController::class, 'config'])->middleware('throttle:api');

Route::prefix('auth')->group(function (): void {
    // Rate limiting على الدخول وإنشاء الحسابات: حماية أساسية بغياب تحقق SMS.
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:auth');

    Route::middleware('auth:api')->group(function (): void {
        // الخروج مسموح للموقوف: إبطال توكنه في مصلحة الجميع.
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me'])->middleware('not-banned');
    });
});

Route::middleware(['auth:api', 'not-banned', 'throttle:api'])->group(function (): void {

    // ---- الإعلانات ----
    Route::get('announcements', [PlatformController::class, 'announcements']);

    // ---- الصور ----
    Route::post('uploads', [UploadController::class, 'store']);

    // ---- البروفايل ----
    Route::patch('profile', [ProfileController::class, 'update']);
    Route::post('profile/fcm-token', [ProfileController::class, 'updateFcmToken']);
    Route::get('profile/username-check', [ProfileController::class, 'checkUsername']);
    Route::get('users/search', [ProfileController::class, 'search']);

    // ---- القنوات ----
    Route::get('channels', [ChannelController::class, 'index']);
    Route::post('channels', [ChannelController::class, 'store']);
    Route::post('channels/join', [ChannelController::class, 'join']);
    Route::get('channels/{channel}', [ChannelController::class, 'show']);
    Route::patch('channels/{channel}', [ChannelController::class, 'update']);
    Route::post('channels/{channel}/leave', [ChannelController::class, 'leave']);
    Route::post('channels/{channel}/invite-code', [ChannelController::class, 'regenerateInviteCode']);
    Route::delete('channels/{channel}/members/{userId}', [ChannelController::class, 'removeMember']);

    // سجل الألعاب — أساس "بطولة العيلة" لاحقاً.
    Route::get('channels/{channel}/games', [ChannelController::class, 'history']);
    // "افتح الغرفة"
    Route::post('channels/{channel}/games', [GameController::class, 'store']);

    // ---- الألعاب (طبقة المنصّة) ----
    // قبل games/{game} حتى لا يبتلعها الـ binding.
    Route::get('games/catalog', [GameController::class, 'catalog']);
    Route::get('games/{game}', [GameController::class, 'show']);
    Route::post('games/{game}/join', [GameController::class, 'join']);
    Route::post('games/{game}/leave', [GameController::class, 'leave']);
    Route::post('games/{game}/start', [GameController::class, 'start']);
    Route::post('games/{game}/end-early', [GameController::class, 'endEarly']);

    // ---- وحدة لعبة الحروف ----
    Route::get('harf/reference', [HarfController::class, 'reference']);

    Route::prefix('games/{game}/harf')->middleware('throttle:gameplay')->group(function (): void {
        Route::post('draw', [HarfController::class, 'drawLetter']);
        Route::post('answer', [HarfController::class, 'updateAnswer']);
        Route::post('stop', [HarfController::class, 'pressStop']);
        Route::post('objection', [HarfController::class, 'raiseObjection']);
        Route::post('vote', [HarfController::class, 'castVote']);
        Route::post('tiebreak', [HarfController::class, 'submitTiebreak']);
    });

    // ---- وحدة لعبة الهدف ----
    Route::get('hadaf/reference', [HadafController::class, 'reference']);

    Route::prefix('games/{game}/hadaf')->middleware('throttle:gameplay')->group(function (): void {
        Route::post('answer', [HadafController::class, 'answer']);
    });

    // ---- وحدة لعبة المشهد ----
    Route::get('mashhad/reference', [MashhadController::class, 'reference']);

    Route::prefix('games/{game}/mashhad')->middleware('throttle:gameplay')->group(function (): void {
        Route::post('say', [MashhadController::class, 'say']);
        Route::post('claim', [MashhadController::class, 'claim']);
        Route::post('challenge', [MashhadController::class, 'challenge']);
        Route::post('vote', [MashhadController::class, 'vote']);
        Route::post('award', [MashhadController::class, 'award']);
    });

    // ---- وحدة لعبة الجاسوس ----
    Route::get('spy/reference', [SpyController::class, 'reference']);

    Route::prefix('games/{game}/spy')->middleware('throttle:gameplay')->group(function (): void {
        Route::post('ask', [SpyController::class, 'ask']);
        Route::post('answer', [SpyController::class, 'answer']);
        Route::post('vote', [SpyController::class, 'vote']);
        Route::post('guess', [SpyController::class, 'guess']);
    });
});
