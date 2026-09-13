<?php

namespace App\Livewire\Admin\Admins;

use App\Admin\AdminAudit;
use App\Livewire\Admin\AdminComponent;
use App\Models\Admin;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/**
 * إدارة المشرفين — للمشرف الأعلى وحده.
 */
#[Layout('admin.layout')]
#[Title('المشرفون')]
class Index extends AdminComponent
{
    /** null = مسكّرة، 0 = جديد. */
    public ?int $editingId = null;

    /** @var array{name: string, email: string, password: string, is_super: bool} */
    public array $form = ['name' => '', 'email' => '', 'password' => '', 'is_super' => false];

    public function boot(): void
    {
        parent::boot();

        abort_unless($this->admin()->is_super, 403);
    }

    public function create(): void
    {
        $this->editingId = 0;
        $this->form = ['name' => '', 'email' => '', 'password' => '', 'is_super' => false];
        $this->resetValidation();
    }

    public function edit(int $id): void
    {
        $admin = Admin::findOrFail($id);

        $this->editingId = $admin->id;
        $this->form = ['name' => $admin->name, 'email' => $admin->email, 'password' => '', 'is_super' => $admin->is_super];
        $this->resetValidation();
    }

    public function save(): void
    {
        $admin = $this->editingId ? Admin::findOrFail($this->editingId) : new Admin;
        $this->form['email'] = Str::lower(trim($this->form['email']));

        $this->validate([
            'form.name' => ['required', 'string', 'max:80'],
            'form.email' => ['required', 'email', 'max:190', Rule::unique('admins', 'email')->ignore($admin->id)],
            'form.password' => [$admin->exists ? 'nullable' : 'required', 'string', 'min:10', 'max:200'],
            'form.is_super' => ['boolean'],
        ], [], [
            'form.name' => 'الاسم',
            'form.email' => 'الإيميل',
            'form.password' => 'كلمة السر',
        ]);

        // لا تُسحب صلاحية آخر مشرف أعلى — وإلا ما عاد أحد يدير المشرفين.
        if ($admin->exists && $admin->is_super && ! $this->form['is_super'] && Admin::where('is_super', true)->count() <= 1) {
            $this->addError('form.is_super', 'هذا آخر مشرف أعلى — ما بتقدر تشيل صلاحيته.');

            return;
        }

        $isNew = ! $admin->exists;
        $before = $admin->exists ? ['name' => $admin->name, 'email' => $admin->email, 'is_super' => $admin->is_super] : [];

        $admin->name = trim($this->form['name']);
        $admin->email = $this->form['email'];
        $admin->is_super = (bool) $this->form['is_super'];

        if (($this->form['password'] ?? '') !== '') {
            $admin->password = $this->form['password'];
        }

        $admin->save();

        $changes = AdminAudit::diff($before, ['name' => $admin->name, 'email' => $admin->email, 'is_super' => $admin->is_super]);

        if (($this->form['password'] ?? '') !== '' && ! $isNew) {
            // السجل يذكر أن كلمة السر تغيّرت — لا يذكرها هي.
            $changes['password'] = ['from' => '••••', 'to' => '•••• (تغيّرت)'];
        }

        AdminAudit::record($isNew ? 'admin.created' : 'admin.updated', ($isNew ? 'أضاف المشرف ' : 'عدّل المشرف ').$admin->email, $admin, $changes);

        $this->editingId = null;
        $this->form['password'] = '';
        $this->notify('انحفظ المشرف.');
    }

    public function delete(int $id): void
    {
        $admin = Admin::findOrFail($id);

        if ($admin->id === $this->admin()->id) {
            $this->notify('ما بتقدر تحذف حسابك.', 'error');

            return;
        }

        if ($admin->is_super && Admin::where('is_super', true)->count() <= 1) {
            $this->notify('هذا آخر مشرف أعلى.', 'error');

            return;
        }

        AdminAudit::record('admin.deleted', "حذف المشرف {$admin->email}", $admin);

        $admin->delete();

        $this->notify('انحذف المشرف.');
    }

    public function render()
    {
        return view('livewire.admin.admins.index', [
            'admins' => Admin::orderByDesc('is_super')->orderBy('name')->get(),
        ]);
    }
}
