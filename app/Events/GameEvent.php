<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * حدث واحد للقناة الحيّة للجلسة، باسم ديناميكي.
 *
 * كل أحداث اللعبة (phase_changed، letter_drawn، stop_pressed ...) تمرّ من هنا:
 * الاسم يحدّده مُطلق الحدث، والحمولة تصل كما هي. يمنع هذا تكاثر عشرات
 * أصناف الأحداث المتطابقة، ويبقي عقد الـ WebSocket في مكان واحد.
 *
 * ShouldBroadcastNow لا ShouldBroadcast: التزامن اللحظي لا يحتمل انتظار طابور.
 */
class GameEvent implements ShouldBroadcastNow
{
    use Dispatchable;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $gameId,
        public readonly string $name,
        public readonly array $payload = [],
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('game.'.$this->gameId)];
    }

    public function broadcastAs(): string
    {
        return $this->name;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        // ختم السيرفر يرافق كل حدث: الجهاز يحسب عدّاداته من توقيت السيرفر لا توقيته.
        return $this->payload + ['serverTime' => (int) (microtime(true) * 1000)];
    }
}
