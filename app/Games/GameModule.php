<?php

namespace App\Games;

use App\Models\Game;
use App\Models\User;

/**
 * عقد وحدة اللعبة.
 *
 * المنصّة توفّر للعبة: هوية اللاعبين، القناة، الإشعارات، القناة الحيّة،
 * وتخزين النتيجة النهائية. اللعبة تنفّذ منطقها الداخلي فقط.
 * لعبة جديدة = صنف جديد يحقّق هذه الواجهة، بلا مساس بالتسجيل واللوبي والدعوات.
 */
interface GameModule
{
    /** معرّف فريد يُخزَّن في games.game_type. */
    public function type(): string;

    public function name(): string;

    /** أيقونة اللعبة (إيموجي) — تعرضها المنصّة في الكتالوج والسجل. */
    public function icon(): string;

    public function description(): string;

    public function minPlayers(): int;

    public function maxPlayers(): int;

    /**
     * وصف شاشة الشروط — تعرضها المنصّة بقالب موحّد بلا معرفة بمحتواها.
     *
     * @return array<int, array<string, mixed>>
     */
    public function configSchema(): array;

    /**
     * تحقّق من شروط أرسلها المنشئ وأعِدها مكتملة بالقيم الافتراضية.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalizeConfig(array $input): array;

    /**
     * المدة المتوقعة بالثواني — تُعرض حيّة في اللوبي مع كل انضمام.
     *
     * @param  array<string, mixed>  $config
     */
    public function estimateSeconds(array $config, int $playerCount): int;

    /** تهيئة الحالة الحيّة لحظة فتح الغرفة. */
    public function openLobby(Game $game, User $host): void;

    // نوايا دورة الحياة — مشتركة لكل الألعاب، تنفّذها كل وحدة بطريقتها.

    public function join(Game $game, User $user): void;

    public function leave(Game $game, User $user): void;

    public function start(Game $game, User $host): void;

    public function endEarly(Game $game, User $host): void;

    /** لقطة كاملة للحالة كما يراها هذا المستخدم — للانضمام وإعادة الاتصال. */
    public function snapshot(Game $game, User $viewer): array;

    /** تنظيف الحالة الحيّة عند التخلي عن اللعبة. */
    public function abandon(Game $game): void;
}
