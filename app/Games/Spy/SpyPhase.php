<?php

namespace App\Games\Spy;

/**
 * مراحل لعبة الجاسوس.
 *
 * لكل مرحلة مؤقّت سيرفر ونتيجة افتراضية عند انتهاء الوقت: صاحب الدور لم
 * يسأل؟ دوره يُسحب. المسؤول لم يجب؟ تُسجَّل "ما جاوب". ما صوّت أحد؟ لا
 * أغلبية ولا إخراج. فلا توجد حالة يستطيع فيها لاعب واحد تجميد اللعبة.
 */
enum SpyPhase: string
{
    /** كل لاعب يرى كلمته — أو أنه الجاسوس — على جهازه وحده. */
    case RoleReveal = 'role_reveal';

    case Asking = 'asking';
    case Answering = 'answering';
    case Voting = 'voting';
    case VoteResult = 'vote_result';

    /** انكشف الجاسوس: فرصة أخيرة يخمّن فيها الكلمة. */
    case SpyGuess = 'spy_guess';

    /**
     * المدة الثابتة للمرحلة، أو null إن كانت من شروط اللعبة (مدة الدور).
     */
    public function duration(): ?int
    {
        return match ($this) {
            self::Asking, self::Answering => null,
            self::RoleReveal => (int) config('spy.phases.role_reveal'),
            self::Voting => (int) config('spy.phases.voting'),
            self::VoteResult => (int) config('spy.phases.vote_result'),
            self::SpyGuess => (int) config('spy.phases.spy_guess'),
        };
    }
}
