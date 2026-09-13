<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AppTheme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ما يقرؤه التطبيق من لوحة الإدارة.
 */
class PlatformController extends Controller
{
    /**
     * إعدادات التطبيق — أول طلب عند الإقلاع، وقبل الدخول.
     *
     * بلا مصادقة عمداً: شاشة الصيانة والتحديث الإجباري والثيمات يجب أن
     * تعمل لمن لم يسجّل دخوله بعد، ولمن انتهت جلسته.
     */
    public function config(): JsonResponse
    {
        $themes = AppTheme::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'maintenance' => [
                'enabled' => (bool) config('platform.maintenance.enabled'),
                'message' => (string) config('platform.maintenance.message'),
            ],
            'registration' => [
                'enabled' => (bool) config('platform.registration.enabled'),
                'message' => (string) config('platform.registration.message'),
            ],
            'minAppVersion' => [
                'version' => config('platform.min_app_version.version'),
                'message' => (string) config('platform.min_app_version.message'),
                'storeUrl' => config('platform.min_app_version.store_url'),
            ],
            'themes' => $themes->map(fn (AppTheme $theme) => $theme->toApi())->values(),
            // ثيم افتراضي معطّل لا يُعرض؛ حينها أول ثيم ظاهر هو الافتراضي.
            'defaultThemeKey' => $themes->firstWhere('is_default', true)?->key ?? $themes->first()?->key,
            'serverTime' => (int) round(microtime(true) * 1000),
        ]);
    }

    /** إعلانات هذا المستخدم السارية الآن. */
    public function announcements(Request $request): JsonResponse
    {
        $announcements = Announcement::query()
            ->visibleTo($request->user())
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'announcements' => $announcements->map(fn (Announcement $item) => $item->toApi())->values(),
        ]);
    }
}
