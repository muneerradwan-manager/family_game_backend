<?php

namespace App\Livewire\Admin;

use App\Admin\AdminAudit;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

#[Layout('admin.layout')]
#[Title('حسابي')]
class Profile extends AdminComponent
{
    public string $name = '';

    public string $currentPassword = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public function mount(): void
    {
        $this->name = $this->admin()->name;
    }

    public function saveName(): void
    {
        $this->validate(['name' => ['required', 'string', 'max:80']], [], ['name' => 'الاسم']);

        $admin = $this->admin();
        $before = $admin->name;
        $admin->forceFill(['name' => trim($this->name)])->save();

        AdminAudit::record('admin.profile', 'غيّر اسمه في اللوحة', $admin, ['name' => ['from' => $before, 'to' => $admin->name]]);

        $this->notify('انحفظ الاسم.');
    }

    public function savePassword(): void
    {
        $this->validate([
            'currentPassword' => ['required', 'string', 'current_password:admin'],
            'password' => ['required', 'string', 'min:10', 'max:200', 'same:passwordConfirmation'],
        ], [
            'same' => 'تأكيد كلمة السر غير مطابق.',
        ], [
            'currentPassword' => 'كلمة السر الحالية',
            'password' => 'كلمة السر الجديدة',
        ]);

        $admin = $this->admin();
        $admin->password = $this->password;
        $admin->save();

        AdminAudit::record('admin.password_changed', 'غيّر كلمة سره', $admin);

        $this->reset('currentPassword', 'password', 'passwordConfirmation');
        $this->notify('تغيّرت كلمة السر.');
    }

    public function render()
    {
        return view('livewire.admin.profile');
    }
}
