<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * الحساب الموقوف لا يصل لشيء — ولا حتى القناة الحيّة.
 *
 * يأتي بعد المصادقة: التوكن نفسه صالح تقنياً حتى ينتهي، فالإيقاف يُفرض هنا
 * مع كل طلب لا عند الدخول وحده. وإلا بقي الموقوف يلعب ساعةً كاملة حتى
 * ينتهي توكنه.
 */
class EnsureUserNotBanned
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && method_exists($user, 'isBanned') && $user->isBanned()) {
            return response()->json([
                'message' => self::message($user->ban_reason),
                'banned' => true,
            ], 403);
        }

        return $next($request);
    }

    public static function message(?string $reason): string
    {
        return $reason === null || $reason === ''
            ? 'حسابك موقوف — تواصل مع الإدارة.'
            : "حسابك موقوف: {$reason}";
    }
}
