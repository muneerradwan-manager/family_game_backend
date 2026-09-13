<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * استيراد مشاهد لعبة المشهد إلى البنك.
 *
 * الصيغة (JSONL: كائن في كل سطر):
 *
 *   {"key":"blackout","c":"daily","title":"انقطعت الكهرباء","setup":"...",
 *    "roles":[{"n":"الأب","g":"شغّل الكهرباء","b":"هدف إضافي","s":"سرّك","d":"medium"}],
 *    "events":[{"t":"🚪 حدا عم يدق عالباب!","scope":"all"}]}
 *
 *   key = مفتاح فريد · c = المجموعة · roles = الأدوار الأساسية
 *   n اسم · g الهدف · b هدف إضافي (اختياري) · s سرّ (اختياري) · d الصعوبة
 *   events: t النص · scope = all (للجميع) أو one (للاعب واحد)
 *
 * المشهد الموجود يُحدَّث بمفتاحه لا يُكرَّر، فتعديل مشهد ثم إعادة الاستيراد
 * تُحدِّثه في مكانه.
 */
class ImportScenes extends Command
{
    protected $signature = 'mashhad:import
                            {path? : ملف أو مجلد — الافتراضي resources/scenes}
                            {--fresh : امسح البنك قبل الاستيراد}';

    protected $description = 'يستورد مشاهد لعبة المشهد إلى البنك (JSONL).';

    public function handle(): int
    {
        $path = $this->argument('path') ?? resource_path('scenes');

        if (! file_exists($path)) {
            $this->error("ما في ملف ولا مجلد على هذا المسار: {$path}");

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            DB::table('mashhad_scenes')->delete();
            $this->warn('مُسح بنك المشاهد القديم.');
        }

        $files = is_dir($path) ? (glob($path.'/*.jsonl') ?: []) : [$path];

        if ($files === []) {
            $this->error('ما في ملفات مشاهد في هذا المجلد.');

            return self::FAILURE;
        }

        $imported = 0;
        $updated = 0;
        $invalid = 0;

        foreach ($files as $file) {
            $lineNumber = 0;

            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $lineNumber++;
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                try {
                    $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    $invalid++;
                    $this->line("  <fg=yellow>سطر {$lineNumber} في ".basename($file).' — JSON غير صالح</>');

                    continue;
                }

                $record = $this->normalize($row, basename($file, '.jsonl'));

                if ($record === null) {
                    $invalid++;
                    $this->line("  <fg=yellow>سطر {$lineNumber} في ".basename($file).' — ناقص أو غير صالح</>');

                    continue;
                }

                $exists = DB::table('mashhad_scenes')->where('key', $record['key'])->exists();

                DB::table('mashhad_scenes')->updateOrInsert(
                    ['key' => $record['key']],
                    $record,
                );

                $exists ? $updated++ : $imported++;
            }
        }

        $this->newLine();
        $this->info("أُضيف {$imported} مشهداً · حُدّث {$updated} · غير صالح {$invalid}");
        $this->table(
            ['المجموعة', 'العدد'],
            DB::table('mashhad_scenes')
                ->selectRaw('category, count(*) as total')
                ->groupBy('category')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($row) => [
                    config("mashhad.categories.{$row->category}.label") ?? $row->category,
                    $row->total,
                ])
                ->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function normalize(array $row, string $source): ?array
    {
        $key = trim((string) ($row['key'] ?? ''));
        $category = (string) ($row['c'] ?? $row['category'] ?? '');
        $title = trim((string) ($row['title'] ?? ''));
        $setup = trim((string) ($row['setup'] ?? ''));

        if ($key === '' || $title === '' || ! isset(config('mashhad.categories')[$category])) {
            return null;
        }

        $roles = [];

        foreach ((array) ($row['roles'] ?? []) as $role) {
            $name = trim((string) ($role['n'] ?? ''));
            $goal = trim((string) ($role['g'] ?? ''));

            if ($name === '' || $goal === '') {
                continue;
            }

            $difficulty = (string) ($role['d'] ?? 'medium');

            $roles[] = array_filter([
                'n' => $name,
                'g' => $goal,
                'b' => trim((string) ($role['b'] ?? '')) ?: null,
                's' => trim((string) ($role['s'] ?? '')) ?: null,
                'd' => in_array($difficulty, config('mashhad.difficulties'), true)
                    ? $difficulty
                    : 'medium',
            ], fn ($value) => $value !== null);
        }

        // مشهد بأقل من هذا العدد لا يصنع تضارباً — وبلا تضارب لا لعبة.
        if (count($roles) < (int) config('mashhad.limits.min_players')) {
            return null;
        }

        $events = [];

        foreach ((array) ($row['events'] ?? []) as $event) {
            $text = trim((string) ($event['t'] ?? ''));

            if ($text === '') {
                continue;
            }

            $events[] = [
                't' => $text,
                'scope' => ($event['scope'] ?? 'all') === 'one' ? 'one' : 'all',
            ];
        }

        return [
            'key' => $key,
            'category' => $category,
            'title' => $title,
            'setup' => $setup,
            'roles' => json_encode($roles, JSON_UNESCAPED_UNICODE),
            'events' => json_encode($events, JSON_UNESCAPED_UNICODE),
            'source' => $source,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
