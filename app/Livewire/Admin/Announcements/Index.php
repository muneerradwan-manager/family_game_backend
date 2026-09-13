<?php

namespace App\Livewire\Admin\Announcements;

use App\Admin\AdminAudit;
use App\Admin\AnnouncementPublisher;
use App\Livewire\Admin\AdminComponent;
use App\Models\Announcement;
use App\Models\Channel;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\WithPagination;

#[Layout('admin.layout')]
#[Title('الإعلانات')]
class Index extends AdminComponent
{
    use WithPagination;

    /** null = مسكّرة، 0 = جديد. */
    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function create(): void
    {
        $this->editingId = 0;
        $this->form = [
            'title' => '',
            'body' => '',
            'level' => 'info',
            'audience' => 'all',
            'channel_code' => '',
            'starts_at' => '',
            'ends_at' => '',
            'is_active' => true,
            'is_dismissible' => true,
            'send_push' => false,
        ];
        $this->resetValidation();
    }

    public function edit(int $id): void
    {
        $announcement = Announcement::with('channel')->findOrFail($id);

        $this->editingId = $announcement->id;
        $this->form = [
            'title' => $announcement->title,
            'body' => $announcement->body,
            'level' => $announcement->level,
            'audience' => $announcement->audience,
            'channel_code' => (string) $announcement->channel?->invite_code,
            'starts_at' => $announcement->starts_at?->format('Y-m-d\TH:i') ?? '',
            'ends_at' => $announcement->ends_at?->format('Y-m-d\TH:i') ?? '',
            'is_active' => $announcement->is_active,
            'is_dismissible' => $announcement->is_dismissible,
            'send_push' => false,
        ];
        $this->resetValidation();
    }

    public function save(AnnouncementPublisher $publisher): void
    {
        $this->validate([
            'form.title' => ['required', 'string', 'max:120'],
            'form.body' => ['required', 'string', 'max:2000'],
            'form.level' => ['required', 'in:'.implode(',', array_keys(Announcement::LEVELS))],
            'form.audience' => ['required', 'in:all,channel'],
            'form.channel_code' => ['required_if:form.audience,channel', 'nullable', 'string', 'size:6'],
            'form.starts_at' => ['nullable', 'date'],
            'form.ends_at' => ['nullable', 'date'],
            'form.is_active' => ['boolean'],
            'form.is_dismissible' => ['boolean'],
            'form.send_push' => ['boolean'],
        ], [
            'form.channel_code.required_if' => 'اكتب رمز دعوة القناة.',
            'size' => 'رمز الدعوة 6 خانات.',
        ], [
            'form.title' => 'العنوان',
            'form.body' => 'النص',
            'form.level' => 'النوع',
            'form.starts_at' => 'البداية',
            'form.ends_at' => 'النهاية',
        ]);

        $channel = null;

        if ($this->form['audience'] === 'channel') {
            $channel = Channel::where('invite_code', strtoupper(trim($this->form['channel_code'])))->first();

            if ($channel === null) {
                $this->addError('form.channel_code', 'ما في قناة بهالرمز.');

                return;
            }
        }

        $startsAt = $this->form['starts_at'] ? Carbon::parse($this->form['starts_at']) : null;
        $endsAt = $this->form['ends_at'] ? Carbon::parse($this->form['ends_at']) : null;

        if ($startsAt && $endsAt && $endsAt->lte($startsAt)) {
            $this->addError('form.ends_at', 'النهاية لازم تكون بعد البداية.');

            return;
        }

        $announcement = $this->editingId ? Announcement::findOrFail($this->editingId) : new Announcement;
        $isNew = ! $announcement->exists;

        $values = [
            'title' => trim($this->form['title']),
            'body' => trim($this->form['body']),
            'level' => $this->form['level'],
            'audience' => $this->form['audience'],
            'channel_id' => $channel?->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_active' => (bool) $this->form['is_active'],
            'is_dismissible' => (bool) $this->form['is_dismissible'],
        ];

        $before = $isNew ? [] : $announcement->only(['title', 'body', 'level', 'audience', 'channel_id', 'is_active', 'is_dismissible']);

        $announcement->fill($values);

        if ($isNew) {
            $announcement->created_by = $this->admin()->id;
        }

        $announcement->save();

        AdminAudit::record(
            $isNew ? 'announcement.created' : 'announcement.updated',
            ($isNew ? 'نشر الإعلان' : 'عدّل الإعلان')." «{$announcement->title}»",
            $announcement,
            AdminAudit::diff($before, $announcement->only(array_keys($before ?: $values))),
        );

        $message = 'انحفظ الإعلان.';

        if ($this->form['send_push'] && $announcement->is_active) {
            $sent = $publisher->push($announcement);
            AdminAudit::record('announcement.pushed', "أرسل إشعار «{$announcement->title}» لـ {$sent} جهاز", $announcement);
            $message = "انحفظ الإعلان وانبعت إشعار لـ {$sent} جهاز.";
        }

        $this->editingId = null;
        $this->notify($message);
    }

    public function push(int $id, AnnouncementPublisher $publisher): void
    {
        $announcement = Announcement::findOrFail($id);
        $sent = $publisher->push($announcement);

        AdminAudit::record('announcement.pushed', "أرسل إشعار «{$announcement->title}» لـ {$sent} جهاز", $announcement);

        $this->notify("انبعت الإشعار لـ {$sent} جهاز.");
    }

    public function toggleActive(int $id): void
    {
        $announcement = Announcement::findOrFail($id);
        $announcement->forceFill(['is_active' => ! $announcement->is_active])->save();

        AdminAudit::record(
            'announcement.toggled',
            ($announcement->is_active ? 'فعّل' : 'أوقف')." الإعلان «{$announcement->title}»",
            $announcement,
        );

        $this->notify($announcement->is_active ? 'الإعلان ظاهر.' : 'الإعلان مخفي.');
    }

    public function delete(int $id): void
    {
        $announcement = Announcement::findOrFail($id);

        AdminAudit::record('announcement.deleted', "حذف الإعلان «{$announcement->title}»", $announcement, [
            'announcement' => $announcement->toApi(),
        ]);

        $announcement->delete();

        $this->notify('انحذف الإعلان.');
    }

    public function render()
    {
        return view('livewire.admin.announcements.index', [
            'announcements' => Announcement::with('channel:id,name')->latest()->paginate(20),
            'levels' => Announcement::LEVELS,
            'now' => now(),
        ]);
    }
}
