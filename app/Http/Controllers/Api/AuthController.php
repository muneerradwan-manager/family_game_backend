<?php

namespace App\Http\Controllers\Api;

use App\Auth\JwtService;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * التسجيل والدخول: رقم هاتف + كلمة سر، بلا تحقق SMS في النسخة الأولى.
 *
 * الرقم يُخزَّن دائماً بصيغة E.164، وكلمة السر Hash، ولا شيء منهما يُعاد
 * للجهاز. الإيميل الاختياري هو وسيلة الاستعادة الوحيدة حالياً.
 */
class AuthController extends Controller
{
    public function __construct(private readonly JwtService $jwt) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:25'],
            'password' => ['required', 'string', 'min:6', 'max:72'],
            'fullName' => ['required', 'string', 'min:3', 'max:80'],
            // اسم المستخدم بأحرف إنجليزية وأرقام و_ فقط: يتفادى التباس التشكيل
            // والتشابه البصري في العربية، وهو معرّف البحث والدعوات.
            'username' => ['required', 'string', 'min:3', 'max:20', 'regex:/^[a-z0-9_]+$/', 'unique:users,username'],
            'gender' => ['required', Rule::in([User::GENDER_MALE, User::GENDER_FEMALE])],
            'avatarId' => ['nullable', 'string', 'max:40'],
            'photoUrl' => ['nullable', 'url', 'max:255'],
            'recoveryEmail' => ['nullable', 'email', 'max:255'],
        ]);

        $phone = $this->requireE164($data['phone']);

        if (User::where('phone', $phone)->exists()) {
            throw ValidationException::withMessages([
                'phone' => 'هذا الرقم مسجّل مسبقاً. جرّب تسجيل الدخول.',
            ]);
        }

        $user = User::create([
            'phone' => $phone,
            'password_hash' => $data['password'],
            'full_name' => $data['fullName'],
            'username' => $data['username'],
            'gender' => $data['gender'],
            'avatar_id' => $data['avatarId'] ?? null,
            'photo_url' => $data['photoUrl'] ?? null,
            'recovery_email' => $data['recoveryEmail'] ?? null,
        ]);

        return response()->json([
            'user' => (new UserResource($user))->asSelf(),
            'tokens' => $this->jwt->issueTokenPair($user),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:25'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('phone', $this->requireE164($data['phone']))->first();

        // رسالة واحدة للحالتين: لا نكشف أي أرقام مسجّلة عندنا.
        if (! $user || ! Hash::check($data['password'], $user->password_hash)) {
            throw ValidationException::withMessages([
                'phone' => 'الرقم أو كلمة السر غير صحيحة.',
            ]);
        }

        return response()->json([
            'user' => (new UserResource($user))->asSelf(),
            'tokens' => $this->jwt->issueTokenPair($user),
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate([
            'refreshToken' => ['required', 'string'],
        ]);

        $tokens = $this->jwt->refresh($data['refreshToken']);

        if ($tokens === null) {
            return response()->json(['message' => 'انتهت الجلسة — سجّل دخولك من جديد.'], 401);
        }

        return response()->json(['tokens' => $tokens]);
    }

    public function logout(Request $request): JsonResponse
    {
        $refreshToken = $request->input('refreshToken');

        if (is_string($refreshToken) && $refreshToken !== '') {
            $this->jwt->revokeRefreshToken($refreshToken);
        }

        return response()->json(['message' => 'تم تسجيل الخروج.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => new UserResource($request->user())]);
    }

    private function requireE164(string $input): string
    {
        $phone = PhoneNumber::toE164($input);

        if ($phone === null) {
            throw ValidationException::withMessages([
                'phone' => 'رقم الهاتف غير صالح.',
            ]);
        }

        return $phone;
    }
}
