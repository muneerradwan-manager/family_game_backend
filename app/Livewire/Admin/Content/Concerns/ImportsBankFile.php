<?php

namespace App\Livewire\Admin\Content\Concerns;

use App\Admin\AdminAudit;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\WithFileUploads;
use Throwable;

/**
 * رفع ملف JSONL إلى بنك أسئلة أو مشاهد.
 *
 * يمرّ عبر أمر الاستيراد نفسه لا عبر منطق مكرّر هنا: قواعد التحقق ومنع
 * التكرار في مكان واحد، فاستيراد اللوحة وسطر الأوامر لا يختلفان أبداً.
 */
trait ImportsBankFile
{
    use WithFileUploads;

    public $importFile = null;

    public string $importOutput = '';

    protected function runImport(string $command, string $label): void
    {
        $this->validate(['importFile' => ['required', 'file', 'max:20480']], [], ['importFile' => 'الملف']);

        $extension = Str::lower($this->importFile->getClientOriginalExtension());

        if (! in_array($extension, ['jsonl', 'json'], true)) {
            $this->addError('importFile', 'الملف لازم يكون .jsonl أو .json');

            return;
        }

        $source = Str::slug(pathinfo($this->importFile->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'admin';
        $directory = storage_path('app/imports/'.Str::uuid());
        File::ensureDirectoryExists($directory);

        // اسم الملف يصير «مصدر» الأسئلة في البنك، فنحفظه باسمه الأصلي لا بالاسم المؤقت.
        $path = $directory.'/'.$source.'.'.$extension;
        File::copy($this->importFile->getRealPath(), $path);

        try {
            $exitCode = Artisan::call($command, ['path' => $path]);
            $this->importOutput = trim(Artisan::output());
        } catch (Throwable $exception) {
            $exitCode = 1;
            $this->importOutput = $exception->getMessage();
        } finally {
            File::deleteDirectory($directory);
        }

        AdminAudit::record("{$command}.upload", "استورد ملف {$label}: {$source}.{$extension}", null, [
            'exitCode' => $exitCode,
            'output' => Str::limit($this->importOutput, 2000),
        ]);

        $this->importFile = null;
        $this->notify($exitCode === 0 ? 'خلص الاستيراد — النتيجة تحت.' : 'فشل الاستيراد — شوف التفاصيل.', $exitCode === 0 ? 'success' : 'error');
    }
}
