<?php

namespace App\Jobs;

use App\Games\Mashhad\MashhadEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * مؤقّت السيرفر لمرحلة واحدة من لعبة المشهد.
 *
 * داخل المشهد يضرب أكثر من مرة: مرة عند كل حدث مفاجئ ومرة عند النهاية.
 * ويحمل الختم seq الذي أُطلق عنده، فأي ضربة تجاوزها الزمن تنتهي بلا أثر.
 */
class AdvanceMashhadPhase implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $gameId,
        public readonly int $seq,
    ) {}

    public function handle(MashhadEngine $engine): void
    {
        $engine->handleTimeout($this->gameId, $this->seq);
    }
}
