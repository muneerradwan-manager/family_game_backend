<?php

namespace App\Livewire\Admin\Content;

use App\Admin\AdminAudit;
use App\Livewire\Admin\AdminComponent;
use App\Livewire\Admin\Concerns\EditsSettings;
use App\Livewire\Admin\Content\Concerns\ImportsBankFile;
use App\Models\HadafQuestion;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * بنك أسئلة لعبة الهدف.
 */
#[Layout('admin.layout')]
#[Title('أسئلة الهدف')]
class HadafQuestions extends AdminComponent
{
    use EditsSettings;
    use ImportsBankFile;
    use WithPagination;

    public const DIFFICULTIES = ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $difficulty = '';

    /** null = مسكّرة، 0 = جديد. */
    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var array<string, array{label: string, emoji: string}> */
    public array $categoryLabels = [];

    public bool $showCategories = false;

    public function mount(): void
    {
        $this->loadCategoryLabels();
    }

    private function loadCategoryLabels(): void
    {
        $this->categoryLabels = collect(config('hadaf.categories', []))
            ->map(fn (array $category) => ['label' => $category['label'] ?? '', 'emoji' => $category['emoji'] ?? ''])
            ->all();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'category', 'difficulty'], true)) {
            $this->resetPage();
        }
    }

    private function distractorSlots(): int
    {
        return max(1, (int) config('hadaf.choices') - 1);
    }

    public function create(): void
    {
        $this->editingId = 0;
        $this->form = [
            'category' => $this->category ?: array_key_first(config('hadaf.categories')),
            'difficulty' => $this->difficulty ?: 'medium',
            'prompt' => '',
            'answer' => '',
            'distractors' => array_fill(0, $this->distractorSlots(), ''),
            'explanation' => '',
        ];
        $this->resetValidation();
    }

    public function edit(int $id): void
    {
        $question = HadafQuestion::findOrFail($id);
        $distractors = array_values($question->distractors ?? []);

        $this->editingId = $question->id;
        $this->form = [
            'category' => $question->category,
            'difficulty' => $question->difficulty,
            'prompt' => $question->prompt,
            'answer' => $question->answer,
            'distractors' => array_pad($distractors, $this->distractorSlots(), ''),
            'explanation' => (string) $question->explanation,
        ];
        $this->resetValidation();
    }

    public function addDistractor(): void
    {
        $this->form['distractors'][] = '';
    }

    public function save(): void
    {
        $this->validate([
            'form.category' => ['required', 'in:'.implode(',', array_keys(config('hadaf.categories')))],
            'form.difficulty' => ['required', 'in:easy,medium,hard'],
            'form.prompt' => ['required', 'string', 'max:400'],
            'form.answer' => ['required', 'string', 'max:200'],
            'form.distractors' => ['array'],
            'form.distractors.*' => ['nullable', 'string', 'max:200'],
            'form.explanation' => ['nullable', 'string', 'max:400'],
        ], [], [
            'form.category' => 'المجموعة',
            'form.difficulty' => 'الصعوبة',
            'form.prompt' => 'السؤال',
            'form.answer' => 'الجواب',
            'form.distractors.*' => 'الخيار',
            'form.explanation' => 'التوضيح',
        ]);

        $prompt = trim($this->form['prompt']);
        $answer = trim($this->form['answer']);

        $distractors = array_values(array_unique(array_filter(
            array_map(fn ($option) => trim((string) $option), $this->form['distractors']),
            fn (string $option) => $option !== '' && $option !== $answer,
        )));

        if (count($distractors) < $this->distractorSlots()) {
            $this->addError('form.distractors', "لازم {$this->distractorSlots()} خيارات خاطئة مختلفة عن الجواب وعن بعض.");

            return;
        }

        // نفس بصمة أمر الاستيراد — فسؤال أُضيف من اللوحة لا يُكرَّر باستيراد لاحق.
        $fingerprint = sha1($this->form['category'].'|'.preg_replace('/\s+/u', ' ', $prompt));

        $duplicate = HadafQuestion::where('fingerprint', $fingerprint)
            ->when($this->editingId, fn ($query) => $query->where('id', '!=', $this->editingId))
            ->exists();

        if ($duplicate) {
            $this->addError('form.prompt', 'هالسؤال موجود بالبنك بنفس المجموعة.');

            return;
        }

        $question = $this->editingId ? HadafQuestion::findOrFail($this->editingId) : new HadafQuestion(['source' => 'admin']);
        $isNew = ! $question->exists;
        $before = $isNew ? [] : $question->only(['category', 'difficulty', 'prompt', 'answer', 'distractors', 'explanation']);

        $question->fill([
            'category' => $this->form['category'],
            'difficulty' => $this->form['difficulty'],
            'prompt' => $prompt,
            'answer' => $answer,
            'distractors' => $distractors,
            'explanation' => trim((string) $this->form['explanation']) ?: null,
            'fingerprint' => $fingerprint,
        ])->save();

        AdminAudit::record(
            $isNew ? 'hadaf.question_created' : 'hadaf.question_updated',
            ($isNew ? 'أضاف سؤال هدف: ' : 'عدّل سؤال هدف: ').$prompt,
            $question,
            AdminAudit::diff($before, $question->only(['category', 'difficulty', 'prompt', 'answer', 'distractors', 'explanation'])),
        );

        $this->editingId = null;
        $this->notify('انحفظ السؤال.');
    }

    public function delete(int $id): void
    {
        $question = HadafQuestion::findOrFail($id);

        AdminAudit::record('hadaf.question_deleted', 'حذف سؤال هدف: '.$question->prompt, $question, [
            'question' => $question->only(['category', 'difficulty', 'prompt', 'answer', 'distractors', 'explanation']),
        ]);

        $question->delete();

        $this->notify('انحذف السؤال.');
    }

    public function import(): void
    {
        $this->runImport('hadaf:import', 'أسئلة الهدف');
    }

    public function saveCategories(): void
    {
        $value = [];

        foreach ($this->settings()->default('hadaf.categories') ?? [] as $key => $original) {
            $label = trim((string) ($this->categoryLabels[$key]['label'] ?? ''));

            if ($label === '' || mb_strlen($label) > 30) {
                $this->addError("categoryLabels.{$key}.label", 'الاسم مطلوب وأقصاه 30 حرف.');

                return;
            }

            // «مولّدة» صفة برمجية للمجموعة لا يغيّرها المشرف.
            $value[$key] = [
                'label' => $label,
                'emoji' => trim((string) ($this->categoryLabels[$key]['emoji'] ?? '')),
                'generated' => (bool) ($original['generated'] ?? false),
            ];
        }

        $changed = $this->saveSetting('hadaf.categories', $value, 'أسماء مجموعات الهدف');
        $this->resetValidation();
        $this->notify($changed ? 'انحفظت أسماء المجموعات.' : 'ما في تغيير.', $changed ? 'success' : 'warning');
    }

    public function resetCategories(): void
    {
        $this->resetSetting('hadaf.categories', 'أسماء مجموعات الهدف');
        $this->loadCategoryLabels();
        $this->notify('رجعت الأسماء للافتراضي.');
    }

    public function render()
    {
        $term = trim($this->search);

        $questions = HadafQuestion::query()
            ->when($term !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('prompt', 'like', "%{$term}%")
                ->orWhere('answer', 'like', "%{$term}%")))
            ->when($this->category !== '', fn ($query) => $query->where('category', $this->category))
            ->when($this->difficulty !== '', fn ($query) => $query->where('difficulty', $this->difficulty))
            ->latest('id')
            ->paginate(25);

        $counts = HadafQuestion::query()
            ->selectRaw('category, difficulty, count(*) as total')
            ->groupBy('category', 'difficulty')
            ->get()
            ->groupBy('category')
            ->map(fn ($rows) => $rows->pluck('total', 'difficulty'));

        return view('livewire.admin.content.hadaf', [
            'questions' => $questions,
            'categories' => config('hadaf.categories'),
            'difficulties' => self::DIFFICULTIES,
            'counts' => $counts,
            'total' => HadafQuestion::count(),
            'categoriesOverridden' => $this->settings()->isOverridden('hadaf.categories'),
        ]);
    }
}
