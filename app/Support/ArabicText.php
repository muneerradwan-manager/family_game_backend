<?php

namespace App\Support;

/**
 * تطبيع النص العربي قبل مقارنة التكرار.
 *
 * الأسد = أسد = اسد → إجابة واحدة مكررة. بلا هذا التطبيع يصبح الفرق بين
 * إجابتين متطابقتين مجرد همزة، ويحصل الاثنان على +10 بدل +5.
 */
class ArabicText
{
    /** التشكيل والتطويل — لا معنى لهما في المقارنة. */
    private const DIACRITICS = '/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u';

    private const LETTER_MAP = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        'ة' => 'ه',
        'ى' => 'ي',
        'ؤ' => 'و',
        'ئ' => 'ي',
    ];

    public static function normalize(?string $value): string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return '';
        }

        $text = preg_replace(self::DIACRITICS, '', $text) ?? $text;
        $text = strtr($text, self::LETTER_MAP);

        // توحيد الأرقام العربية-الهندية إلى اللاتينية (ماركة مثل "بي إم دبليو 3").
        $text = strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        // حذف "ال" التعريف — لكن ليس من كلمة طولها حرفان أصلاً.
        if (mb_strlen($text) > 3 && mb_substr($text, 0, 2) === 'ال') {
            $text = mb_substr($text, 2);
        }

        return mb_strtolower($text, 'UTF-8');
    }

    /**
     * هل تبدأ الإجابة بالحرف المسحوب؟ يُستخدم كتلميح للواجهة فقط،
     * فالحكم النهائي على صحة الإجابة يبقى للاعبين عبر الاعتراض والتصويت.
     */
    public static function startsWithLetter(?string $value, string $letter): bool
    {
        $normalized = self::normalize($value);

        if ($normalized === '') {
            return false;
        }

        return mb_substr($normalized, 0, 1) === self::normalize($letter);
    }
}
