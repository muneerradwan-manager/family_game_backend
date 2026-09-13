<?php

namespace App\Games\Mashhad\Scenes;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * سحب المشاهد وتوزيع الأدوار.
 *
 * توزيع الأدوار هو ما يصنع الدراما: الأدوار الأساسية مكتوبة لكل مشهد
 * بأهدافٍ **متضاربة عمداً** (واحد يريد فتح الباب وآخر يريد منعه). وحين يزيد
 * عدد اللاعبين عن أدوار المشهد تأتي البقية من مجموعة أدوار الحشو العامة —
 * لأن كتابة اثني عشر دوراً خاصاً لكل مشهد تُنتج أدواراً هامشية باهتة، بينما
 * دور الحشو له هدفٌ اجتماعي حقيقي يصلح في أي حبكة.
 */
class SceneLibrary
{
    /**
     * سحب مشهد لم يُستعمل في هذه الجلسة.
     *
     * @param  string|null  $category  null = مزيج من كل المجموعات
     * @param  array<int, string>  $exclude  مفاتيح المشاهد المستهلكة
     * @return array<string, mixed>
     */
    public function draw(?string $category, array $exclude = []): array
    {
        $row = DB::table('mashhad_scenes')
            ->when($category !== null, fn ($query) => $query->where('category', $category))
            ->when($exclude !== [], fn ($query) => $query->whereNotIn('key', $exclude))
            ->inRandomOrder()
            ->first();

        // نفدت مشاهد هذه المجموعة: نوسّع بدل أن نعيد مشهداً لعبوه للتوّ.
        $row ??= DB::table('mashhad_scenes')
            ->whereNotIn('key', $exclude)
            ->inRandomOrder()
            ->first();

        // نفد البنك كله (جلسة طويلة جداً): نسمح بالتكرار حينها.
        $row ??= DB::table('mashhad_scenes')->inRandomOrder()->first();

        if ($row === null) {
            throw new RuntimeException('بنك المشاهد فارغ — شغّل php artisan mashhad:import');
        }

        return [
            'key' => $row->key,
            'category' => $row->category,
            'categoryLabel' => config("mashhad.categories.{$row->category}.label"),
            'categoryEmoji' => config("mashhad.categories.{$row->category}.emoji"),
            'title' => $row->title,
            'setup' => $row->setup,
            'roles' => json_decode($row->roles, true) ?: [],
            'events' => json_decode($row->events, true) ?: [],
        ];
    }

    /**
     * توزيع الأدوار على اللاعبين.
     *
     * الأدوار الأساسية أولاً وبترتيبها المكتوب — فهي متضاربة ومترابطة،
     * وإسقاط أحدها يكسر الحبكة. ثم الحشو للباقين.
     *
     * @param  array<string, mixed>  $scene
     * @param  array<int, string>  $playerIds
     * @param  bool  $simplified  الوضع العائلي: أدوار سهلة بلا أسرار ولا أهداف إضافية
     * @return array<string, array<string, mixed>>
     */
    public function assign(array $scene, array $playerIds, bool $simplified = false): array
    {
        $core = $scene['roles'];
        $filler = config('mashhad.filler_roles');

        if ($simplified) {
            $easyCore = array_values(array_filter(
                $core,
                fn (array $role) => ($role['d'] ?? 'medium') === 'easy',
            ));

            // لا نُفرغ المشهد من أدواره لو لم يكن فيه سهلٌ كافٍ: الأدوار
            // الأساسية تبقى، والتبسيط يطال الأسرار والأهداف الإضافية.
            $core = $easyCore === [] ? $core : array_merge($easyCore, $core);
            $core = $this->uniqueByName($core);

            $filler = array_values(array_filter(
                $filler,
                fn (array $role) => ($role['difficulty'] ?? 'medium') === 'easy',
            ));
        }

        shuffle($filler);

        $seats = [];
        $order = $playerIds;
        shuffle($order);

        foreach ($order as $index => $userId) {
            $role = $core[$index] ?? null;

            if ($role !== null) {
                $seats[$userId] = $this->fromCore($role, $simplified);

                continue;
            }

            $fillerRole = $filler[($index - count($core)) % max(count($filler), 1)] ?? null;

            $seats[$userId] = $fillerRole === null
                ? $this->fromCore($core[$index % count($core)], $simplified)
                : $this->fromFiller($fillerRole);
        }

        return $seats;
    }

    /**
     * أوقات الأحداث المفاجئة داخل المشهد.
     *
     * ضمن نافذة وسطى: حدث في الثانية الأولى يضيع قبل أن تبدأ القصة، وحدث
     * قبل الصافرة بثانية لا يترك وقتاً لاستغلاله. وبينها فجوة دنيا حتى لا
     * يتزاحم حدثان.
     *
     * @param  array<int, array<string, mixed>>  $events
     * @param  array<int, string>  $playerIds
     * @return array<int, array<string, mixed>>
     */
    public function scheduleEvents(array $events, int $sceneSeconds, array $playerIds): array
    {
        if ($events === [] || $playerIds === []) {
            return [];
        }

        [$min, $max] = config('mashhad.events.count');
        [$from, $to] = config('mashhad.events.window');
        $gap = (int) config('mashhad.events.min_gap_seconds');

        $pool = $events;
        shuffle($pool);

        $wanted = min(random_int($min, $max), count($pool));
        $start = (int) round($sceneSeconds * $from);
        $end = (int) round($sceneSeconds * $to);

        // مشهد قصير لا يتّسع للفجوة الدنيا: نوزّع بالتساوي بدل أن نُسقط أحداثه.
        $span = max($end - $start, 1);
        $step = max((int) floor($span / max($wanted, 1)), 1);

        $scheduled = [];

        for ($index = 0; $index < $wanted; $index++) {
            $slotStart = $start + $index * $step;
            $slotEnd = min($slotStart + $step, $end);
            $at = $slotEnd > $slotStart ? random_int($slotStart, $slotEnd) : $slotStart;

            if ($index > 0 && $at - $scheduled[$index - 1]['at'] < $gap) {
                $at = $scheduled[$index - 1]['at'] + $gap;
            }

            if ($at >= $sceneSeconds) {
                break;
            }

            $event = $pool[$index];
            $scope = ($event['scope'] ?? 'all') === 'one' ? 'one' : 'all';

            $scheduled[] = [
                'text' => (string) $event['t'],
                'scope' => $scope,
                // الحدث الخاص يذهب للاعب واحد — وهنا تبدأ المعلومة غير المتكافئة.
                'userId' => $scope === 'one'
                    ? $playerIds[random_int(0, count($playerIds) - 1)]
                    : null,
                'at' => $at,
                'fired' => false,
            ];
        }

        return $scheduled;
    }

    /**
     * @param  array<string, mixed>  $role
     * @return array<string, mixed>
     */
    private function fromCore(array $role, bool $simplified): array
    {
        $bonus = config('mashhad.family.bonus_goals');
        $secrets = config('mashhad.family.secrets');

        return [
            'name' => (string) $role['n'],
            'goal' => (string) $role['g'],
            'bonus' => $simplified && ! $bonus ? null : ($role['b'] ?? null),
            'secret' => $simplified && ! $secrets ? null : ($role['s'] ?? null),
            'difficulty' => (string) ($role['d'] ?? 'medium'),
            'filler' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $role
     * @return array<string, mixed>
     */
    private function fromFiller(array $role): array
    {
        return [
            'name' => (string) $role['name'],
            'goal' => (string) $role['goal'],
            'bonus' => null,
            'secret' => null,
            'difficulty' => (string) ($role['difficulty'] ?? 'medium'),
            'filler' => true,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $roles
     * @return array<int, array<string, mixed>>
     */
    private function uniqueByName(array $roles): array
    {
        $seen = [];
        $unique = [];

        foreach ($roles as $role) {
            if (isset($seen[$role['n']])) {
                continue;
            }

            $seen[$role['n']] = true;
            $unique[] = $role;
        }

        return $unique;
    }
}
