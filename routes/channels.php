<?php

use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| صلاحيات القنوات الحيّة
|--------------------------------------------------------------------------
|
| كل جلسة لعبة = Room واحدة يشترك بها أعضاء القناة. المصافحة تُوثَّق بنفس
| توكن الـ JWT المستخدم في REST، والاشتراك لا يُقبل إلا لعضو في القناة.
|
*/

$isMember = static fn (string $userId, ?string $channelId): bool => $channelId !== null
    && DB::table('channel_members')
        ->where('channel_id', $channelId)
        ->where('user_id', $userId)
        ->exists();

Broadcast::channel('game.{gameId}', static function (User $user, string $gameId) use ($isMember): bool {
    return $isMember($user->id, Game::whereKey($gameId)->value('channel_id'));
});

Broadcast::channel('channel.{channelId}', static function (User $user, string $channelId) use ($isMember): bool {
    return $isMember($user->id, $channelId);
});

Broadcast::channel('user.{userId}', static function (User $user, string $userId): bool {
    return $user->id === $userId;
});
