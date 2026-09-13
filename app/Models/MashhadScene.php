<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'category', 'title', 'setup', 'roles', 'events', 'source'])]
class MashhadScene extends Model
{
    protected function casts(): array
    {
        return ['roles' => 'array', 'events' => 'array'];
    }
}
