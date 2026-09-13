<?php

namespace App\Notifications;

use App\Models\Channel;
use Illuminate\Support\Facades\Log;

/**
 * البديل الافتراضي في التطوير وحين تكون FCM مطفأة.
 *
 * غياب الإعداد لا يجوز أن يعطّل فتح غرفة: نسجّل ما كان سيُرسَل ونكمل.
 */
class LogPushNotifier implements PushNotifier
{
    public function send(array $tokens, string $title, string $body, array $data = []): void
    {
        Log::info('[push] '.$title.' — '.$body, [
            'recipients' => count(array_filter($tokens)),
            'data' => $data,
        ]);
    }

    public function toChannelMembers(Channel $channel, array $except, string $title, string $body, array $data = []): void
    {
        $tokens = $channel->members()
            ->whereNotNull('fcm_token')
            ->whereNotIn('users.id', $except)
            ->pluck('fcm_token')
            ->all();

        $this->send($tokens, $title, $body, $data);
    }
}
