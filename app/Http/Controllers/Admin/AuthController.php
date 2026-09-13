<?php

namespace App\Http\Controllers\Admin;

use App\Admin\AdminAudit;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * دخول لوحة الإدارة.
 *
 * نموذج HTML عادي لا مكوّن Livewire: الدخول صفحة واحدة بلا تفاعل حي، وحماية
 * CSRF وتدوير الجلسة هنا أوضح وأصعب نسياناً.
 */
class AuthController extends Controller
{
    /** محاولات فاشلة قبل الإقفال المؤقت — لكل إيميل وعنوان معاً. */
    private const MAX_ATTEMPTS = 5;

    public function showLogin(): View|RedirectResponse
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = 'admin-login:'.Str::lower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            return back()
                ->withErrors(['email' => "محاولات كتير — جرّب بعد {$seconds} ثانية."])
                ->onlyInput('email');
        }

        $credentials['email'] = Str::lower($credentials['email']);

        if (! Auth::guard('admin')->attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);

            // رسالة واحدة للحالتين: لا نكشف أي إيميلات عندها حساب مشرف.
            return back()
                ->withErrors(['email' => 'الإيميل أو كلمة السر غير صحيحة.'])
                ->onlyInput('email');
        }

        RateLimiter::clear($key);

        // جلسة جديدة بعد الدخول: تُبطل أي معرّف جلسة زُرع قبل المصادقة.
        $request->session()->regenerate();

        $admin = Auth::guard('admin')->user();
        $admin->forceFill(['last_login_at' => now()])->save();

        AdminAudit::record('login', 'دخل لوحة الإدارة', $admin);

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        if (Auth::guard('admin')->check()) {
            AdminAudit::record('logout', 'خرج من لوحة الإدارة', Auth::guard('admin')->user());
        }

        Auth::guard('admin')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
