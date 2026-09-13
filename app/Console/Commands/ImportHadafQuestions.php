<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * استيراد أسئلة لعبة الهدف إلى البنك.
 *
 * هذا هو الطريق إلى عشرة آلاف سؤال: الملفات المرفقة في `resources/questions`
 * بداية، وأي بنك خارجي يُحوَّل لنفس الصيغة ويُستورد بأمر واحد. التكرار مستحيل
 * — البصمة مبنية على نصّ السؤال المطبَّع، فاستيراد الملف نفسه عشر مرات لا
 * يزيد في البنك سؤالاً واحداً.
 *
 * الصيغة (JSONL: كائن في كل سطر، أو ملف JSON فيه مصفوفة):
 *
 *   {"c":"religion","d":"easy","q":"كم عدد أركان الإسلام؟","a":"خمسة",
 *    "w":["أربعة","ستة","سبعة"],"e":"شهادة وصلاة وزكاة وصوم وحج"}
 *
 *   c = المجموعة · d = الصعوبة · q = السؤال · a = الجواب
 *   w = المضلِّلات · e = توضيح (اختياري)
 */
class ImportHadafQuestions extends Command
{
    protected $signature = 'hadaf:import
                            {path? : ملف أو مجلد — الافتراضي resources/questions}
                            {--fresh : امسح البنك قبل الاستيراد}';

    protected $description = 'يستورد أسئلة لعبة الهدف إلى البنك (JSON أو JSONL)، بلا تكرار.';

    public function handle(): int
    {
        $path = $this->argument('path') ?? resource_path('questions');

        if (! file_exists($path)) {
            $this->error("ما في ملف ولا مجلد على هذا المسار: {$path}");

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            DB::table('hadaf_questions')->delete();
            $this->warn('مُسح البنك القديم.');
        }

        $files = is_dir($path)
            ? array_merge(glob($path.'/*.jsonl') ?: [], glob($path.'/*.json') ?: [])
            : [$path];

        if ($files === []) {
            $this->error('ما في ملفات أسئلة في هذا المجلد.');

            return self::FAILURE;
        }

        $imported = 0;
        $skipped = 0;
        $invalid = 0;

        foreach ($files as $file) {
            foreach ($this->rows($file) as $lineNumber => $row) {
                $record = $this->normalize($row, basename($file, '.jsonl'));

                if ($record === null) {
                    $invalid++;
                    $this->line("  <fg=yellow>سطر {$lineNumber} في ".basename($file).' — ناقص أو غير صالح</>');

                    continue;
                }

                // التكرار لا يُعدّ خطأً: الاستيراد يُعاد كثيراً وهذا مقصود.
                $created = DB::table('hadaf_questions')->insertOrIgnore($record);

                $created > 0 ? $imported++ : $skipped++;
            }
        }

        $this->newLine();
        $this->info("أُضيف {$imported} سؤالاً · تكرار متجاهَل {$skipped} · غير صالح {$invalid}");
        $this->table(
            ['المجموعة', 'العدد'],
            DB::table('hadaf_questions')
                ->selectRaw('category, count(*) as total')
                ->groupBy('category')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($row) => [
                    config("hadaf.categories.{$row->category}.label") ?? $row->category,
                    $row->total,
                ])
                ->all(),
        );

        return self::SUCCESS;
    }

    /**
     * قراءة سطراً سطراً لا دفعة واحدة: ملف بعشرة آلاف سؤال لا يُحمَّل في
     * الذاكرة كلّه ليُكتب منه صفّ واحد في كل مرة.
     *
     * @return iterable<int, array<string, mixed>>
     */
    private function rows(string $file): iterable
    {
        $handle = fopen($file, 'r');

        if ($handle === false) {
            return;
        }

        try {
            $first = true;
            $lineNumber = 0;

            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $line = trim($line);

                if ($line === '' || $line === '[' || $line === ']') {
                    continue;
                }

                // صيغة المصفوفة: الفاصلة في آخر السطر جزء من JSON المحيط.
                $line = rtrim($line, ',');

                try {
                    $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    // ملف JSON مُنسَّق على أسطر متعددة: نعود لقراءته كاملاً.
                    if ($first) {
                        yield from $this->wholeFile($file);

                        return;
                    }

                    $decoded = null;
                }

                $first = false;

                if (is_array($decoded)) {
                    yield $lineNumber => $decoded;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return iterable<int, array<string, mixed>> */
    private function wholeFile(string $file): iterable
    {
        $decoded = json_decode(file_get_contents($file) ?: '[]', true);

        foreach (is_array($decoded) ? $decoded : [] as $index => $row) {
            if (is_array($row)) {
                yield $index + 1 => $row;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function normalize(array $row, string $source): ?array
    {
        $category = (string) ($row['c'] ?? $row['category'] ?? '');
        $prompt = trim((string) ($row['q'] ?? $row['prompt'] ?? ''));
        $answer = trim((string) ($row['a'] ?? $row['answer'] ?? ''));
        $distractors = $row['w'] ?? $row['distractors'] ?? [];
        $difficulty = (string) ($row['d'] ?? $row['difficulty'] ?? 'medium');

        if (! isset(config('hadaf.categories')[$category])) {
            return null;
        }

        if (! in_array($difficulty, config('hadaf.difficulties'), true)) {
            $difficulty = 'medium';
        }

        $distractors = array_values(array_filter(
            array_map(fn ($option) => trim((string) $option), (array) $distractors),
            fn (string $option) => $option !== '' && $option !== $answer,
        ));

        // أقل من العدد المطلوب من المضلِّلات يعني خيارات ناقصة على الشاشة.
        if ($prompt === '' || $answer === '' || count($distractors) < config('hadaf.choices') - 1) {
            return null;
        }

        $explanation = trim((string) ($row['e'] ?? $row['explanation'] ?? ''));

        return [
            'category' => $category,
            'difficulty' => $difficulty,
            'prompt' => $prompt,
            'answer' => $answer,
            'distractors' => json_encode($distractors, JSON_UNESCAPED_UNICODE),
            'explanation' => $explanation === '' ? null : $explanation,
            'source' => $source,
            'fingerprint' => sha1($category.'|'.preg_replace('/\s+/u', ' ', $prompt)),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
