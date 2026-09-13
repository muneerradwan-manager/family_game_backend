<?php

namespace App\Games;

use App\Events\ChannelEvent;
use App\Events\GameEvent;
use App\Games\State\GameStateStore;
use App\Models\Channel;
use App\Models\Game;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * إنهاء جلسة من لوحة الإدارة.
 *
 * الترتيب مقصود: الحالة الحيّة تُمسح أولاً. أي مؤقّت مرحلة يصل بعدها يجد
 * الحالة فارغة فينتهي بلا أثر (محرّكات الألعاب كلها تتجاهل حالة غائبة)،
 * فلا تعود جلسة أنهاها المشرف للحياة لأن مهمة كانت في الطابور.
 */
class GameTerminator
{
    public const DEFAULT_MESSAGE = 'أنهت الإدارة هذه اللعبة.';

    public function __construct(
        private readonly GameModuleRegistry $registry,
        private readonly GameStateStore $store,
    ) {}

    /** @return bool false إن كانت الجلسة منتهية أصلاً */
    public function terminate(Game $game, string $message = self::DEFAULT_MESSAGE): bool
    {
        if (! $game->isLive()) {
            return false;
        }

        if ($this->registry->has($game->game_type)) {
            $this->registry->get($game->game_type)->abandon($game);
        } else {
            $this->store->forget($game->id);
        }

        DB::transaction(function () use ($game, $message) {
            $game->forceFill([
                'status' => Game::STATUS_ABANDONED,
                'finished_at' => now(),
                'result' => $game->result ?? [
                    'endedByAdmin' => true,
                    'reason' => 'admin_terminated',
                    'message' => $message,
                ],
            ])->save();

            // تحرير قفل «لعبة نشطة واحدة» — بشرط أنه ما زال يشير لهذه اللعبة.
            Channel::where('id', $game->channel_id)
                ->where('active_game_id', $game->id)
                ->update(['active_game_id' => null]);
        });

        // عطل البثّ لا يُبطل الإنهاء: القاعدة صارت صحيحة، والأجهزة ستلتقط
        // الحالة عند أول لقطة.
        try {
            GameEvent::dispatch($game->id, 'game_abandoned', ['byAdmin' => true, 'message' => $message]);
            ChannelEvent::dispatch($game->channel_id, 'active_game_changed', ['activeGame' => null]);
        } catch (Throwable $exception) {
            Log::error('فشل بثّ إنهاء الجلسة', ['game' => $game->id, 'error' => $exception->getMessage()]);
        }

        return true;
    }
}
