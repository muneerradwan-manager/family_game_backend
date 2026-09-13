<?php

namespace App\Games\State;

use Closure;

/**
 * طبقة "الحالة الحيّة" معزولة خلف واجهة واحدة عن قصد.
 *
 * بنسخة أولى بخادم واحد يكفي أي مخزن سريع؛ ومع التوسّع لأكثر من سيرفر تصبح
 * Redis إلزامية (حالة مشتركة). الانتقال حينها تبديل تنفيذ لا إعادة كتابة.
 */
interface GameStateStore
{
    /** @return array<string, mixed>|null */
    public function get(string $gameId): ?array;

    /** @param array<string, mixed> $state */
    public function put(string $gameId, array $state): void;

    public function forget(string $gameId): void;

    /**
     * قراءة-تعديل-كتابة تحت قفل حصري: هذا ما يمنع السباقات
     * (ضغطة سحب عند 9.9 ث + مؤقّت السيرفر معاً = تنجح الأولى فقط).
     *
     * يستقبل المُعدِّل الحالة الحالية ويعيد [الحالة الجديدة, القيمة المُرجَعة].
     * إعادة null كحالة جديدة تعني: لا تكتب شيئاً.
     *
     * @param  Closure(array<string, mixed>|null): array{0: array<string, mixed>|null, 1: mixed}  $mutator
     */
    public function mutate(string $gameId, Closure $mutator): mixed;
}
