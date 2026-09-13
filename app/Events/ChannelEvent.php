<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * أحداث على مستوى القناة (لا الجلسة): فتح غرفة، انتهاء لعبة، تغيّر الأعضاء.
 * هي ما يجعل بطاقة "لعبة جارية" في شاشة القناة تتحدّث بلا تحديث يدوي.
 */
class ChannelEvent implements ShouldBroadcastNow
{
    use Dispatchable;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $channelId,
        public readonly string $name,
        public readonly array $payload = [],
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('channel.'.$this->channelId)];
    }

    public function broadcastAs(): string
    {
        return $this->name;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload + ['serverTime' => (int) (microtime(true) * 1000)];
    }
}
