<?php

namespace App\Admin;

/**
 * ما يعرضه محرّر إعدادات كل لعبة.
 *
 * المفتاح هو مسار config نفسه، فيقرؤه المحرّك بلا تعديل. والوصف هنا لا في
 * ملفات config: تلك يقرؤها المطوّر، وهذا يقرؤه مشرف لا يعرف PHP — فالتسمية
 * تشرح ماذا يفعل الرقم في اللعبة، لا اسم متغيّره.
 *
 * أنواع الحقول:
 *   int         رقم صحيح بحدّين
 *   bool        مفتاح تشغيل
 *   text        نص قصير
 *   select      خيار من قائمة
 *   int_list    أرقام مفصولة بفواصل (خيارات شاشة الشروط)
 */
final class GameSettingsDefinitions
{
    /** @return array<int, array{title: string, hint?: string, fields: array<int, array<string, mixed>>}> */
    public static function for(string $gameType): array
    {
        $sections = match ($gameType) {
            'harf' => self::harf(),
            'spy' => self::spy(),
            'hadaf' => self::hadaf(),
            'mashhad' => self::mashhad(),
            default => [],
        };

        // حقل لم يعد في ملف config (أُعيدت تسميته مثلاً) لا يُعرض: تعديله
        // لن يقرأه أحد، وعرضه يوهم المشرف أنه يغيّر شيئاً.
        foreach ($sections as $index => $section) {
            $sections[$index]['fields'] = array_values(array_filter(
                $section['fields'],
                fn (array $field) => config()->has($field['key']),
            ));
        }

        return array_values(array_filter($sections, fn (array $section) => $section['fields'] !== []));
    }

    /** @return array<int, string> */
    public static function keysFor(string $gameType): array
    {
        $keys = [];

        foreach (self::for($gameType) as $section) {
            foreach ($section['fields'] as $field) {
                $keys[] = $field['key'];
            }
        }

        return $keys;
    }

    /** @return array<string, mixed>|null */
    public static function field(string $gameType, string $key): ?array
    {
        foreach (self::for($gameType) as $section) {
            foreach ($section['fields'] as $field) {
                if ($field['key'] === $key) {
                    return $field;
                }
            }
        }

        return null;
    }

    // =========================================================================

    private static function harf(): array
    {
        return [
            [
                'title' => 'مدد المراحل',
                'hint' => 'بالثواني. كل مرحلة لها مؤقّت سيرفر ونتيجة افتراضية حين ينتهي.',
                'fields' => [
                    self::int('harf.phases.awaiting_letter', 'مهلة سحب الحرف', 3, 60, 'بعدها يسحب السيرفر عن صاحب الدور.'),
                    self::int('harf.phases.reveal_letter', 'عرض الحرف قبل الكتابة', 1, 15),
                    self::int('harf.phases.grace', 'مهلة إكمال الفارغ بعد الستوب', 3, 60),
                    self::int('harf.phases.reveal', 'قراءة الإجابات', 3, 60),
                    self::int('harf.phases.objection', 'وقت الاعتراض', 5, 120),
                    self::int('harf.phases.voting', 'التصويت على كل اعتراض', 5, 60),
                    self::int('harf.phases.scoreboard', 'عرض لوحة النقاط', 3, 30),
                    self::int('harf.phases.tiebreak', 'جولة الحسم', 10, 120),
                ],
            ],
            [
                'title' => 'النقاط',
                'fields' => [
                    self::int('harf.points.unique', 'إجابة صحيحة لم يكتبها غيرك', 0, 100),
                    self::int('harf.points.duplicate', 'إجابة صحيحة مكررة', 0, 100),
                    self::int('harf.points.sole_answer', 'الوحيد الذي أجاب في العمود', 0, 100),
                    self::int('harf.points.empty_or_rejected', 'فارغة أو مرفوضة', -50, 50),
                    self::int('harf.points.stop_bonus', 'بونص الستوب (كل الخانات قُبلت)', 0, 100),
                    self::int('harf.points.stop_penalty', 'عقوبة الستوب (رُفضت خانة)', -100, 0),
                    self::int('harf.points.failed_objection', 'عقوبة الاعتراض الفاشل', -100, 0),
                ],
            ],
            [
                'title' => 'السقوف',
                'fields' => [
                    self::int('harf.limits.min_players', 'أقل عدد لاعبين', 2, 10),
                    self::int('harf.limits.max_players', 'أكثر عدد لاعبين', 3, 50),
                    self::int('harf.limits.objections_per_player', 'اعتراضات لكل لاعب بالجولة', 0, 20),
                    self::int('harf.limits.objections_discussed', 'اعتراضات تُناقَش (الباقي يُقبل)', 1, 20),
                    self::int('harf.limits.suggest_reduce_above_rounds', 'اقتراح تقليل الجولات فوق', 3, 100),
                    self::int('harf.limits.early_end_after_round', 'الإنهاء المبكّر متاح بعد جولة', 1, 100),
                    self::int('harf.limits.rejoin_grace_rounds', 'العودة بالنقاط خلال جولات', 0, 10),
                ],
            ],
            [
                'title' => 'شاشة الشروط',
                'hint' => 'الخيارات التي يراها صاحب الغرفة، والقيم التي تبدأ محدّدة.',
                'fields' => [
                    self::intList('harf.write_seconds_options', 'خيارات مدة الكتابة', 10, 600),
                    self::intList('harf.rounds_per_player_options', 'خيارات الجولات لكل شخص', 1, 10),
                    self::int('harf.flexible_write_seconds', 'مدة الكتابة في الوضع المرن', 30, 600),
                    self::int('harf.defaults.write_seconds', 'مدة الكتابة الافتراضية', 10, 600),
                    self::int('harf.defaults.rounds_per_player', 'الجولات لكل شخص افتراضياً', 1, 10),
                    self::bool('harf.defaults.flexible_mode', 'الوضع المرن مفعّل افتراضياً'),
                ],
            ],
        ];
    }

    private static function spy(): array
    {
        return [
            [
                'title' => 'مدد المراحل',
                'hint' => 'بالثواني. مدة السؤال والجواب تأتي من شروط الغرفة.',
                'fields' => [
                    self::int('spy.phases.role_reveal', 'قراءة الورقة السرّية', 3, 60),
                    self::int('spy.phases.voting', 'التصويت على الجاسوس', 10, 180),
                    self::int('spy.phases.vote_result', 'عرض نتيجة التصويت', 3, 60),
                    self::int('spy.phases.spy_guess', 'فرصة الجاسوس الأخيرة', 5, 120),
                ],
            ],
            [
                'title' => 'السقوف والقواعد',
                'fields' => [
                    self::int('spy.limits.min_players', 'أقل عدد لاعبين', 3, 10),
                    self::int('spy.limits.max_players', 'أكثر عدد لاعبين', 3, 50),
                    self::int('spy.limits.question_length', 'أقصى طول للسؤال', 20, 500),
                    self::int('spy.limits.answer_length', 'أقصى طول للجواب', 20, 500),
                    self::int('spy.limits.guess_options', 'عدد خيارات تخمين الجاسوس', 2, 12),
                    self::int('spy.limits.spy_escapes_at', 'الجاسوس ينجو حين يبقى', 2, 5, 'عدد اللاعبين الباقين الذي يُعلن عنده فوز الجاسوس.'),
                    self::int('spy.limits.early_end_after_round', 'الإنهاء المبكّر متاح بعد جولة', 1, 20),
                    self::int('spy.limits.rejoin_grace_rounds', 'العودة خلال جولات', 0, 10),
                ],
            ],
            [
                'title' => 'شاشة الشروط',
                'fields' => [
                    self::intList('spy.turn_seconds_options', 'خيارات مدة الدور', 10, 300),
                    self::intList('spy.max_rounds_options', 'خيارات أقصى عدد جولات', 1, 20),
                    self::int('spy.defaults.turn_seconds', 'مدة الدور الافتراضية', 10, 300),
                    self::int('spy.defaults.max_rounds', 'أقصى جولات افتراضياً', 1, 20),
                    self::bool('spy.defaults.last_guess', 'فرصة الجاسوس الأخيرة مفعّلة افتراضياً'),
                ],
            ],
        ];
    }

    private static function hadaf(): array
    {
        $difficulties = ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'];

        return [
            [
                'title' => 'مدد المراحل',
                'hint' => 'بالثواني. مدة السؤال تأتي من شروط الغرفة.',
                'fields' => [
                    self::int('hadaf.phases.ready', 'العدّ التنازلي قبل السؤال', 1, 15),
                    self::int('hadaf.phases.reveal', 'كشف الجواب', 2, 30),
                    self::int('hadaf.phases.scoreboard', 'عرض الترتيب', 2, 30),
                    self::int('hadaf.phases.tiebreak', 'جولة الحسم', 5, 120),
                ],
            ],
            [
                'title' => 'النقاط',
                'fields' => [
                    self::int('hadaf.points.correct', 'إجابة صحيحة', 0, 100),
                    self::intList('hadaf.points.speed_bonus', 'بونص السرعة (الأول، الثاني، الثالث...)', 0, 100),
                    self::int('hadaf.points.streak_threshold', 'السلسلة تبدأ من إجابة رقم', 2, 20),
                    self::int('hadaf.points.streak_bonus', 'بونص السلسلة', 0, 100),
                    self::int('hadaf.points.wrong', 'إجابة خاطئة', -100, 0),
                    self::int('hadaf.points.no_answer', 'بلا إجابة', -100, 0),
                    self::int('hadaf.points.risk_multiplier', 'مضاعف ⚡ عند الإصابة', 1, 10),
                    self::int('hadaf.points.risk_penalty', 'خصم ⚡ عند الخطأ', -100, 0),
                ],
            ],
            [
                'title' => 'السقوف',
                'fields' => [
                    self::int('hadaf.choices', 'عدد الخيارات لكل سؤال', 2, 6),
                    self::int('hadaf.limits.min_players', 'أقل عدد لاعبين', 1, 10),
                    self::int('hadaf.limits.max_players', 'أكثر عدد لاعبين', 2, 50),
                    self::int('hadaf.limits.risks_per_game', 'مرات ⚡ بالجلسة', 0, 20),
                    self::int('hadaf.limits.early_end_after_round', 'الإنهاء المبكّر متاح بعد جولة', 1, 50),
                    self::int('hadaf.limits.rejoin_grace_rounds', 'العودة بالنقاط خلال جولات', 0, 10),
                ],
            ],
            [
                'title' => 'شاشة الشروط والوضع المرن',
                'fields' => [
                    self::intList('hadaf.rounds_options', 'خيارات عدد الجولات', 1, 50),
                    self::intList('hadaf.question_seconds_options', 'خيارات وقت السؤال', 5, 120),
                    self::int('hadaf.defaults.rounds', 'الجولات افتراضياً', 1, 50),
                    self::int('hadaf.defaults.question_seconds', 'وقت السؤال افتراضياً', 5, 120),
                    self::bool('hadaf.defaults.flexible_mode', 'الوضع المرن مفعّل افتراضياً'),
                    self::int('hadaf.flexible.question_seconds', 'وقت السؤال في الوضع المرن', 5, 300),
                    self::select('hadaf.flexible.difficulty', 'صعوبة الوضع المرن', $difficulties),
                ],
            ],
        ];
    }

    private static function mashhad(): array
    {
        $difficulties = ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'];

        return [
            [
                'title' => 'مدد المراحل',
                'hint' => 'بالثواني. مدة المشهد نفسه تأتي من شروط الغرفة.',
                'fields' => [
                    self::int('mashhad.phases.role_reveal', 'قراءة الدور السرّي', 5, 120),
                    self::int('mashhad.phases.claims', 'الادّعاء', 10, 180),
                    self::int('mashhad.phases.challenge', 'الكشف والاعتراض', 10, 180),
                    self::int('mashhad.phases.verdict', 'التصويت على كل اعتراض', 5, 120),
                    self::int('mashhad.phases.scoreboard', 'نقاط المشهد', 3, 60),
                    self::int('mashhad.phases.awards', 'جوائز السهرة', 10, 180),
                ],
            ],
            [
                'title' => 'النقاط',
                'fields' => [
                    self::int('mashhad.points.main_goal', 'الهدف الرئيسي', 0, 200),
                    self::int('mashhad.points.bonus_goal', 'الهدف الإضافي', 0, 200),
                    self::int('mashhad.points.hard_role', 'بونص الدور الصعب', 0, 200),
                    self::int('mashhad.points.event_used', 'استغلال الحدث المفاجئ', 0, 100),
                    self::int('mashhad.points.award', 'كل جائزة سهرة', 0, 100),
                    self::int('mashhad.points.failed_challenge', 'اعتراض سقط', -100, 0),
                    self::int('mashhad.points.false_claim', 'ادّعاء سقط', -100, 0),
                ],
            ],
            [
                'title' => 'الأحداث المفاجئة',
                'fields' => [
                    self::intList('mashhad.events.count', 'عدد الأحداث (من، إلى)', 0, 10),
                    self::int('mashhad.events.min_gap_seconds', 'أقل فجوة بين حدثين', 5, 300),
                ],
            ],
            [
                'title' => 'السقوف',
                'fields' => [
                    self::int('mashhad.limits.min_players', 'أقل عدد لاعبين', 2, 10),
                    self::int('mashhad.limits.max_players', 'أكثر عدد لاعبين', 3, 30),
                    self::int('mashhad.limits.challenges_per_player', 'اعتراضات لكل لاعب', 0, 20),
                    self::int('mashhad.limits.challenges_discussed', 'اعتراضات تُناقَش', 1, 20),
                    self::int('mashhad.limits.message_length', 'أقصى طول للرسالة', 20, 1000),
                    self::int('mashhad.limits.messages_per_scene', 'سقف رسائل المشهد', 50, 2000),
                    self::int('mashhad.limits.rejoin_grace_scenes', 'العودة خلال مشاهد', 0, 5),
                ],
            ],
            [
                'title' => 'شاشة الشروط والوضع العائلي',
                'fields' => [
                    self::intList('mashhad.scenes_options', 'خيارات عدد المشاهد', 1, 10),
                    self::intList('mashhad.scene_seconds_options', 'خيارات مدة المشهد (ثواني)', 30, 900),
                    self::int('mashhad.defaults.scenes', 'المشاهد افتراضياً', 1, 10),
                    self::int('mashhad.defaults.scene_seconds', 'مدة المشهد افتراضياً', 30, 900),
                    self::bool('mashhad.defaults.family_mode', 'الوضع العائلي مفعّل افتراضياً'),
                    self::int('mashhad.family.scene_seconds', 'مدة المشهد في الوضع العائلي', 30, 900),
                    self::select('mashhad.family.difficulty', 'صعوبة أدوار الوضع العائلي', $difficulties),
                    self::bool('mashhad.family.bonus_goals', 'أهداف إضافية في الوضع العائلي'),
                    self::bool('mashhad.family.secrets', 'أسرار في الوضع العائلي'),
                ],
            ],
        ];
    }

    // =========================================================================

    private static function int(string $key, string $label, int $min, int $max, ?string $hint = null): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'int', 'min' => $min, 'max' => $max, 'hint' => $hint];
    }

    private static function bool(string $key, string $label, ?string $hint = null): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'bool', 'hint' => $hint];
    }

    /** @param array<string, string> $options */
    private static function select(string $key, string $label, array $options, ?string $hint = null): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'select', 'options' => $options, 'hint' => $hint];
    }

    private static function intList(string $key, string $label, int $min, int $max, ?string $hint = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'type' => 'int_list',
            'min' => $min,
            'max' => $max,
            'hint' => $hint ?? 'أرقام مفصولة بفاصلة.',
        ];
    }
}
