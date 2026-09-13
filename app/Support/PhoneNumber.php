<?php

namespace App\Support;

/**
 * توحيد أرقام الهواتف إلى صيغة E.164 من اليوم الأول.
 *
 * لا يوجد تحقق SMS في النسخة الأولى، لكن تخزين الرقم موحّداً من الآن يعني
 * أن إضافة التحقق لاحقاً لن تحتاج تهجير بيانات ولا تنظيف أرقام مكررة
 * بصيغ مختلفة (0791234567 و +962791234567 و 00962791234567 رقم واحد).
 */
class PhoneNumber
{
    /** رمز الدولة الافتراضي حين يكتب المستخدم رقمه المحلي بلا مقدمة دولية. */
    public const DEFAULT_COUNTRY_CODE = '962';

    public static function toE164(?string $input, string $countryCode = self::DEFAULT_COUNTRY_CODE): ?string
    {
        $raw = trim((string) $input);

        if ($raw === '') {
            return null;
        }

        // توحيد الأرقام العربية-الهندية أولاً.
        $raw = strtr($raw, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

        $hasPlus = str_starts_with($raw, '+');
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        if ($hasPlus) {
            $national = $digits;
        } elseif (str_starts_with($digits, '00')) {
            $national = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $national = $countryCode.ltrim($digits, '0');
        } elseif (str_starts_with($digits, $countryCode)) {
            $national = $digits;
        } else {
            $national = $countryCode.$digits;
        }

        $e164 = '+'.$national;

        return self::isValid($e164) ? $e164 : null;
    }

    public static function isValid(?string $value): bool
    {
        return is_string($value) && preg_match('/^\+[1-9]\d{7,14}$/', $value) === 1;
    }
}
