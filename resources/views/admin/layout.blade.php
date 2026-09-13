<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'لوحة الإدارة' }} · ألعاب العيلة</title>
    {{-- رقم النسخة من تاريخ تعديل الملف: تحديث CSS لا يبقى مخبّأً عند المشرفين.
         والمجلد admin-assets لا admin: مجلد بنفس اسم المسار يجعل خادم الملفات
         يردّ على /admin بنفسه (404) قبل أن يصل الطلب لـ Laravel. --}}
    <link rel="stylesheet" href="{{ asset('admin-assets/admin.css') }}?v={{ @filemtime(public_path('admin-assets/admin.css')) }}">
    @livewireStyles
</head>
<body>
@php
    $admin = auth('admin')->user();

    $navigation = [
        'الأساسيات' => [
            ['admin.dashboard', 'نظرة عامة', '📊', 'admin.dashboard'],
            ['admin.users.index', 'المستخدمون', '👤', 'admin.users.*'],
            ['admin.channels.index', 'القنوات', '👨‍👩‍👧', 'admin.channels.*'],
            ['admin.sessions.index', 'الجلسات', '🎮', 'admin.sessions.*'],
        ],
        'الألعاب' => [
            ['admin.games.index', 'الألعاب وإعداداتها', '🎲', 'admin.games.*'],
            ['admin.content.harf', 'حروف وأعمدة الحروف', '🔠', 'admin.content.harf'],
            ['admin.content.spy', 'كلمات الجاسوس', '🕵️', 'admin.content.spy'],
            ['admin.content.hadaf', 'أسئلة الهدف', '🎯', 'admin.content.hadaf'],
            ['admin.content.scenes', 'مشاهد المشهد', '🎬', 'admin.content.scenes'],
            ['admin.content.mashhad-extras', 'أدوار وجوائز المشهد', '🎭', 'admin.content.mashhad-extras'],
        ],
        'التطبيق' => [
            ['admin.themes.index', 'الثيمات', '🎨', 'admin.themes.*'],
            ['admin.announcements.index', 'الإعلانات', '📣', 'admin.announcements.*'],
            ['admin.platform', 'مفاتيح التطبيق', '🛠️', 'admin.platform'],
        ],
        'الإدارة' => [
            ['admin.admins.index', 'المشرفون', '🛡️', 'admin.admins.*'],
            ['admin.audit.index', 'سجل التدقيق', '🧾', 'admin.audit.*'],
            ['admin.profile', 'حسابي', '⚙️', 'admin.profile'],
        ],
    ];
@endphp

<div class="shell" x-data="{ menu: false }">
    <aside class="sidebar" :class="{ 'is-open': menu }">
        <div class="brand">
            <span class="brand-mark">🎲</span>
            <span>ألعاب العيلة</span>
        </div>

        @foreach ($navigation as $group => $items)
            <div class="nav-group">{{ $group }}</div>
            @foreach ($items as [$route, $label, $icon, $pattern])
                {{-- رابط لشاشة لم تُسجَّل (مشرف غير أعلى مثلاً) لا يُعرض. --}}
                @if (Route::has($route))
                    <a href="{{ route($route) }}" wire:navigate
                       class="nav-link {{ request()->routeIs($pattern) ? 'is-active' : '' }}">
                        <span class="nav-icon">{{ $icon }}</span>
                        <span>{{ $label }}</span>
                    </a>
                @endif
            @endforeach
        @endforeach
    </aside>

    <div class="main">
        <header class="topbar">
            <button class="burger" type="button" @click="menu = !menu" aria-label="القائمة">☰</button>
            <div class="topbar-title">{{ $title ?? 'لوحة الإدارة' }}</div>
            @if ($admin)
                <span class="topbar-user">
                    {{ $admin->name }}
                    @if ($admin->is_super) <span class="badge b-blue">مشرف أعلى</span> @endif
                </span>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button class="btn btn-sm" type="submit">خروج</button>
                </form>
            @endif
        </header>

        <main class="content">
            {{ $slot }}
        </main>
    </div>
</div>

{{-- رسائل عابرة: من المكوّنات (notify) ومن التحويلات (session). --}}
<div class="toasts" x-data="adminToasts(@js(session('notify')))" @notify.window="push($event.detail)">
    <template x-for="toast in items" :key="toast.id">
        <div class="toast" :class="'toast-' + toast.type" x-text="toast.message" x-transition></div>
    </template>
</div>

@livewireScripts
<script>
    function adminToasts(initial) {
        return {
            items: [],
            init() {
                if (initial) this.push({ message: initial, type: 'success' });
            },
            push(detail) {
                const payload = Array.isArray(detail) ? detail[0] : detail;
                const toast = { id: Date.now() + Math.random(), message: payload.message, type: payload.type || 'success' };
                this.items.push(toast);
                setTimeout(() => this.items = this.items.filter(item => item.id !== toast.id), 3600);
            },
        };
    }
</script>
</body>
</html>
