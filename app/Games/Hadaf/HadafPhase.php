<?php

namespace App\Games\Hadaf;

/**
 * مراحل جولة لعبة الهدف.
 *
 * `Ready` ليست زينة: بدونها يرى من فتح الشاشة أولاً السؤالَ قبل غيره بجزء من
 * الثانية، وفي لعبة تُحسم بالمللي ثانية هذا فارق حاسم. العدّ التنازلي يوحّد
 * لحظة الانطلاق للجميع.
 */
enum HadafPhase: string
{
    case Ready = 'ready';
    case Question = 'question';
    case Reveal = 'reveal';
    case Scoreboard = 'scoreboard';
    case Tiebreak = 'tiebreak';

    /** المدة الثابتة للمرحلة، أو null إن كانت من شروط اللعبة. */
    public function duration(): ?int
    {
        return match ($this) {
            self::Question => null,
            self::Ready => (int) config('hadaf.phases.ready'),
            self::Reveal => (int) config('hadaf.phases.reveal'),
            self::Scoreboard => (int) config('hadaf.phases.scoreboard'),
            self::Tiebreak => (int) config('hadaf.phases.tiebreak'),
        };
    }
}
