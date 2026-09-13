<?php

namespace App\Jobs;

use App\Games\State\GameStateStore;
use App\Models\Game;
use App\Models\User;
use App\Notifications\PushNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * تذكير صاحب الغرفة بعد خمس دقائق بلا بدء.
 *
 * غرفة مفتوحة ومنسيّة تحجز القناة كلها (لعبة نشطة واحدة)، وصاحبها غالباً
 * خرج من التطبيق ينتظر الناس. تذكير واحد لا أكثر: هذا أحد الاستخدامات
 * الثلاثة المسموح بها للإشعارات.
 */
class RemindLobbyHost implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $gameId) {}

    public function handle(GameStateStore $store, PushNotifier $push): void
    {
        $game = Game::find($this->gameId);

        // بلّشت أو انلغت: لا داعي للتذكير.
        if (! $game || $game->status !== Game::STATUS_LOBBY) {
            return;
        }

        $state = $store->get($this->gameId);

        if ($state === null) {
            return;
        }

        $host = User::find($state['hostId']);

        if ($host === null || blank($host->fcm_token)) {
            return;
        }

        $waiting = count(array_filter(
            $state['players'],
            fn (array $player) => ! $player['isSpectator'] && $player['leftAtRound'] === null,
        ));

        $push->send(
            [$host->fcm_token],
            'غرفتك لسا مفتوحة',
            $waiting >= (int) config('harf.limits.min_players')
                ? "صار عندك {$waiting} لاعبين — بتقدر تبلّش!"
                : "لسا {$waiting} باللوبي — ذكّر الباقيين.",
            ['type' => 'lobby_reminder', 'gameId' => $this->gameId, 'channelId' => $game->channel_id],
        );
    }
}
