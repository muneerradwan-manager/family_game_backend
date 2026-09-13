<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'channel_id', 'game_type', 'status', 'config',
    'started_by', 'started_at', 'finished_at', 'result',
])]
class Game extends Model
{
    use HasUuids;

    public const STATUS_LOBBY = 'lobby';

    public const STATUS_PLAYING = 'playing';

    public const STATUS_FINISHED = 'finished';

    public const STATUS_ABANDONED = 'abandoned';

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function players(): HasMany
    {
        return $this->hasMany(GamePlayer::class)->orderBy('join_order');
    }

    public function harfRounds(): HasMany
    {
        return $this->hasMany(HarfRound::class)->orderBy('round_no');
    }

    public function isLive(): bool
    {
        return in_array($this->status, [self::STATUS_LOBBY, self::STATUS_PLAYING], true);
    }
}
