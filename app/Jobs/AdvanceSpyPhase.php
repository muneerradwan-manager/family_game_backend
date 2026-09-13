<?php

namespace App\Jobs;

use App\Games\Spy\SpyEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * مؤقّت السيرفر لمرحلة واحدة من لعبة الجاسوس.
 *
 * لا ينتظر السيرفر أي جهاز ليعلن انتهاء وقته: صاحب الدور لم يسأل؟ دوره
 * يُسحب. المسؤول لم يجب؟ تُسجَّل "ما جاوب" وتكمل الجولة. يحمل الختم seq
 * الذي أُطلقت عنده؛ فإن تقدّمت المرحلة قبل موعده صار الختم قديماً وتنتهي
 * المهمة بلا أثر.
 */
class AdvanceSpyPhase implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $gameId,
        public readonly int $seq,
    ) {}

    public function handle(SpyEngine $engine): void
    {
        $engine->handleTimeout($this->gameId, $this->seq);
    }
}
