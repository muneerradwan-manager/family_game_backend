<?php

namespace App\Games\Harf;

/**
 * مراحل الجولة. لكل مرحلة مؤقّت سيرفر ونتيجة افتراضية عند انتهاء الوقت،
 * فلا توجد حالة يستطيع فيها لاعب أو انقطاع نت تجميد اللعبة على الآخرين.
 */
enum HarfPhase: string
{
    case AwaitingLetter = 'awaiting_letter';
    case RevealLetter = 'reveal_letter';
    case Writing = 'writing';
    case Grace = 'grace';
    case Reveal = 'reveal';
    case Objection = 'objection';
    case Voting = 'voting';
    case Scoreboard = 'scoreboard';
    case Tiebreak = 'tiebreak';

    /** المدة الثابتة للمرحلة، أو null إن كانت من شروط اللعبة. */
    public function duration(): ?int
    {
        return match ($this) {
            self::Writing => null,
            self::AwaitingLetter => (int) config('harf.phases.awaiting_letter'),
            self::RevealLetter => (int) config('harf.phases.reveal_letter'),
            self::Grace => (int) config('harf.phases.grace'),
            self::Reveal => (int) config('harf.phases.reveal'),
            self::Objection => (int) config('harf.phases.objection'),
            self::Voting => (int) config('harf.phases.voting'),
            self::Scoreboard => (int) config('harf.phases.scoreboard'),
            self::Tiebreak => (int) config('harf.phases.tiebreak'),
        };
    }
}
