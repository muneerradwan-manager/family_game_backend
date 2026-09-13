<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'photo_url', 'owner_id', 'invite_code', 'active_game_id'])]
class Channel extends Model
{
    use HasUuids;

    public const MAX_MEMBERS = 50;

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'channel_members')
            ->withPivot(['role', 'joined_at'])
            ->orderByPivot('joined_at');
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }

    public function activeGame(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'active_game_id');
    }

    public function isOwner(User $user): bool
    {
        return $this->owner_id === $user->id;
    }

    /**
     * رمز دعوة من 6 خانات بلا حروف ملتبسة — يُقرأ صوتياً بلا خطأ.
     */
    public static function generateInviteCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;

        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, $max)];
            }
        } while (static::where('invite_code', $code)->exists());

        return $code;
    }
}
