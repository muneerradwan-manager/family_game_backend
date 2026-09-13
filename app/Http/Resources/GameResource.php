<?php

namespace App\Http\Resources;

use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Game */
class GameResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channelId' => $this->channel_id,
            'gameType' => $this->game_type,
            'status' => $this->status,
            'config' => $this->config,
            'startedBy' => $this->started_by,
            'starter' => new UserResource($this->whenLoaded('starter')),
            'playerCount' => $this->whenCounted('players'),
            'result' => $this->result,
            'createdAt' => $this->created_at?->toIso8601String(),
            'startedAt' => $this->started_at?->toIso8601String(),
            'finishedAt' => $this->finished_at?->toIso8601String(),
        ];
    }
}
