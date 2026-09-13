<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * إعلان يظهر داخل التطبيق — للجميع أو لأعضاء قناة بعينها.
 */
#[Fillable([
    'title', 'body', 'level', 'audience', 'channel_id', 'starts_at',
    'ends_at', 'is_active', 'is_dismissible', 'push_sent_at', 'created_by',
])]
class Announcement extends Model
{
    public const LEVELS = [
        'info' => 'معلومة',
        'success' => 'خبر حلو',
        'warning' => 'تنبيه',
        'danger' => 'مهم جداً',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'push_sent_at' => 'datetime',
            'is_active' => 'boolean',
            'is_dismissible' => 'boolean',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * الإعلانات السارية لهذا المستخدم الآن.
     *
     * نافذة الوقت تُفحص هنا لا على الجهاز: ساعة الجهاز قد تكون خاطئة، وإعلان
     * انتهى يجب ألا يظهر لأن هاتف أحدهم متأخر يوماً.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $now = now();

        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->where(fn (Builder $q) => $q
                ->where('audience', 'all')
                ->orWhere(fn (Builder $channel) => $channel
                    ->where('audience', 'channel')
                    ->whereIn('channel_id', $user->channels()->select('channels.id'))));
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'level' => $this->level,
            'audience' => $this->audience,
            'channelId' => $this->channel_id,
            'dismissible' => $this->is_dismissible,
            'startsAt' => $this->starts_at?->toIso8601String(),
            'endsAt' => $this->ends_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
