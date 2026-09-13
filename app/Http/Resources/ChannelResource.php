<?php

namespace App\Http\Resources;

use App\Models\Channel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Channel */
class ChannelResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'photoUrl' => $this->photo_url,
            'ownerId' => $this->owner_id,
            'isOwner' => $viewer !== null && $this->owner_id === $viewer->id,
            'inviteCode' => $this->invite_code,
            'memberCount' => $this->whenCounted('members'),
            'members' => MemberResource::collection($this->whenLoaded('members')),
            'activeGame' => new GameResource($this->whenLoaded('activeGame')),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
