<?php

namespace App\Games\Mashhad;

/**
 * مراحل المشهد الواحد.
 *
 * الترتيب هو القصة نفسها: تعرف دورك، تعيش المشهد، تدّعي ما حقّقته، يُكشف كل
 * شيء فيعترض من يشك، ثم تُحسم الاعتراضات وتُحسب النقاط.
 */
enum MashhadPhase: string
{
    /** بطاقة سرّية: شخصيتك وهدفك وسرّك. */
    case RoleReveal = 'role_reveal';

    /** الشات الحي — الكل يتكلم بنفس الوقت، والأحداث تنفجر في وسطه. */
    case Scene = 'scene';

    /** كل لاعب يدّعي ماذا حقّق — وأهدافه ما زالت سرّية عن غيره. */
    case Claims = 'claims';

    /** تنكشف الأهداف والادّعاءات معاً، فيعترض من يشك. */
    case Challenge = 'challenge';

    /** تصويت على اعتراض واحد في كل مرة. */
    case Verdict = 'verdict';

    case Scoreboard = 'scoreboard';

    /** نهاية المباراة: جوائز التصويت الحر. */
    case Awards = 'awards';

    /** المدة الثابتة للمرحلة، أو null إن كانت من شروط اللعبة. */
    public function duration(): ?int
    {
        return match ($this) {
            self::Scene => null,
            self::RoleReveal => (int) config('mashhad.phases.role_reveal'),
            self::Claims => (int) config('mashhad.phases.claims'),
            self::Challenge => (int) config('mashhad.phases.challenge'),
            self::Verdict => (int) config('mashhad.phases.verdict'),
            self::Scoreboard => (int) config('mashhad.phases.scoreboard'),
            self::Awards => (int) config('mashhad.phases.awards'),
        };
    }
}
