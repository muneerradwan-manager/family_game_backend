<?php

namespace App\Providers;

use App\Auth\JwtGuard;
use App\Auth\JwtService;
use App\Games\GameModuleRegistry;
use App\Games\Hadaf\HadafModule;
use App\Games\Hadaf\Questions\Generators\ArithmeticGenerator;
use App\Games\Hadaf\Questions\Generators\MissingOperatorGenerator;
use App\Games\Hadaf\Questions\Generators\PercentGenerator;
use App\Games\Hadaf\Questions\Generators\SequenceGenerator;
use App\Games\Hadaf\Questions\QuestionPool;
use App\Games\Harf\HarfModule;
use App\Games\Mashhad\MashhadModule;
use App\Games\Spy\SpyModule;
use App\Games\State\CacheGameStateStore;
use App\Games\State\GameStateStore;
use App\Notifications\FcmPushNotifier;
use App\Notifications\LogPushNotifier;
use App\Notifications\PushNotifier;
use App\Settings\SettingsStore;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // طبقة الحالة الحيّة معزولة خلف واجهة واحدة: الانتقال بين المخازن
        // (ذاكرة خادم واحد ← Redis مشتركة) تبديل ربط لا إعادة كتابة.
        $this->app->singleton(GameStateStore::class, fn () => new CacheGameStateStore(
            Cache::store(config('games.state_store')),
        ));

        $this->app->singleton(GameModuleRegistry::class, fn ($app) => new GameModuleRegistry([
            $app->make(HarfModule::class),
            $app->make(SpyModule::class),
            $app->make(HadafModule::class),
            $app->make(MashhadModule::class),
        ]));

        // مولّدات أسئلة لعبة الهدف: إضافة مولّد = سطر هنا.
        $this->app->singleton(QuestionPool::class, fn ($app) => new QuestionPool([
            $app->make(ArithmeticGenerator::class),
            $app->make(SequenceGenerator::class),
            $app->make(MissingOperatorGenerator::class),
            $app->make(PercentGenerator::class),
        ]));

        // تعديلات لوحة الإدارة فوق ملفات config — مخزن واحد للعملية كلها،
        // فيتذكّر ما طبّقه ويعرف متى تغيّر شيء.
        $this->app->singleton(SettingsStore::class, fn ($app) => new SettingsStore(
            Cache::store(),
            $app['config'],
        ));

        // غياب إعداد FCM لا يعطّل فتح غرفة: نسجّل الإشعار بدل إرساله.
        $this->app->singleton(PushNotifier::class, function () {
            $credentials = config('services.fcm.credentials');
            $projectId = config('services.fcm.project_id');

            if (! config('services.fcm.enabled') || blank($credentials) || blank($projectId)) {
                return new LogPushNotifier;
            }

            return new FcmPushNotifier($credentials, $projectId);
        });
    }

    public function boot(): void
    {
        // أولاً: تعديلات المشرف فوق config، قبل أن يقرأ أحدٌ قيمة.
        $settings = $this->app->make(SettingsStore::class);
        $settings->apply();

        // عامل الطابور لا يُقلع مع كل مهمة: قبل كل مهمة نلتقط ما غيّره
        // المشرف منذ آخر مرة، فتسري نقاط اللعبة الجديدة بلا إعادة تشغيل.
        Queue::before(fn () => $settings->applyIfStale());

        $this->registerJwtGuard();
        $this->registerRateLimits();

        // php artisan dev = السيرفر + عامل الطابور + Reverb.
        // لا واجهة JS هنا (العميل تطبيق Flutter)، فـ vite لا محل له.
        if ($this->app->runningInConsole()) {
            DevCommands::except('vite');
        }
    }

    /**
     * حارس JWT: نفس التوكن يخدم REST ومصافحة الـ WebSocket.
     */
    private function registerJwtGuard(): void
    {
        Auth::extend('jwt', fn () => new JwtGuard(
            $this->app->make(JwtService::class),
            $this->app,
        ));
    }

    /**
     * حماية أساسية بغياب تحقق SMS: تسجيل الدخول وإنشاء الحسابات محدودان.
     */
    private function registerRateLimits(): void
    {
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perMinute(5)->by('phone:'.$request->input('phone')),
        ]);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(180)
            ->by($request->user()?->id ?: $request->ip()));

        // نوايا اللعب متكررة بطبيعتها (إرسال الإجابة مع كل حرف) فسقفها أعلى.
        RateLimiter::for('gameplay', fn (Request $request) => Limit::perMinute(600)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
