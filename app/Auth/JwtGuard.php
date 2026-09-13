<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Traits\Macroable;

/**
 * حارس بلا حالة يقرأ التوكن من ترويسة Authorization في كل طلب.
 *
 * لا نستخدم Auth::viaRequest لأن حارسه يحتفظ بالمستخدم الذي حلّه أول مرة
 * وبنسخة الطلب التي أُنشئ معها. مع عملية-لكل-طلب لا يظهر الفرق، لكن مع
 * سيرفر طويل العمر (Octane) أو عدة طلبات داخل اختبار واحد يعني ذلك أن
 * الطلب الثاني يُنسب لصاحب الطلب الأول. هنا نربط الحل بنسخة الطلب نفسها.
 */
class JwtGuard implements Guard
{
    use Macroable;

    private ?Authenticatable $user = null;

    /** نسخة الطلب التي حُلّ المستخدم من أجلها. */
    private ?Request $resolvedFor = null;

    /** ثُبِّت المستخدم يدوياً (actingAs في الاختبارات) فلا يُعاد حلّه. */
    private bool $pinned = false;

    public function __construct(
        private readonly JwtService $jwt,
        private readonly Container $container,
    ) {}

    public function user(): ?Authenticatable
    {
        if ($this->pinned) {
            return $this->user;
        }

        $request = $this->currentRequest();

        if ($this->resolvedFor === $request) {
            return $this->user;
        }

        $this->resolvedFor = $request;
        $this->user = $this->jwt->userFromAccessToken($request?->bearerToken());

        return $this->user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function id(): ?string
    {
        return $this->user()?->getAuthIdentifier();
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;
        $this->pinned = true;

        return $this;
    }

    /** @param array<string, mixed> $credentials */
    public function validate(array $credentials = []): bool
    {
        if (! isset($credentials['phone'], $credentials['password'])) {
            return false;
        }

        $user = User::where('phone', $credentials['phone'])->first();

        return $user !== null && Hash::check($credentials['password'], $user->password_hash);
    }

    private function currentRequest(): ?Request
    {
        return $this->container->bound('request') ? $this->container->make('request') : null;
    }
}
