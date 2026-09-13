<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'fullName' => ['sometimes', 'string', 'min:3', 'max:80'],
            'username' => [
                'sometimes', 'string', 'min:3', 'max:20', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
            'gender' => ['sometimes', Rule::in([User::GENDER_MALE, User::GENDER_FEMALE])],
            'avatarId' => ['nullable', 'string', 'max:40'],
            'photoUrl' => ['nullable', 'url', 'max:255'],
            'recoveryEmail' => ['nullable', 'email', 'max:255'],
        ]);

        $user->fill(array_filter([
            'full_name' => $data['fullName'] ?? null,
            'username' => $data['username'] ?? null,
            'gender' => $data['gender'] ?? null,
        ], fn ($value) => $value !== null));

        // هذه الحقول يجوز تفريغها عمداً، فلا تمر بـ array_filter.
        foreach (['avatarId' => 'avatar_id', 'photoUrl' => 'photo_url', 'recoveryEmail' => 'recovery_email'] as $input => $column) {
            if ($request->exists($input)) {
                $user->{$column} = $data[$input] ?? null;
            }
        }

        $user->save();

        return response()->json(['user' => (new UserResource($user))->asSelf()]);
    }

    /** فحص لحظي أثناء الكتابة في شاشة إنشاء البروفايل. */
    public function checkUsername(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:20'],
        ]);

        $valid = preg_match('/^[a-z0-9_]{3,20}$/', $data['username']) === 1;
        $taken = $valid && User::where('username', $data['username'])->exists();

        return response()->json([
            'valid' => $valid,
            'available' => $valid && ! $taken,
            'message' => match (true) {
                ! $valid => 'أحرف إنجليزية صغيرة وأرقام و_ فقط، من 3 إلى 20 خانة.',
                $taken => 'اسم المستخدم محجوز.',
                default => 'متاح.',
            },
        ]);
    }

    /** البحث بالاسم — أساس الدعوة المباشرة لاحقاً. */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:3', 'max:20'],
        ]);

        $users = User::where('username', 'like', $data['q'].'%')
            ->where('id', '!=', $request->user()->id)
            ->limit(20)
            ->get();

        return response()->json(['users' => UserResource::collection($users)]);
    }

    /** توكن FCM للدعوات والتنبيهات — ليس أداة تزامن. */
    public function updateFcmToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fcmToken' => ['nullable', 'string', 'max:255'],
        ]);

        $request->user()->forceFill(['fcm_token' => $data['fcmToken'] ?? null])->save();

        return response()->json(['message' => 'تم التحديث.']);
    }
}
