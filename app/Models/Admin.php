<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * مشرف لوحة الإدارة — منفصل تماماً عن مستخدمي التطبيق.
 */
#[Fillable(['name', 'email', 'password', 'is_super', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class Admin extends Authenticatable
{
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_super' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }
}
