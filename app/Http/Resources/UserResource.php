<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /** يُفعَّل في ردود التسجيل والدخول حيث لا يكون الطلب موثّقاً بعد. */
    private bool $asSelf = false;

    public function asSelf(): static
    {
        $this->asSelf = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // داخل الألعاب يظهر اسم المستخدم والصورة فقط؛ الهاتف وإيميل الاستعادة
        // لصاحب الحساب وحده.
        $isSelf = $this->asSelf || $request->user()?->id === $this->id;

        return array_filter([
            'id' => $this->id,
            'username' => $this->username,
            'fullName' => $this->full_name,
            'photoUrl' => $this->photo_url,
            'avatarId' => $this->avatar_id,
            'gender' => $this->gender,
            'phone' => $isSelf ? $this->phone : null,
            'recoveryEmail' => $isSelf ? $this->recovery_email : null,
            'createdAt' => $this->created_at?->toIso8601String(),
        ], fn ($value) => $value !== null);
    }
}
