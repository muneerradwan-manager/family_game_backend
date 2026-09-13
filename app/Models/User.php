<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

#[Fillable([
    'phone', 'password_hash', 'full_name', 'username',
    'photo_url', 'avatar_id', 'gender', 'recovery_email', 'fcm_token',
])]
#[Hidden(['password_hash', 'fcm_token'])]
class User extends Authenticatable
{
    use HasUuids;

    public const GENDER_MALE = 'male';

    public const GENDER_FEMALE = 'female';

    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
        ];
    }

    /**
     * المخطط يسمّي العمود password_hash لا password — نوجّه Laravel إليه.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'channel_members')
            ->withPivot(['role', 'joined_at']);
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    /**
     * صيغة الخطاب في الواجهة تعتمد الجنس: دورك تسحب / دورك تسحبي.
     */
    public function isFemale(): bool
    {
        return $this->gender === self::GENDER_FEMALE;
    }
}
