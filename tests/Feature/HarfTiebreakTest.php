<?php

use App\Models\Game;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

/**
 * تعادل حقيقي في الصدارة: نُدوِّر ترتيب من يكمل أولاً بين الجولات، فيأخذ كل
 * لاعب بونص ستوب واحداً وتتساوى النتائج الثلاث تماماً.
 */
function playTiedGame($test): array
{
    $table = seatedGame($test);
    $game = $table['game'];

    for ($round = 0; $round < 3; $round++) {
        advancePhase($game); // سحب تلقائي
        advancePhase($game); // كشف الحرف ← الكتابة

        // الجولة الأولى يبدأ اللاعب 0، والثانية اللاعب 1، والثالثة اللاعب 2.
        for ($offset = 0; $offset < 3; $offset++) {
            $index = ($round + $offset) % 3;
            fillAllColumns($test, $table['players'][$index], $game, ['أ', 'ب', 'ج'][$index]);
        }

        advancePhase($game); // العرض ← الاعتراض
        advancePhase($game); // الاعتراض ← اللوحة
        advancePhase($game); // اللوحة ← التالي (أو النهاية)
    }

    return $table;
}

it('يفتح جولة حسم عند تعادل الصدارة بدل إعلان فائز عشوائي', function () {
    $table = playTiedGame($this);
    $state = gameState($table['game']);

    expect($state)->not->toBeNull()
        ->and($state['round']['phase'])->toBe('tiebreak')
        ->and($state['round']['tiedPlayers'])->toHaveCount(3)
        ->and($state['round']['letter'])->toBeIn(config('harf.letters'))
        ->and(Game::find($table['game'])->status)->toBe(Game::STATUS_PLAYING);
});

it('يحسم لأول إجابة صحيحة تصل', function () {
    $table = playTiedGame($this);
    $game = $table['game'];
    $letter = gameState($game)['round']['letter'];

    $winner = $table['players'][2];

    $this->postJson("/api/games/{$game}/harf/tiebreak", [
        'text' => $letter.'وووو',
    ], authHeaders($winner))->assertStatus(202);

    $record = Game::find($game);

    expect($record->status)->toBe(Game::STATUS_FINISHED)
        ->and($record->result['winnerId'])->toBe($winner->id)
        ->and($record->result['decidedByTiebreak'])->toBeTrue();
});

it('يتجاهل إجابة لا تبدأ بالحرف المسحوب', function () {
    $table = playTiedGame($this);
    $game = $table['game'];
    $letter = gameState($game)['round']['letter'];

    // نختار حرفاً مختلفاً عن المسحوب.
    $wrong = collect(config('harf.letters'))->first(fn ($candidate) => $candidate !== $letter);

    $this->postJson("/api/games/{$game}/harf/tiebreak", [
        'text' => $wrong.'وووو',
    ], authHeaders($table['players'][0]))->assertStatus(202);

    expect(Game::find($game)->status)->toBe(Game::STATUS_PLAYING);
});

it('يحسم بالأسبق انضماماً حين تنتهي مهلة الحسم بلا إجابة', function () {
    $table = playTiedGame($this);
    $game = $table['game'];

    advancePhase($game);

    $record = Game::find($game);

    expect($record->status)->toBe(Game::STATUS_FINISHED)
        ->and($record->result['winnerId'])->toBe($table['players'][0]->id)
        ->and($record->result['decidedByTiebreak'])->toBeFalse();
});
