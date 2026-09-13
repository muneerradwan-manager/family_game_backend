<?php

namespace App\Games\Harf;

use App\Support\ArabicText;

/**
 * احتساب نقاط جولة واحدة. يجري على السيرفر حصراً — الأجهزة لا تحسب نقاطاً أبداً.
 */
class HarfScoring
{
    /**
     * @param  array<int, string>  $columns  مفاتيح أعمدة هذه اللعبة
     * @param  array<string, array<string, string>>  $answers  {userId: {column: text}}
     * @param  array<int, array<string, mixed>>  $objections  الاعتراضات بعد حسم التصويت
     * @param  array<int, string>  $playerIds  اللاعبون المحتسَبون (بلا متفرّجين)
     * @return array<string, array<string, mixed>> {userId: {perColumn, stopBonus, penalties, total}}
     */
    public function score(
        array $columns,
        array $answers,
        array $objections,
        array $playerIds,
        ?string $stopBy,
    ): array {
        $points = config('harf.points');
        $rejected = $this->rejectedCells($objections);

        $result = [];
        foreach ($playerIds as $userId) {
            $result[$userId] = [
                'perColumn' => array_fill_keys($columns, 0),
                'stopBonus' => 0,
                'penalties' => 0,
                'total' => 0,
            ];
        }

        foreach ($columns as $column) {
            // الإجابات المقبولة في هذا العمود: غير فارغة ولم يسقطها تصويت.
            $accepted = [];
            foreach ($playerIds as $userId) {
                $text = trim((string) ($answers[$userId][$column] ?? ''));

                if ($text === '' || isset($rejected[$userId.'|'.$column])) {
                    continue;
                }

                $accepted[$userId] = ArabicText::normalize($text);
            }

            if ($accepted === []) {
                continue;
            }

            // الوحيد الذي أجاب في العمود كله.
            if (count($accepted) === 1) {
                $only = array_key_first($accepted);
                $result[$only]['perColumn'][$column] = $points['sole_answer'];

                continue;
            }

            $frequency = array_count_values($accepted);

            foreach ($accepted as $userId => $normalized) {
                $result[$userId]['perColumn'][$column] = $frequency[$normalized] > 1
                    ? $points['duplicate']
                    : $points['unique'];
            }
        }

        // بونص/عقوبة الستوب: مشروطة بقبول كل الخانات — تجعل الستوب قراراً لا سباقاً أعمى.
        if ($stopBy !== null && isset($result[$stopBy])) {
            $result[$stopBy]['stopBonus'] = $this->stopperCleanSweep($columns, $answers, $rejected, $stopBy)
                ? $points['stop_bonus']
                : $points['stop_penalty'];
        }

        // الاعتراض الفاشل يكلّف — بدونه يصير الاعتراض مجانياً على كل شيء.
        foreach ($objections as $objection) {
            if (($objection['verdict'] ?? null) !== 'valid') {
                continue;
            }

            $losers = array_merge([$objection['by']], $objection['coObjectors'] ?? []);

            foreach (array_unique($losers) as $userId) {
                if (isset($result[$userId])) {
                    $result[$userId]['penalties'] += $points['failed_objection'];
                }
            }
        }

        foreach ($result as $userId => $row) {
            $result[$userId]['total'] = array_sum($row['perColumn']) + $row['stopBonus'] + $row['penalties'];
        }

        return $result;
    }

    /**
     * خانات سقطت بتصويت "خطأ".
     *
     * @param  array<int, array<string, mixed>>  $objections
     * @return array<string, true>
     */
    private function rejectedCells(array $objections): array
    {
        $rejected = [];

        foreach ($objections as $objection) {
            if (($objection['verdict'] ?? null) === 'invalid') {
                $rejected[$objection['targetUserId'].'|'.$objection['column']] = true;
            }
        }

        return $rejected;
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<string, array<string, string>>  $answers
     * @param  array<string, true>  $rejected
     */
    private function stopperCleanSweep(array $columns, array $answers, array $rejected, string $stopBy): bool
    {
        foreach ($columns as $column) {
            $text = trim((string) ($answers[$stopBy][$column] ?? ''));

            if ($text === '' || isset($rejected[$stopBy.'|'.$column])) {
                return false;
            }
        }

        return true;
    }
}
