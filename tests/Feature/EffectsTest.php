<?php

use App\Games\Effects;
use App\Jobs\AdvanceHarfPhase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Log::spy();
});

it('يجدول مؤقّت المرحلة حتى لو سقط أثر جانبي آخر', function () {
    $ran = false;

    $effects = makeEffects();
    $effects->scheduleTimeout(7, 10);
    $effects->defer(fn () => throw new RuntimeException('انقطع الاتصال بالقاعدة'));
    $effects->defer(function () use (&$ran) {
        $ran = true;
    });
    $effects->emit('phase_changed', ['phase' => 'writing']);

    $effects->flush('game-1', 'channel-1');

    // هذا هو المهم: الجولة تكمل. عطل البثّ يعني شاشة لا تتحدّث، لا لعبة ميتة.
    Queue::assertPushed(
        AdvanceHarfPhase::class,
        fn (AdvanceHarfPhase $job) => $job->gameId === 'game-1' && $job->seq === 7,
    );

    expect($ran)->toBeTrue();
});

it('لا يعيد جدولة نفس المؤقّت مرتين بعد التفريغ', function () {
    $effects = makeEffects();
    $effects->scheduleTimeout(3, 5);
    $effects->flush('game-1');
    $effects->flush('game-1');

    Queue::assertPushed(AdvanceHarfPhase::class, 1);
});

it('لا يجدول شيئاً حين لا تطلب المرحلة مؤقّتاً', function () {
    makeEffects()->flush('game-1');

    Queue::assertNothingPushed();
});

/** مصنع مهمة المؤقّت يختلف من لعبة لأخرى؛ هنا نفحص السلوك المشترك بمهمة الحروف. */
function makeEffects(): Effects
{
    return new Effects(fn (string $gameId, int $seq) => new AdvanceHarfPhase($gameId, $seq));
}
