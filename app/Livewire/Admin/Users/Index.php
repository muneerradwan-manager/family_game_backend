<?php

namespace App\Livewire\Admin\Users;

use App\Admin\AdminAudit;
use App\Livewire\Admin\AdminComponent;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

#[Layout('admin.layout')]
#[Title('المستخدمون')]
class Index extends AdminComponent
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    public ?string $editingId = null;

    /** @var array{full_name: string, username: string, phone: string} */
    public array $form = ['full_name' => '', 'username' => '', 'phone' => ''];

    public ?string $banningId = null;

    public string $banReason = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function edit(string $id): void
    {
        $user = User::findOrFail($id);

        $this->editingId = $user->id;
        $this->form = [
            'full_name' => $user->full_name,
            'username' => $user->username,
            'phone' => $user->phone,
        ];
        $this->resetValidation();
    }

    public function save(): void
    {
        $user = User::findOrFail($this->editingId);

        $this->validate([
            'form.full_name' => ['required', 'string', 'max:80'],
            'form.username' => ['required', 'regex:/^[a-z0-9_]{3,20}$/', Rule::unique('users', 'username')->ignore($user->id)],
            'form.phone' => ['required', 'regex:/^\+\d{8,15}$/', Rule::unique('users', 'phone')->ignore($user->id)],
        ], [], [
            'form.full_name' => 'الاسم',
            'form.username' => 'اليوزر',
            'form.phone' => 'رقم الهاتف',
        ]);

        $before = $user->only(array_keys($this->form));
        $user->forceFill($this->form)->save();

        AdminAudit::record('user.updated', "عدّل بيانات المستخدم @{$user->username}", $user, AdminAudit::diff($before, $this->form));

        $this->editingId = null;
        $this->notify('انحفظت بيانات المستخدم.');
    }

    public function startBan(string $id): void
    {
        $this->banningId = User::findOrFail($id)->id;
        $this->banReason = '';
        $this->resetValidation();
    }

    public function ban(): void
    {
        $user = User::findOrFail($this->banningId);

        $this->validate(['banReason' => ['nullable', 'string', 'max:300']], [], ['banReason' => 'السبب']);

        $user->forceFill([
            'banned_at' => now(),
            'ban_reason' => trim($this->banReason) === '' ? null : trim($this->banReason),
        ])->save();

        // الإيقاف يسري فوراً: توكنات التجديد تُبطل فلا يستطيع الجهاز تمديد
        // جلسته، والتوكن الحالي يرفضه وسيط not-banned مع أول طلب.
        $revoked = $this->revokeTokens($user);

        AdminAudit::record('user.banned', "أوقف المستخدم @{$user->username}", $user, [
            'reason' => $user->ban_reason,
            'revokedTokens' => $revoked,
        ]);

        $this->banningId = null;
        $this->notify("انوقف حساب @{$user->username}.");
    }

    public function unban(string $id): void
    {
        $user = User::findOrFail($id);

        $user->forceFill(['banned_at' => null, 'ban_reason' => null])->save();

        AdminAudit::record('user.unbanned', "رفع الإيقاف عن @{$user->username}", $user);

        $this->notify("انرفع الإيقاف عن @{$user->username}.");
    }

    public function revokeSessions(string $id): void
    {
        $user = User::findOrFail($id);
        $revoked = $this->revokeTokens($user);

        AdminAudit::record('user.sessions_revoked', "سجّل خروج @{$user->username} من كل الأجهزة", $user, [
            'revokedTokens' => $revoked,
        ]);

        $this->notify("انسجّل خروجه من {$revoked} جهاز — بتنتهي الجلسات الحالية مع انتهاء التوكن.");
    }

    private function revokeTokens(User $user): int
    {
        return RefreshToken::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function render()
    {
        $term = trim($this->search);

        $users = User::query()
            ->withCount('channels')
            ->when($term !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('username', 'like', "%{$term}%")
                ->orWhere('full_name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")))
            ->when($this->status === 'banned', fn ($query) => $query->whereNotNull('banned_at'))
            ->when($this->status === 'active', fn ($query) => $query->whereNull('banned_at'))
            ->latest()
            ->paginate(20);

        return view('livewire.admin.users.index', [
            'users' => $users,
            'banning' => $this->banningId ? User::find($this->banningId) : null,
        ]);
    }
}
