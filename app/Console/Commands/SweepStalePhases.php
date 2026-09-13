<?php

namespace App\Console\Commands;

use App\Games\Hadaf\HadafEngine;
use App\Games\Harf\HarfEngine;
use App\Games\Mashhad\MashhadEngine;
use App\Games\Spy\SpyEngine;
use Illuminate\Console\Command;

/**
 * شبكة أمان لمؤقّتات كل الألعاب.
 *
 * الإيقاع الطبيعي مهام مؤجّلة في Redis تنجو من إعادة تشغيل السيرفر، لكن قد
 * يسقط عامل الطابور أو تُمسح مهمة. هذا الأمر يمرّ على الجلسات الحيّة ويحرّك
 * ما تجاوز موعد مرحلته. لا تعتمد عليه في الإيقاع — فقط في التعافي.
 *
 * لعبة جديدة = سطر واحد هنا.
 */
class SweepStalePhases extends Command
{
    protected $signature = 'games:sweep';

    protected $description = 'يحرّك أي جولة تجاوزت موعد مرحلتها ولم يصلها مؤقّتها (بعد إعادة تشغيل أو سقوط عامل طابور).';

    public function handle(
        HarfEngine $harf,
        SpyEngine $spy,
        HadafEngine $hadaf,
        MashhadEngine $mashhad,
    ): int {
        $moved = $harf->sweepStalePhases()
            + $spy->sweepStalePhases()
            + $hadaf->sweepStalePhases()
            + $mashhad->sweepStalePhases();

        if ($moved > 0) {
            $this->info("تم تحريك {$moved} جلسة متوقفة.");
        }

        return self::SUCCESS;
    }
}
