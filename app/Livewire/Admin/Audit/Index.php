<?php

namespace App\Livewire\Admin\Audit;

use App\Livewire\Admin\AdminComponent;
use App\Models\AdminAuditLog;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

#[Layout('admin.layout')]
#[Title('سجل التدقيق')]
class Index extends AdminComponent
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $action = '';

    public ?int $viewingId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedAction(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $term = trim($this->search);

        return view('livewire.admin.audit.index', [
            'logs' => AdminAuditLog::query()
                ->when($term !== '', fn ($query) => $query->where(fn ($q) => $q
                    ->where('summary', 'like', "%{$term}%")
                    ->orWhere('admin_email', 'like', "%{$term}%")
                    ->orWhere('subject_id', $term)))
                ->when($this->action !== '', fn ($query) => $query->where('action', $this->action))
                ->latest('created_at')
                ->latest('id')
                ->paginate(30),
            'actions' => AdminAuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'viewing' => $this->viewingId ? AdminAuditLog::find($this->viewingId) : null,
        ]);
    }
}
