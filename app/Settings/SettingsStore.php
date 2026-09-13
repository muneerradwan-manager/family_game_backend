<?php

namespace App\Settings;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * تعديلات المشرف فوق ملفات config.
 *
 * الفكرة كلها في سطر: القيمة المعدّلة تُكتب في config() نفسه. فالألعاب التي
 * تقرأ `config('harf.phases.grace')` تقرأ قيمة المشرف بلا أن تعرف بوجوده،
 * ولا يُعاد كتابة سطر واحد من محرّكاتها.
 *
 * وملفات config تبقى المرجع: حذف التعديل يعيد القيمة الافتراضية. ولهذا تُحفظ
 * نسخة من الجذور القابلة للتعديل قبل أي تعديل — نعيدها ثم نفرش التعديلات
 * فوقها، فلا يبقى أثر لتعديل حُذف.
 *
 * العمليات الطويلة (عامل الطابور) لا تُقلع مع كل طلب، فيحمل المخزن رقم
 * نسخة في الكاش؛ وقبل كل مهمة يقارنه بما طبّقه ويعيد التطبيق إن تغيّر.
 */
class SettingsStore
{
    private const CACHE_KEY = 'settings:overrides';

    private const VERSION_KEY = 'settings:version';

    /**
     * ما يجوز للمشرف تعديله. البنية التحتية (مخزن الحالة، JWT، قاعدة
     * البيانات) ليست هنا عمداً: خطأ فيها من المتصفح يُسقط الخدمة كلها.
     *
     * @var array<int, string>
     */
    public const EDITABLE_PREFIXES = [
        'harf.', 'spy.', 'hadaf.', 'mashhad.', 'games.catalog.', 'platform.',
    ];

    /** @var array<int, string> */
    private const ROOTS = ['harf', 'spy', 'hadaf', 'mashhad', 'games', 'platform'];

    /** @var array<string, mixed>|null نسخة القيم الافتراضية قبل أي تعديل */
    private ?array $defaults = null;

    private ?int $appliedVersion = null;

    public function __construct(
        private readonly Cache $cache,
        private readonly Config $config,
    ) {}

    /**
     * تطبيق كل التعديلات على config — عند الإقلاع.
     *
     * يبتلع أخطاء القاعدة: أثناء أول هجرة (أو في اختبار قبل الهجرة) الجدول
     * غير موجود، وغيابه يعني ببساطة «لا تعديلات» لا خدمة معطّلة.
     */
    public function apply(): void
    {
        $this->snapshotDefaults();

        try {
            $overrides = $this->overrides();
            $version = $this->version();
        } catch (Throwable) {
            return;
        }

        foreach ($this->defaults as $root => $value) {
            $this->config->set($root, $value);
        }

        foreach ($overrides as $key => $value) {
            $this->config->set($key, $value);
        }

        $this->appliedVersion = $version;
    }

    /** لعامل الطابور: أعِد التطبيق فقط إن غيّر المشرف شيئاً منذ آخر مرة. */
    public function applyIfStale(): void
    {
        try {
            $stale = $this->version() !== $this->appliedVersion;
        } catch (Throwable) {
            return;
        }

        if ($stale) {
            $this->apply();
        }
    }

    /** @return array<string, mixed> */
    public function overrides(): array
    {
        return $this->cache->rememberForever(self::CACHE_KEY, fn () => DB::table('settings')
            ->pluck('value', 'key')
            ->map(fn ($value) => json_decode((string) $value, true))
            ->all());
    }

    public function isOverridden(string $key): bool
    {
        return array_key_exists($key, $this->overrides());
    }

    /** القيمة الفعّالة الآن (تعديل المشرف أو الافتراضي). */
    public function get(string $key, mixed $fallback = null): mixed
    {
        return $this->config->get($key, $fallback);
    }

    /** القيمة كما في ملف config — لزر «رجوع للافتراضي». */
    public function default(string $key): mixed
    {
        $this->snapshotDefaults();

        return Arr::get($this->defaults, $key);
    }

    /**
     * حفظ تعديل.
     *
     * @return mixed القيمة السابقة الفعّالة — لسجل التدقيق
     */
    public function put(string $key, mixed $value, ?int $adminId = null): mixed
    {
        $this->assertEditable($key);
        $this->snapshotDefaults();

        $previous = $this->config->get($key);

        DB::table('settings')->updateOrInsert(
            ['key' => $key],
            [
                'value' => json_encode($value, JSON_UNESCAPED_UNICODE),
                'updated_by' => $adminId,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        $this->config->set($key, $value);
        $this->bump();

        return $previous;
    }

    /**
     * حذف التعديل = العودة للقيمة الافتراضية.
     *
     * @return mixed القيمة السابقة الفعّالة
     */
    public function forget(string $key): mixed
    {
        $this->assertEditable($key);
        $this->snapshotDefaults();

        $previous = $this->config->get($key);

        DB::table('settings')->where('key', $key)->delete();

        $this->config->set($key, $this->default($key));
        $this->bump();

        return $previous;
    }

    public function isEditable(string $key): bool
    {
        foreach (self::EDITABLE_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function assertEditable(string $key): void
    {
        if (! $this->isEditable($key)) {
            throw new InvalidArgumentException("الإعداد [{$key}] ليس قابلاً للتعديل من اللوحة.");
        }
    }

    private function version(): int
    {
        return (int) $this->cache->get(self::VERSION_KEY, 0);
    }

    /** إبطال الكاش ورفع رقم النسخة — فتلتقط العمليات الطويلة التغيير. */
    private function bump(): void
    {
        $this->cache->forget(self::CACHE_KEY);

        $next = $this->version() + 1;
        $this->cache->forever(self::VERSION_KEY, $next);
        $this->appliedVersion = $next;
    }

    private function snapshotDefaults(): void
    {
        if ($this->defaults !== null) {
            return;
        }

        $this->defaults = [];

        foreach (self::ROOTS as $root) {
            $this->defaults[$root] = $this->config->get($root, []);
        }
    }
}
