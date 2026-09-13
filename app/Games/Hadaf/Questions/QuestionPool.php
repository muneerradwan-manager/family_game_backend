<?php

namespace App\Games\Hadaf\Questions;

use Illuminate\Support\Facades\DB;

/**
 * مصدر الأسئلة الموحّد: المولّدات + البنك المكتوب.
 *
 * المحرّك لا يعرف من أين جاء السؤال؛ يطلب واحداً بمجموعة وصعوبة فيصله جاهزاً.
 * الرياضيات تأتي من مولّدات (عدد لا نهائي)، وبقية المجموعات من جدول
 * `hadaf_questions` الذي يُملأ بـ`php artisan hadaf:import`.
 *
 * `$exclude` بصماتُ ما استُهلك في الجلسة — وهو ما يمنع التكرار. بالبصمة
 * لا بالمعرّف: السؤال المولَّد يأخذ معرّفاً جديداً كل مرة فلا يكشف المعرّفُ
 * تطابقَه. وأسوأ ما قد يحدث في لعبة سرعة أن يعرف نصفُ اللاعبين الجوابَ مسبقاً.
 */
class QuestionPool
{
    private const GENERATE_ATTEMPTS = 12;

    /** @var array<int, QuestionGenerator> */
    private array $generators;

    /** @param iterable<QuestionGenerator> $generators */
    public function __construct(iterable $generators = [])
    {
        $this->generators = is_array($generators) ? $generators : iterator_to_array($generators);
    }

    /**
     * سؤال واحد.
     *
     * @param  string|null  $category  null = مزيج من كل المجموعات
     * @param  array<int, string>  $exclude  بصمات الأسئلة المستهلكة في هذه الجلسة
     */
    public function draw(?string $category, string $difficulty, array $exclude = []): Question
    {
        $category ??= $this->randomCategory();

        // المجموعات المولَّدة تُخدَم من مولّداتها أولاً: لا نفاد.
        $generated = $this->generatorsFor($category, $difficulty);

        if ($generated !== []) {
            return $this->generateUnused($generated, $difficulty, $exclude);
        }

        $fromBank = $this->fromBank($category, $difficulty, $exclude);

        if ($fromBank !== null) {
            return $fromBank;
        }

        // نفد البنك لهذه المجموعة والصعوبة: نوسّع الصعوبة قبل أن نستسلم.
        $fromBank = $this->fromBank($category, null, $exclude);

        if ($fromBank !== null) {
            return $fromBank;
        }

        // مجموعة فارغة تماماً (بنك لم يُستورد بعد): الرياضيات لا تنفد أبداً.
        return $this->mathFallback($difficulty);
    }

    /** هل في البنك ما يكفي لتشغيل هذه المجموعة؟ */
    public function has(string $category): bool
    {
        if (config("hadaf.categories.{$category}.generated") === true) {
            return true;
        }

        return DB::table('hadaf_questions')->where('category', $category)->exists();
    }

    /** @return array<string, int> عدد أسئلة البنك لكل مجموعة */
    public function bankCounts(): array
    {
        return DB::table('hadaf_questions')
            ->selectRaw('category, count(*) as total')
            ->groupBy('category')
            ->pluck('total', 'category')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * @param  array<int, string>  $exclude
     */
    private function fromBank(string $category, ?string $difficulty, array $exclude): ?Question
    {
        $row = DB::table('hadaf_questions')
            ->where('category', $category)
            ->when($difficulty !== null, fn ($query) => $query->where('difficulty', $difficulty))
            ->when($exclude !== [], fn ($query) => $query->whereNotIn('fingerprint', $exclude))
            ->inRandomOrder()
            ->first();

        if ($row === null) {
            return null;
        }

        return Question::make(
            $row->category,
            $row->difficulty,
            $row->prompt,
            $row->answer,
            json_decode($row->distractors, true) ?: [],
            $row->explanation,
            (string) $row->id,
        );
    }

    private function mathFallback(string $difficulty): Question
    {
        return $this->generateUnused($this->generatorsFor('math', $difficulty), $difficulty, []);
    }

    /**
     * توليد سؤال لم يُستعمل في هذه الجلسة.
     *
     * المولّدات عشوائية، فقد تُخرج السؤال نفسه مرتين — خصوصاً في المستوى
     * السهل حيث مجال الأرقام ضيّق. نعيد المحاولة بعدد محدود ثم نقبل ما جاء:
     * تكرار نادر أهون من جولة تقف بلا سؤال.
     *
     * @param  array<int, QuestionGenerator>  $generators
     * @param  array<int, string>  $exclude
     */
    private function generateUnused(array $generators, string $difficulty, array $exclude): Question
    {
        $question = $generators[random_int(0, count($generators) - 1)]->generate($difficulty);

        for ($attempt = 1; $attempt < self::GENERATE_ATTEMPTS; $attempt++) {
            if (! in_array($question->fingerprint(), $exclude, true)) {
                return $question;
            }

            $question = $generators[random_int(0, count($generators) - 1)]->generate($difficulty);
        }

        return $question;
    }

    /** @return array<int, QuestionGenerator> */
    private function generatorsFor(string $category, string $difficulty): array
    {
        return array_values(array_filter(
            $this->generators,
            fn (QuestionGenerator $generator) => $generator->category() === $category
                && $generator->supports($difficulty),
        ));
    }

    private function randomCategory(): string
    {
        $keys = array_keys(config('hadaf.categories'));

        // لا نسحب من مجموعة فارغة في وضع المزيج: السؤال لا بد أن يأتي.
        $available = array_values(array_filter($keys, fn (string $key) => $this->has($key)));

        if ($available === []) {
            return 'math';
        }

        return $available[random_int(0, count($available) - 1)];
    }
}
