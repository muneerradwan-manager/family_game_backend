<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * رفع صور البروفايل والقنوات.
 *
 * الملفات تُخزَّن خارج المجلد العام وتُقدَّم عبر مسار مقروء هنا، لا عبر
 * رابط رمزي (storage:link): الرابط الرمزي يحتاج صلاحيات إدارية على ويندوز
 * وينكسر بصمت عند النشر. مسار واحد يعمل في كل مكان.
 *
 * لا تصغير على السيرفر — نسخة PHP هنا بلا GD/Imagick. التطبيق يصغّر الصورة
 * قبل الرفع، والسقف أدناه هو خط الدفاع الثاني.
 */
class UploadController extends Controller
{
    private const MAX_KILOBYTES = 4096;

    private const DISK = 'local';

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_KILOBYTES],
        ]);

        $file = $request->file('image');

        // اسم عشوائي لا اسم المستخدم الأصلي: يمنع تخمين الملفات والكتابة فوقها.
        $name = Str::uuid()->toString().'.'.$file->extension();
        $path = 'uploads/'.now()->format('Y/m').'/'.$name;

        Storage::disk(self::DISK)->put($path, $file->get());

        return response()->json([
            'path' => $path,
            'url' => url('/api/uploads/'.$path),
        ], 201);
    }

    /**
     * تقديم صورة مرفوعة.
     *
     * مفتوح بلا مصادقة عمداً: الروابط تظهر داخل قوائم الأعضاء ولوحات النتائج
     * وقد تُشارَك، واسم الملف عشوائي لا يمكن تخمينه.
     */
    public function show(string $path): StreamedResponse
    {
        // مسار الملف يأتي من الرابط: نمنع الخروج من مجلد الرفع.
        abort_unless(preg_match('#^uploads/\d{4}/\d{2}/[0-9a-f\-]{36}\.(jpg|jpeg|png|webp)$#', $path), 404);

        $disk = Storage::disk(self::DISK);

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, headers: [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
