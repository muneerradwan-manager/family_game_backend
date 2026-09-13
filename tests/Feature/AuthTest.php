<?php

use App\Auth\JwtService;
use App\Models\User;

it('يوحّد الرقم إلى E.164 عند التسجيل', function () {
    $response = $this->postJson('/api/auth/register', [
        'phone' => '0791234567',
        'password' => 'secret123',
        'fullName' => 'أحمد محمد علي',
        'username' => 'ahmad_m',
        'gender' => 'male',
    ]);

    $response->assertCreated()
        ->assertJsonPath('user.username', 'ahmad_m')
        ->assertJsonPath('user.phone', '+962791234567')
        ->assertJsonStructure(['tokens' => ['access_token', 'refresh_token', 'expires_in']]);

    expect(User::first()->password_hash)->not->toBe('secret123');
});

it('يرفض تسجيل نفس الرقم بصيغة مختلفة', function () {
    makeUser('first', ['phone' => '+962791234567']);

    $this->postJson('/api/auth/register', [
        'phone' => '00962791234567',
        'password' => 'secret123',
        'fullName' => 'ثاني مستخدم هنا',
        'username' => 'second',
        'gender' => 'male',
    ])->assertStatus(422)->assertJsonValidationErrors('phone');
});

it('يرفض اسم مستخدم بأحرف عربية أو كبيرة', function () {
    foreach (['أحمد', 'Ahmad', 'ab'] as $username) {
        $this->postJson('/api/auth/register', [
            'phone' => '079123456'.random_int(1, 9),
            'password' => 'secret123',
            'fullName' => 'اسم ثلاثي كامل',
            'username' => $username,
            'gender' => 'male',
        ])->assertStatus(422)->assertJsonValidationErrors('username');
    }
});

it('يسجّل الدخول برقم محلي ويعيد توكنات', function () {
    makeUser('ahmad', ['phone' => '+962791234567', 'password_hash' => 'secret123']);

    $this->postJson('/api/auth/login', [
        'phone' => '0791234567',
        'password' => 'secret123',
    ])->assertOk()->assertJsonStructure(['tokens' => ['access_token', 'refresh_token']]);
});

it('لا يكشف إن كان الرقم مسجّلاً عند خطأ كلمة السر', function () {
    makeUser('ahmad', ['phone' => '+962791234567', 'password_hash' => 'secret123']);

    $wrongPassword = $this->postJson('/api/auth/login', [
        'phone' => '0791234567', 'password' => 'wrong-one',
    ]);

    $unknownPhone = $this->postJson('/api/auth/login', [
        'phone' => '0799999999', 'password' => 'secret123',
    ]);

    expect($wrongPassword->json('errors.phone'))->toBe($unknownPhone->json('errors.phone'));
});

it('يدوّر refresh token ويبطل القديم', function () {
    $user = makeUser('ahmad');
    $tokens = app(JwtService::class)->issueTokenPair($user);

    $this->postJson('/api/auth/refresh', ['refreshToken' => $tokens['refresh_token']])
        ->assertOk()
        ->assertJsonStructure(['tokens' => ['access_token', 'refresh_token']]);

    // التوكن المستعمَل مرة لا يعمل ثانية — هذا ما يحمي من توكن مسروق.
    $this->postJson('/api/auth/refresh', ['refreshToken' => $tokens['refresh_token']])
        ->assertStatus(401);
});

it('يمنع الوصول بلا توكن', function () {
    $this->getJson('/api/auth/me')->assertStatus(401);
});

it('يعيد الحساب مع توكن صالح', function () {
    $user = makeUser('ahmad');

    $this->getJson('/api/auth/me', authHeaders($user))
        ->assertOk()
        ->assertJsonPath('user.username', 'ahmad');
});
