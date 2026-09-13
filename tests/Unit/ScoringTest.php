<?php

use App\Games\Harf\HarfScoring;
use App\Support\ArabicText;

const COLUMNS = ['name', 'animal', 'object', 'country', 'food'];

function scoreRound(array $answers, array $objections = [], ?string $stopBy = null): array
{
    return (new HarfScoring)->score(COLUMNS, $answers, $objections, array_keys($answers), $stopBy);
}

function row(array $overrides = []): array
{
    return array_merge(array_fill_keys(COLUMNS, ''), $overrides);
}

describe('تطبيع النص', function () {
    it('يوحّد الهمزات والتاء المربوطة والألف المقصورة', function () {
        expect(ArabicText::normalize('الأسد'))->toBe(ArabicText::normalize('أسد'))
            ->and(ArabicText::normalize('أسد'))->toBe(ArabicText::normalize('اسد'))
            ->and(ArabicText::normalize('مكة'))->toBe(ArabicText::normalize('مكه'))
            ->and(ArabicText::normalize('ليلى'))->toBe(ArabicText::normalize('ليلي'));
    });

    it('يحذف التشكيل والمسافات الزائدة', function () {
        expect(ArabicText::normalize('  أَسَــد   '))->toBe(ArabicText::normalize('اسد'));
    });

    it('لا يحذف ال من كلمة قصيرة', function () {
        // "ألم" ليست "ال" + "م".
        expect(ArabicText::normalize('الم'))->toBe('الم');
    });

    it('يتعرّف على بداية الكلمة بالحرف المسحوب', function () {
        expect(ArabicText::startsWithLetter('أسد', 'ا'))->toBeTrue()
            ->and(ArabicText::startsWithLetter('نمر', 'ا'))->toBeFalse()
            ->and(ArabicText::startsWithLetter('', 'ا'))->toBeFalse();
    });
});

describe('احتساب النقاط', function () {
    it('يمنح 10 للفريدة و5 للمكررة', function () {
        $scores = scoreRound([
            'a' => row(['name' => 'أحمد', 'animal' => 'أسد']),
            'b' => row(['name' => 'أمل', 'animal' => 'الأسد']),
            'c' => row(['name' => 'أنس', 'animal' => 'أرنب']),
        ]);

        expect($scores['a']['perColumn']['name'])->toBe(10)
            // "الأسد" و"أسد" إجابة واحدة بعد التطبيع.
            ->and($scores['a']['perColumn']['animal'])->toBe(5)
            ->and($scores['b']['perColumn']['animal'])->toBe(5)
            ->and($scores['c']['perColumn']['animal'])->toBe(10);
    });

    it('يمنح 15 للوحيد الذي أجاب في العمود', function () {
        $scores = scoreRound([
            'a' => row(['country' => 'الأردن']),
            'b' => row(),
            'c' => row(),
        ]);

        expect($scores['a']['perColumn']['country'])->toBe(15)
            ->and($scores['b']['perColumn']['country'])->toBe(0);
    });

    it('يصفّر الخانة التي سقطت بتصويت', function () {
        $scores = scoreRound(
            answers: [
                'a' => row(['name' => 'جزرة']),
                'b' => row(['name' => 'جمال']),
                'c' => row(['name' => 'جواد']),
            ],
            objections: [[
                'targetUserId' => 'a', 'column' => 'name', 'by' => 'b',
                'coObjectors' => [], 'votes' => [], 'verdict' => 'invalid',
            ]],
        );

        expect($scores['a']['perColumn']['name'])->toBe(0)
            ->and($scores['b']['perColumn']['name'])->toBe(10);
    });

    it('يعاقب المعترض الفاشل وشركاءه بخمس نقاط لكل منهم', function () {
        $scores = scoreRound(
            answers: ['a' => row(['name' => 'جزرة']), 'b' => row(), 'c' => row()],
            objections: [[
                'targetUserId' => 'a', 'column' => 'name', 'by' => 'b',
                'coObjectors' => ['c'], 'votes' => [], 'verdict' => 'valid',
            ]],
        );

        expect($scores['b']['penalties'])->toBe(-5)
            ->and($scores['c']['penalties'])->toBe(-5)
            ->and($scores['a']['penalties'])->toBe(0);
    });

    it('لا يعاقب على اعتراض لم يُناقَش', function () {
        $scores = scoreRound(
            answers: ['a' => row(['name' => 'جزرة']), 'b' => row(), 'c' => row()],
            objections: [[
                'targetUserId' => 'a', 'column' => 'name', 'by' => 'b',
                'coObjectors' => [], 'votes' => [], 'verdict' => 'auto_accepted',
            ]],
        );

        expect($scores['b']['penalties'])->toBe(0)
            ->and($scores['a']['perColumn']['name'])->toBe(15);
    });

    it('يمنح بونص الستوب حين تُقبل كل الخانات', function () {
        $full = row(['name' => 'أحمد', 'animal' => 'أسد', 'object' => 'إبرة', 'country' => 'الأردن', 'food' => 'أرز']);

        $scores = scoreRound(['a' => $full, 'b' => row(), 'c' => row()], stopBy: 'a');

        expect($scores['a']['stopBonus'])->toBe(10);
    });

    it('يطبّق عقوبة الستوب على خانة فارغة أو مرفوضة', function () {
        $partial = row(['name' => 'أحمد', 'animal' => 'أسد']);

        expect(scoreRound(['a' => $partial, 'b' => row(), 'c' => row()], stopBy: 'a')['a']['stopBonus'])
            ->toBe(-10);

        $full = row(['name' => 'أحمد', 'animal' => 'أسد', 'object' => 'إبرة', 'country' => 'الأردن', 'food' => 'أرز']);

        $scores = scoreRound(
            answers: ['a' => $full, 'b' => row(), 'c' => row()],
            objections: [[
                'targetUserId' => 'a', 'column' => 'food', 'by' => 'b',
                'coObjectors' => [], 'votes' => [], 'verdict' => 'invalid',
            ]],
            stopBy: 'a',
        );

        expect($scores['a']['stopBonus'])->toBe(-10);
    });

    it('يجمع الإجمالي من الأعمدة والبونص والعقوبات', function () {
        $full = row(['name' => 'أحمد', 'animal' => 'أسد', 'object' => 'إبرة', 'country' => 'الأردن', 'food' => 'أرز']);

        $scores = scoreRound(['a' => $full, 'b' => row(), 'c' => row()], stopBy: 'a');

        // خمسة أعمدة، وحده أجاب في كل منها: 5 × 15 + بونص الستوب.
        expect($scores['a']['total'])->toBe(85);
    });
});
