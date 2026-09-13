<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>دخول الإدارة · ألعاب العيلة</title>
    <link rel="stylesheet" href="{{ asset('admin-assets/admin.css') }}?v={{ @filemtime(public_path('admin-assets/admin.css')) }}">
</head>
<body>
<div class="auth-page">
    <form class="auth-card" method="POST" action="{{ route('admin.login.attempt') }}">
        @csrf

        <div class="brand-mark" style="width:52px;height:52px;font-size:26px;border-radius:14px">🎲</div>
        <h1>لوحة الإدارة</h1>
        <p class="muted" style="margin:0 0 22px">ألعاب العيلة — للمشرفين فقط.</p>

        <div class="stack">
            <div class="field">
                <label for="email">الإيميل</label>
                <input id="email" name="email" type="email" class="input ltr" value="{{ old('email') }}"
                       autocomplete="username" required autofocus>
                @error('email') <span class="error">{{ $message }}</span> @enderror
            </div>

            <div class="field">
                <label for="password">كلمة السر</label>
                <input id="password" name="password" type="password" class="input ltr"
                       autocomplete="current-password" required>
                @error('password') <span class="error">{{ $message }}</span> @enderror
            </div>

            <label class="check">
                <input type="checkbox" name="remember" value="1">
                <span>تذكّرني على هذا الجهاز</span>
            </label>

            <button class="btn btn-primary btn-block" type="submit">دخول</button>
        </div>
    </form>
</div>
</body>
</html>
