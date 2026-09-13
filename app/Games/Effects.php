<?php

namespace App\Games;

use App\Events\ChannelEvent;
use App\Events\GameEvent;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * آثار جانبية مؤجّلة — مشتركة بين كل الألعاب.
 *
 * تعديل الحالة يجري تحت قفل حصري؛ والبثّ وكتابة القاعدة وجدولة المؤقّتات
 * عمليات شبكة لا يجوز أن تُحتجز تحت القفل. فتُجمَع هنا وتُنفَّذ بعد تحريره.
 *
 * مؤقّت المرحلة وحده يختلف من لعبة لأخرى (مهمة مؤجّلة لكل وحدة)، فيصل
 * كمصنع مهام لا كصنف مثبّت هنا.
 */
final class Effects
{
    /** @var array<int, array{0: string, 1: array<string, mixed>}> */
    private array $gameEvents = [];

    /** @var array<int, array{0: string, 1: array<string, mixed>}> */
    private array $channelEvents = [];

    /** @var array<int, Closure> */
    private array $deferred = [];

    /** @var array{seq: int, delay: int}|null */
    private ?array $timer = null;

    /** @var array<int, array{0: object, 1: int}> */
    private array $jobs = [];

    /**
     * @param  Closure(string $gameId, int $seq): object  $timerFactory
     *                                                                   مهمة تقدّم المرحلة الخاصة بهذه اللعبة.
     */
    public function __construct(private readonly Closure $timerFactory) {}

    /** @param array<string, mixed> $payload */
    public function emit(string $name, array $payload = []): void
    {
        $this->gameEvents[] = [$name, $payload];
    }

    /** @param array<string, mixed> $payload */
    public function emitToChannel(string $name, array $payload = []): void
    {
        $this->channelEvents[] = [$name, $payload];
    }

    public function defer(Closure $callback): void
    {
        $this->deferred[] = $callback;
    }

    /** مؤقّت المرحلة الحالية — آخر استدعاء هو الساري. */
    public function scheduleTimeout(int $seq, int $delaySeconds): void
    {
        $this->timer = ['seq' => $seq, 'delay' => $delaySeconds];
    }

    /** مهمة مؤجّلة إضافية (تنبيه، تذكير) — لا علاقة لها بتقدّم المراحل. */
    public function dispatchLater(object $job, int $delaySeconds): void
    {
        $this->jobs[] = [$job, $delaySeconds];
    }

    /**
     * الترتيب هنا مقصود: المؤقّت أولاً.
     *
     * المرحلة التالية يجب أن تُجدول حتى لو سقط كل ما بعدها. لو بثثنا أولاً
     * ثم فشل الاتصال بـ Reverb، لانتهت المهمة باستثناء قبل جدولة المؤقّت
     * وتجمّدت الجلسة على كل اللاعبين إلى الأبد. عطل البثّ يعني شاشة لا
     * تتحدّث لحظياً — لا لعبة ميتة.
     */
    public function flush(string $gameId, ?string $channelId = null): void
    {
        if ($this->timer !== null) {
            $job = ($this->timerFactory)($gameId, $this->timer['seq']);

            dispatch($job)->delay(now()->addSeconds($this->timer['delay']));
        }

        foreach ($this->jobs as [$job, $delay]) {
            $this->guard(
                fn () => dispatch($job)->delay(now()->addSeconds($delay)),
                'جدولة مهمة مؤجّلة',
            );
        }

        foreach ($this->deferred as $callback) {
            $this->guard($callback, 'ترحيل بيانات الجولة');
        }

        foreach ($this->gameEvents as [$name, $payload]) {
            $this->guard(fn () => GameEvent::dispatch($gameId, $name, $payload), "بثّ الحدث {$name}");
        }

        if ($channelId !== null) {
            foreach ($this->channelEvents as [$name, $payload]) {
                $this->guard(
                    fn () => ChannelEvent::dispatch($channelId, $name, $payload),
                    "بثّ حدث القناة {$name}",
                );
            }
        }

        $this->gameEvents = [];
        $this->channelEvents = [];
        $this->deferred = [];
        $this->jobs = [];
        $this->timer = null;
    }

    /**
     * فشل أثر جانبي واحد لا يُسقط بقيتها: كل واحد منها مستقل عن الآخر،
     * وسقوط أحدها لا يبرّر إيقاف اللعبة.
     */
    private function guard(Closure $callback, string $label): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            Log::error("فشل {$label}", ['error' => $exception->getMessage()]);
        }
    }
}
