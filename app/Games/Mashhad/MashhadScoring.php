<?php

namespace App\Games\Mashhad;

/**
 * نقاط مشهد واحد — السيرفر حصراً.
 *
 * الادّعاء وحده لا يعطي نقاطاً: يعطيها الادّعاء **الذي نجا**. والاعتراض ليس
 * مجانياً أيضاً — من يعترض بلا وجه حق يدفع، وإلا صار الاعتراض على الجميع
 * استراتيجيةً بلا كلفة.
 */
class MashhadScoring
{
    /**
     * @param  array<string, array<string, mixed>>  $cast  الأدوار الموزّعة
     * @param  array<string, array<string, bool>>  $claims  {userId: {main, bonus, event}}
     * @param  array<int, array<string, mixed>>  $challenges  الاعتراضات وأحكامها
     * @param  array<int, string>  $playerIds
     * @return array<string, array<string, int|bool>>
     */
    public function score(array $cast, array $claims, array $challenges, array $playerIds): array
    {
        $points = config('mashhad.points');
        $rows = [];

        // حكمُ كل ادّعاء: مرفوض فقط إن اعتُرض عليه وسقط بالتصويت.
        $rejected = [];
        $failedBy = [];

        foreach ($challenges as $challenge) {
            if ($challenge['verdict'] === 'rejected') {
                $rejected[$challenge['targetUserId']][$challenge['kind']] = true;

                continue;
            }

            // الاعتراض سقط: يدفع صاحبه وشركاؤه.
            if ($challenge['verdict'] === 'upheld') {
                foreach (array_merge([$challenge['by']], $challenge['coChallengers']) as $userId) {
                    $failedBy[$userId] = ($failedBy[$userId] ?? 0) + 1;
                }
            }
        }

        foreach ($playerIds as $userId) {
            $role = $cast[$userId] ?? null;
            $claim = $claims[$userId] ?? [];

            $main = 0;
            $bonus = 0;
            $hard = 0;
            $event = 0;
            $penalties = 0;

            $mainClaimed = (bool) ($claim['main'] ?? false);
            $mainRejected = (bool) ($rejected[$userId]['main'] ?? false);

            if ($mainClaimed && ! $mainRejected) {
                $main = (int) $points['main_goal'];

                // الدور الصعب ليس اختيار صاحبه، فيستحق أكثر حين ينجح فيه.
                if (($role['difficulty'] ?? 'medium') === 'hard') {
                    $hard = (int) $points['hard_role'];
                }
            } elseif ($mainClaimed && $mainRejected) {
                $penalties += (int) $points['false_claim'];
            }

            $bonusClaimed = (bool) ($claim['bonus'] ?? false);

            if ($bonusClaimed && ! ($rejected[$userId]['bonus'] ?? false)) {
                $bonus = (int) $points['bonus_goal'];
            } elseif ($bonusClaimed) {
                $penalties += (int) $points['false_claim'];
            }

            $eventClaimed = (bool) ($claim['event'] ?? false);

            if ($eventClaimed && ! ($rejected[$userId]['event'] ?? false)) {
                $event = (int) $points['event_used'];
            } elseif ($eventClaimed) {
                $penalties += (int) $points['false_claim'];
            }

            $penalties += ($failedBy[$userId] ?? 0) * (int) $points['failed_challenge'];

            $rows[$userId] = [
                'main' => $main,
                'bonus' => $bonus,
                'hard' => $hard,
                'event' => $event,
                'penalties' => $penalties,
                'total' => $main + $bonus + $hard + $event + $penalties,
                'mainAchieved' => $mainClaimed && ! $mainRejected,
                'bonusAchieved' => $bonusClaimed && ! ($rejected[$userId]['bonus'] ?? false),
                'eventAchieved' => $eventClaimed && ! ($rejected[$userId]['event'] ?? false),
            ];
        }

        return $rows;
    }
}
