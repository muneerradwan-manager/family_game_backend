<?php

namespace App\Jobs;

use App\Games\Harf\HarfPhase;
use App\Games\State\GameStateStore;
use App\Models\Game;
use App\Models\User;
use App\Notifications\PushNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * تنبيه صاحب الدور أن عليه سحب الحرف.
 *
 * يُجدول متأخراً بضع ثوانٍ عن بداية الجولة ثم يتحقق أن المرحلة ما زالت
 * `awaiting_letter` لنفس الجولة. من كان يتابع اللعبة يكون قد سحب قبل ذلك،
 * فلا يصله إشعار — والإشعار يصل فقط لمن غاب فعلاً. هذه أدق طريقة لتمييز
 * "التطبيق مغلق" من السيرفر بلا افتراضات عن حالة الجهاز.
 */
class NotifyDrawerTurn implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $gameId,
        public readonly int $roundNo,
    ) {}

    public function handle(GameStateStore $store, PushNotifier $push): void
    {
        $state = $store->get($this->gameId);

        if ($state === null || $state['round'] === null) {
            return;
        }

        $round = $state['round'];

        // سحب الحرف أو تقدّمت الجولة: لا داعي للتنبيه.
        if ($round['no'] !== $this->roundNo || $round['phase'] !== HarfPhase::AwaitingLetter->value) {
            return;
        }

        $drawer = User::find($round['drawerUserId']);

        if ($drawer === null || blank($drawer->fcm_token)) {
            return;
        }

        $game = Game::find($this->gameId);

        $push->send(
            [$drawer->fcm_token],
            'دورك!',
            // صيغة الخطاب تتبع الجنس.
            $drawer->isFemale() ? 'دورك تسحبي الحرف' : 'دورك تسحب الحرف',
            [
                'type' => 'your_turn',
                'gameId' => $this->gameId,
                'channelId' => (string) ($game?->channel_id ?? ''),
            ],
        );
    }
}
