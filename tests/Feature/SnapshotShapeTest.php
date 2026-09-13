<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| شكل اللقطة: الخريطة تبقى خريطة
|--------------------------------------------------------------------------
|
| PHP يسلسل المصفوفة الترابطية الفارغة إلى `[]` لا `{}`. فحقلٌ عقدُه خريطة
| يصل الجهازَ قائمةً فارغة متى كان فارغاً — وهذا ما أسقط جلسة كاملة: التحويل
| على الجهاز رمى استثناءً قبل أن تُبنى اللقطة أصلاً.
|
| الفحص هنا على **النص الخام** لا على `->json()`: الأخير يفكّ `{}` و`[]` إلى
| مصفوفة PHP واحدة، فيخفي الفرق الذي نبحث عنه بالضبط.
|
*/

beforeEach(function () {
    Queue::fake();
});

/**
 * قراءة حقل من الردّ بلا تحويل الكائنات إلى مصفوفات.
 *
 * @param  array<int, string>  $path
 */
function rawField(TestResponse $response, array $path): mixed
{
    $cursor = json_decode($response->getContent());

    foreach ($path as $key) {
        $cursor = $cursor->{$key} ?? null;
    }

    return $cursor;
}

it('يرسل إجابات الحروف كخريطة حتى قبل أن يكتب اللاعب', function () {
    $table = seatedGame($this);
    $game = $table['game'];

    advancePhase($game); // سحب تلقائي
    advancePhase($game); // كشف الحرف ← الكتابة

    // ما كتب أحد شيئاً بعد: هنا كانت العلّة.
    $response = $this->getJson("/api/games/{$game}", authHeaders($table['players'][0]))
        ->assertOk();

    expect(rawField($response, ['state', 'round', 'myAnswers']))
        ->toBeInstanceOf(stdClass::class);

    // وبعد الكتابة تبقى خريطة بمحتواها.
    answer($this, $table['players'][0], $game, 'name', 'أحمد');

    $filled = rawField(
        $this->getJson("/api/games/{$game}", authHeaders($table['players'][0])),
        ['state', 'round', 'myAnswers'],
    );

    expect($filled)->toBeInstanceOf(stdClass::class)
        ->and($filled->name)->toBe('أحمد');
});

it('يرسل نقاط جولة الحروف كخريطة في اللوحة', function () {
    $table = seatedGame($this);
    $game = $table['game'];

    drawAndWrite($this, $table);
    advancePhase($game); // العرض ← الاعتراض
    advancePhase($game); // الاعتراض ← اللوحة

    $response = $this->getJson("/api/games/{$game}", authHeaders($table['players'][0]))
        ->assertOk();

    expect(rawField($response, ['state', 'round', 'roundScores']))
        ->toBeInstanceOf(stdClass::class);
});

it('يرسل أصوات جوائز المشهد كخريطة قبل أن يصوّت أحد', function () {
    $this->artisan('mashhad:import');

    $table = seatedMashhadGame($this, ['scenes' => 1]);
    $game = $table['game'];

    playScene($this, $table);
    advanceMashhad($game); // اللوحة ← الجوائز

    $response = $this->getJson("/api/games/{$game}", authHeaders($table['players'][0]))
        ->assertOk();

    expect($response->json('state.round.phase'))->toBe('awards')
        ->and(rawField($response, ['state', 'round', 'myAwardVotes']))
        ->toBeInstanceOf(stdClass::class);
});
