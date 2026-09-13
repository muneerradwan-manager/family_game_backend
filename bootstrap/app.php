<?php

use App\Http\Middleware\EnsurePlatformAvailable;
use App\Http\Middleware\EnsureUserNotBanned;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // لوحة الإدارة: جلسة متصفح وحماية CSRF — مجموعة web لا api.
        then: function (): void {
            Route::middleware('web')
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));
        },
    )
    // مصافحة الـ WebSocket تُوثَّق بنفس توكن الـ JWT المستخدم في REST،
    // فلا يشترك في غرفة إلا من يحق له فعلاً — ولا الحساب الموقوف.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['auth:api', 'not-banned']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['not-banned' => EnsureUserNotBanned::class]);

        // وضع الصيانة وأدنى نسخة: على كل مسارات التطبيق.
        $middleware->api(append: [EnsurePlatformAvailable::class]);

        // ضيف لوحة الإدارة يُحوَّل لصفحة دخولها. مسارات التطبيق لا تُحوَّل
        // لشيء: تأخذ 401 JSON — ولا صفحة login عندها أصلاً.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('admin', 'admin/*') ? route('admin.login') : null,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
