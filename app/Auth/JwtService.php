<?php

namespace App\Auth;

use App\Models\RefreshToken;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * إصدار وتحقق التوكنات.
 *
 * access token: JWT قصير العمر، بلا حالة على السيرفر — يُستخدم لكل طلب REST
 * ولمصافحة الـ WebSocket (Reverb) بنفس التوكن تماماً.
 *
 * refresh token: سلسلة عشوائية طويلة العمر تُخزَّن في القاعدة كـ sha256 فقط،
 * وتُدوَّر عند كل استخدام (rotation) حتى لا يبقى توكن مسروق صالحاً.
 */
class JwtService
{
    public function accessTtl(): int
    {
        return (int) config('auth.jwt.ttl');
    }

    public function refreshTtl(): int
    {
        return (int) config('auth.jwt.refresh_ttl');
    }

    /**
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    public function issueTokenPair(User $user): array
    {
        return [
            'access_token' => $this->issueAccessToken($user),
            'refresh_token' => $this->issueRefreshToken($user),
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTtl(),
        ];
    }

    public function issueAccessToken(User $user): string
    {
        $now = Carbon::now()->getTimestamp();

        return JWT::encode([
            'sub' => $user->id,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $this->accessTtl(),
            'jti' => (string) Str::uuid(),
        ], $this->secret(), 'HS256');
    }

    public function issueRefreshToken(User $user): string
    {
        $plain = Str::random(64);

        RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => Carbon::now()->addSeconds($this->refreshTtl()),
        ]);

        return $plain;
    }

    /**
     * يعيد المستخدم صاحب التوكن، أو null إن كان التوكن غير صالح أو منتهياً.
     */
    public function userFromAccessToken(?string $token): ?User
    {
        if (blank($token)) {
            return null;
        }

        try {
            $payload = JWT::decode($token, new Key($this->secret(), 'HS256'));
        } catch (Throwable) {
            return null;
        }

        return User::find($payload->sub ?? null);
    }

    /**
     * تدوير refresh token: يُبطل القديم ويصدر زوجاً جديداً.
     *
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}|null
     */
    public function refresh(string $plainRefreshToken): ?array
    {
        $record = RefreshToken::where('token_hash', hash('sha256', $plainRefreshToken))->first();

        if (! $record || ! $record->isUsable()) {
            return null;
        }

        $record->forceFill(['revoked_at' => Carbon::now()])->save();

        $user = $record->user;

        return $user ? $this->issueTokenPair($user) : null;
    }

    public function revokeRefreshToken(string $plainRefreshToken): void
    {
        RefreshToken::where('token_hash', hash('sha256', $plainRefreshToken))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now()]);
    }

    public function revokeAllForUser(User $user): void
    {
        $user->refreshTokens()->whereNull('revoked_at')->update(['revoked_at' => Carbon::now()]);
    }

    private function secret(): string
    {
        $secret = config('auth.jwt.secret');

        if (blank($secret)) {
            throw new \RuntimeException('JWT_SECRET غير معرّف في ملف البيئة.');
        }

        return $secret;
    }
}
