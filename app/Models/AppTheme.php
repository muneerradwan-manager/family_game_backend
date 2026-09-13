<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * ثيم من ثيمات التطبيق.
 *
 * الألوان مخزّنة كما يرسمها Flutter (نص #RRGGBB) لا مشتقّة من لون واحد:
 * كل ثيم شخصية بصرية كاملة، والاشتقاق التلقائي يطمس الفرق بينها.
 */
#[Fillable(['key', 'name', 'tagline', 'is_dark', 'colors', 'is_active', 'is_default', 'sort_order'])]
class AppTheme extends Model
{
    /** مفاتيح الألوان بالترتيب الذي يعرضه المحرّر. */
    public const COLOR_KEYS = [
        'primary' => 'اللون الأساسي',
        'onPrimary' => 'النص فوق الأساسي',
        'secondary' => 'اللون الثانوي',
        'accent' => 'لون التنبيه',
        'background' => 'الخلفية',
        'surface' => 'البطاقات',
        'surfaceAlt' => 'البطاقات البديلة',
        'textPrimary' => 'النص',
        'textMuted' => 'النص الخافت',
        'outline' => 'الحدود',
        'gradientStart' => 'بداية التدرّج',
        'gradientEnd' => 'نهاية التدرّج',
    ];

    protected function casts(): array
    {
        return [
            'colors' => 'array',
            'is_dark' => 'boolean',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'tagline' => $this->tagline,
            'isDark' => $this->is_dark,
            'colors' => $this->colors,
            'isDefault' => $this->is_default,
            'sortOrder' => $this->sort_order,
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
