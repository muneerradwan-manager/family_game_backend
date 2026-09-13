<?php

namespace App\Jobs;

use App\Games\Harf\HarfEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * مؤقّت السيرفر لمرحلة واحدة.
 *
 * لا ينتظر السيرفر أي جهاز ليعلن انتهاء وقته: كل مرحلة تُجدول مهمة مؤجّلة
 * بمدّتها. يحمل الختم seq الذي أُطلقت عنده؛ فإن تقدّمت المرحلة قبل موعده
 * (لاعب سحب الحرف، أو ضُغط ستوب) صار الختم قديماً وتنتهي المهمة بلا أثر.
 */
class AdvanceHarfPhase implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $gameId,
        public readonly int $seq,
    ) {}

    public function handle(HarfEngine $engine): void
    {
        $engine->handleTimeout($this->gameId, $this->seq);
    }
}
