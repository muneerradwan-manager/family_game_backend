<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'category', 'difficulty', 'prompt', 'answer',
    'distractors', 'explanation', 'source', 'fingerprint',
])]
class HadafQuestion extends Model
{
    protected function casts(): array
    {
        return ['distractors' => 'array'];
    }
}
