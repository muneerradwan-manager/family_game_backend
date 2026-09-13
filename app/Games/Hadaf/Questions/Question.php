<?php

namespace App\Games\Hadaf\Questions;

use Illuminate\Support\Str;

/**
 * سؤال واحد جاهز للعرض.
 *
 * الخيارات مخلوطة هنا لا في مصدر السؤال: البنك المكتوب يخزّن الجواب منفصلاً
 * عن المضلِّلات، فلو عُرضت بترتيبها المخزّن لصار موقع الجواب ثابتاً ولحفظه
 * اللاعبون. الخلط عند السحب يجعل الموقع عشوائياً في كل مرة.
 */
final class Question
{
    /** @param array<int, string> $choices */
    private function __construct(
        public readonly string $id,
        public readonly string $category,
        public readonly string $difficulty,
        public readonly string $prompt,
        public readonly array $choices,
        public readonly int $answerIndex,
        /** توضيح قصير يُعرض بعد كشف الجواب — اختياري. */
        public readonly ?string $explanation,
    ) {}

    /**
     * بناء سؤال من جواب صحيح وقائمة مضلِّلات.
     *
     * @param  array<int, string>  $distractors
     */
    public static function make(
        string $category,
        string $difficulty,
        string $prompt,
        string $answer,
        array $distractors,
        ?string $explanation = null,
        ?string $id = null,
    ): self {
        $limit = (int) config('hadaf.choices') - 1;

        // مضلِّلات مكررة أو مطابقة للجواب تكشف نفسها: نُنظّفها قبل الاقتطاع.
        $distractors = array_values(array_unique(array_filter(
            $distractors,
            fn (string $option) => $option !== '' && $option !== $answer,
        )));

        $distractors = self::topUp($answer, $distractors, $limit);

        $choices = array_merge([$answer], array_slice($distractors, 0, $limit));
        shuffle($choices);

        return new self(
            $id ?? (string) Str::uuid(),
            $category,
            $difficulty,
            $prompt,
            array_values($choices),
            (int) array_search($answer, $choices, true),
            $explanation,
        );
    }

    /**
     * بصمة مستقرة مشتقّة من نصّ السؤال.
     *
     * المعرّف وحده لا يكفي لمنع التكرار: السؤال المولَّد يأخذ معرّفاً جديداً
     * في كل مرة، فلو اعتمدنا عليه لأمكن أن يتكرر «كم يساوي 50% من 100؟»
     * مرتين في الجلسة نفسها. البصمة على النص تكشف التطابق أياً كان مصدره.
     *
     * وهي الصيغة نفسها المستعملة في `hadaf:import`، فبصمة سؤال البنك هنا
     * تطابق عمود `fingerprint` في القاعدة.
     */
    public function fingerprint(): string
    {
        return sha1($this->category.'|'.(preg_replace('/\s+/u', ' ', $this->prompt) ?? $this->prompt));
    }

    /**
     * ضمان عدد الخيارات.
     *
     * مضلِّلات المولّدات مبنية على أخطاء واقعية، وقد يتصادف أن ينهار بعضها:
     * خصم 50% يجعل «الباقي» مساوياً للجواب فيُحذف، فتخرج ثلاثة خيارات بدل
     * أربعة — وسؤال بثلاثة خيارات أسهل مما قُصد له. نكمّل بجيران عددية حين
     * يكون الجواب رقماً، فهي الحالة الوحيدة التي يصحّ فيها الاختلاق.
     *
     * @param  array<int, string>  $distractors
     * @return array<int, string>
     */
    private static function topUp(string $answer, array $distractors, int $limit): array
    {
        if (count($distractors) >= $limit || ! is_numeric($answer)) {
            return $distractors;
        }

        $value = (int) $answer;
        $offset = 1;

        while (count($distractors) < $limit && $offset < 60) {
            foreach ([$value + $offset, $value - $offset] as $candidate) {
                if ($candidate < 0 || count($distractors) >= $limit) {
                    continue;
                }

                $option = (string) $candidate;

                if ($option !== $answer && ! in_array($option, $distractors, true)) {
                    $distractors[] = $option;
                }
            }

            $offset++;
        }

        return $distractors;
    }

    public function answer(): string
    {
        return $this->choices[$this->answerIndex];
    }

    /**
     * ما يراه اللاعب أثناء الإجابة — **بلا الجواب الصحيح**.
     *
     * هذه هي النقطة التي تُكسر فيها اللعبة لو أُهملت: موقع الجواب في الحمولة
     * يعني أن من يفتح أدوات المطوّر يفوز كل جولة.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'categoryLabel' => config("hadaf.categories.{$this->category}.label"),
            'categoryEmoji' => config("hadaf.categories.{$this->category}.emoji"),
            'difficulty' => $this->difficulty,
            'prompt' => $this->prompt,
            'choices' => $this->choices,
        ];
    }

    /** الحالة الكاملة للتخزين الداخلي — لا تُبَث قبل مرحلة الكشف. */
    public function toArray(): array
    {
        return $this->toPublicArray() + [
            'answerIndex' => $this->answerIndex,
            'explanation' => $this->explanation,
        ];
    }
}
