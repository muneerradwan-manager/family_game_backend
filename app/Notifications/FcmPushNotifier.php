<?php

namespace App\Notifications;

use App\Models\Channel;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * إرسال عبر FCM HTTP v1.
 *
 * لا نحتاج حزمة Google كاملة: ملف حساب الخدمة يحوي مفتاحاً خاصاً، فنوقّع به
 * JWT ونبدّله بـ access token — وهو بالضبط ما تفعله تلك الحزمة.
 */
class FcmPushNotifier implements PushNotifier
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    public function __construct(
        private readonly string $credentialsPath,
        private readonly string $projectId,
    ) {}

    public function send(array $tokens, string $title, string $body, array $data = []): void
    {
        $tokens = array_values(array_filter(array_unique($tokens)));

        if ($tokens === []) {
            return;
        }

        try {
            $accessToken = $this->accessToken();
        } catch (Throwable $exception) {
            Log::error('تعذّر الحصول على توكن FCM', ['error' => $exception->getMessage()]);

            return;
        }

        $endpoint = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

        foreach ($tokens as $token) {
            try {
                $response = Http::withToken($accessToken)
                    ->timeout(5)
                    ->post($endpoint, [
                        'message' => [
                            'token' => $token,
                            'notification' => ['title' => $title, 'body' => $body],
                            // القيم كلها نصوص: FCM يرفض غيرها في حقل data.
                            'data' => array_map(fn ($value) => (string) $value, $data),
                            'android' => ['priority' => 'high'],
                            'apns' => ['headers' => ['apns-priority' => '10']],
                        ],
                    ]);

                if ($response->failed()) {
                    Log::warning('فشل إرسال إشعار FCM', ['status' => $response->status()]);
                }
            } catch (Throwable $exception) {
                // فشل إشعار لا يوقف اللعبة أبداً.
                Log::warning('استثناء أثناء إرسال إشعار FCM', ['error' => $exception->getMessage()]);
            }
        }
    }

    public function toChannelMembers(Channel $channel, array $except, string $title, string $body, array $data = []): void
    {
        $tokens = $channel->members()
            ->whereNotNull('fcm_token')
            ->whereNotIn('users.id', $except)
            ->pluck('fcm_token')
            ->all();

        $this->send($tokens, $title, $body, $data);
    }

    private function accessToken(): string
    {
        return Cache::remember('fcm:access_token', 3300, function () {
            $credentials = $this->credentials();
            $now = time();

            $assertion = JWT::encode([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_ENDPOINT,
                'iat' => $now,
                'exp' => $now + 3600,
            ], $credentials['private_key'], 'RS256');

            $response = Http::asForm()->timeout(10)->post(self::TOKEN_ENDPOINT, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

            if ($response->failed() || ! isset($response->json()['access_token'])) {
                throw new RuntimeException('رفض Google طلب توكن FCM.');
            }

            return $response->json()['access_token'];
        });
    }

    /** @return array{client_email: string, private_key: string} */
    private function credentials(): array
    {
        if (! is_file($this->credentialsPath)) {
            throw new RuntimeException("ملف حساب خدمة FCM غير موجود: {$this->credentialsPath}");
        }

        $decoded = json_decode((string) file_get_contents($this->credentialsPath), true);

        if (! isset($decoded['client_email'], $decoded['private_key'])) {
            throw new RuntimeException('ملف حساب خدمة FCM ناقص.');
        }

        return $decoded;
    }
}
