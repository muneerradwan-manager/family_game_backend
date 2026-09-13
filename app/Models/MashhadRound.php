<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'game_id', 'scene_no', 'scene_key', 'title', 'cast',
    'transcript', 'events', 'claims', 'scores', 'created_at',
])]
class MashhadRound extends Model
{
    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = null;

    protected function casts(): array
    {
        return [
            'cast' => 'array',
            'transcript' => 'array',
            'events' => 'array',
            'claims' => 'array',
            'scores' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
