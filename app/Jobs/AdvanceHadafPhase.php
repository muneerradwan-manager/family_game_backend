<?php

namespace App\Jobs;

use App\Games\Hadaf\HadafEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * مؤقّت السيرفر لمرحلة واحدة من لعبة الهدف.
 *
 * يحمل الختم seq الذي أُطلق عنده؛ فإن أجاب الجميع قبل موعده (وأُقفل السؤال
 * مبكراً) صار الختم قديماً وتنتهي المهمة بلا أثر.
 */
class AdvanceHadafPhase implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $gameId,
        public readonly int $seq,
    ) {}

    public function handle(HadafEngine $engine): void
    {
        $engine->handleTimeout($this->gameId, $this->seq);
    }
}
