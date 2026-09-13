<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class MemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'fullName' => $this->full_name,
            'photoUrl' => $this->photo_url,
            'avatarId' => $this->avatar_id,
            'gender' => $this->gender,
            'role' => $this->pivot?->role,
            'joinedAt' => $this->pivot?->joined_at,
        ];
    }
}
