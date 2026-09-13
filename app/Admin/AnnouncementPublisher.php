<?php

namespace App\Admin;

use App\Models\Announcement;
use App\Models\User;
use App\Notifications\PushNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * إرسال إعلان كإشعار.
 *
 * الإعلان يظهر داخل التطبيق دائماً؛ الإشعار إضافة اختيارية للمهم منه.
 * والإرسال على دفعات: إشعار لكل المستخدمين بطلب واحد يعني قائمة توكنات بلا
 * سقف، وFCM يرفض الطلب كله إن تجاوزها.
 */
class AnnouncementPublisher
{
    private const CHUNK = 500;

    public function __construct(private readonly PushNotifier $push) {}

    /** @return int عدد الأجهزة التي أُرسل لها */
    public function push(Announcement $announcement): int
    {
        $query = User::query()
            ->whereNull('banned_at')
            ->whereNotNull('fcm_token');

        if ($announcement->audience === 'channel' && $announcement->channel_id !== null) {
            $query->whereIn('id', DB::table('channel_members')
                ->where('channel_id', $announcement->channel_id)
                ->select('user_id'));
        }

        $sent = 0;

        $query->select(['id', 'fcm_token'])->chunkById(self::CHUNK, function ($users) use ($announcement, &$sent) {
            $tokens = $users->pluck('fcm_token')->filter()->values()->all();

            if ($tokens === []) {
                return;
            }

            $this->push->send(
                $tokens,
                $announcement->title,
                Str::limit($announcement->body, 140),
                ['type' => 'announcement', 'announcementId' => (string) $announcement->id],
            );

            $sent += count($tokens);
        });

        $announcement->forceFill(['push_sent_at' => now()])->save();

        return $sent;
    }
}
