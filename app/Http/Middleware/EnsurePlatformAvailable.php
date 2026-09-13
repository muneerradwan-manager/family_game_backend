<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * مفاتيح التطبيق العامة: وضع الصيانة وأدنى نسخة مسموحة.
 *
 * يُطبَّق على كل مسارات api، إلا جلب إعدادات التطبيق نفسها وعرض الصور —
 * لو رُفض الأول لما عرف الجهاز أن هناك صيانة أصلاً، ولعرض خطأ شبكة بدل
 * رسالة المشرف.
 *
 * الرموز مقصودة: 503 للصيانة و426 للتحديث، فيميّزهما التطبيق عن أي خطأ آخر
 * ويعرض لكل منهما شاشته.
 */
class EnsurePlatformAvailable
{
    /** @var array<int, string> */
    private const ALWAYS_OPEN = ['api/app/config', 'api/uploads/*'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is(...self::ALWAYS_OPEN)) {
            return $next($request);
        }

        if (config('platform.maintenance.enabled')) {
            return response()->json([
                'message' => (string) config('platform.maintenance.message'),
                'maintenance' => true,
            ], 503);
        }

        $minimum = config('platform.min_app_version.version');
        $current = $request->header('X-App-Version');

        // بلا ترويسة لا نفرض شيئاً: النسخ الأقدم من هذه الميزة لا ترسلها،
        // ورفضها يعني رسالة لا تستطيع تلك النسخ فهمها أصلاً.
        if (is_string($minimum) && $minimum !== '' && is_string($current) && $current !== '') {
            if (version_compare($this->normalize($current), $this->normalize($minimum), '<')) {
                return response()->json([
                    'message' => (string) config('platform.min_app_version.message'),
                    'upgradeRequired' => true,
                    'minVersion' => $minimum,
                    'storeUrl' => config('platform.min_app_version.store_url'),
                ], 426);
            }
        }

        return $next($request);
    }

    /** «1.2.0+14» ← «1.2.0»: رقم البناء لا يدخل في المقارنة. */
    private function normalize(string $version): string
    {
        return trim(explode('+', $version)[0]);
    }
}
