<?php

namespace App\Games\Hadaf\Questions;

/**
 * مولّد أسئلة برمجي.
 *
 * البديل عن كتابة عشرة آلاف سؤال بيد بشر: الرياضيات والمتسلسلات والمنطق
 * قوالب لا معلومات، فتُولَّد بلا حدّ وبلا تكرار وبصعوبة متدرّجة.
 */
interface QuestionGenerator
{
    /** المجموعة التي يغذّيها هذا المولّد. */
    public function category(): string;

    /** هل يجيد هذا المولّد توليد سؤال بهذه الصعوبة؟ */
    public function supports(string $difficulty): bool;

    public function generate(string $difficulty): Question;
}
