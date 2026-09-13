<?php

namespace App\Games\Harf;

use App\Games\GameModule;
use App\Games\State\GameStateStore;
use App\Models\Game;
use App\Models\User;

/**
 * وحدة "لعبة الحروف" — واجهتها نحو المنصّة.
 *
 * المنصّة لا تعرف شيئاً عن الحروف والأعمدة والاعتراضات؛ تتعامل مع هذا الصنف
 * فقط. المنطق كله في HarfEngine، وهذا غلاف يحقّق العقد المشترك.
 */
class HarfModule implements GameModule
{
    public function __construct(
        private readonly HarfEngine $engine,
        private readonly GameStateStore $store,
    ) {}

    public function type(): string
    {
        return HarfEngine::TYPE;
    }

    public function name(): string
    {
        return 'لعبة الحروف';
    }

    public function icon(): string
    {
        return '🔠';
    }

    public function description(): string
    {
        return 'اسم · حيوان · جماد · بلاد · طعام — حرف واحد والكل يكتب بنفس الوقت.';
    }

    public function minPlayers(): int
    {
        return (int) config('harf.limits.min_players');
    }

    public function maxPlayers(): int
    {
        return (int) config('harf.limits.max_players');
    }

    /**
     * شاشة الشروط: تعرّفها الوحدة، وتعرضها المنصّة بقالب موحّد.
     *
     * @return array<int, array<string, mixed>>
     */
    public function configSchema(): array
    {
        $defaults = config('harf.defaults');

        return [
            [
                'key' => 'sixthColumn',
                'type' => 'select',
                'label' => 'العمود السادس',
                'hint' => 'قائمة جاهزة لا إدخال حر — حتى تبقى الفئة قابلة للحكم.',
                'default' => $defaults['sixth_column'],
                'options' => array_merge(
                    [['value' => null, 'label' => 'بدون', 'level' => null]],
                    array_map(fn (array $option) => [
                        'value' => $option['key'],
                        'label' => $option['label'],
                        'level' => $option['level'],
                    ], config('harf.sixth_columns')),
                ),
            ],
            [
                'key' => 'roundsPerPlayer',
                'type' => 'choice',
                'label' => 'جولات لكل شخص',
                'hint' => 'إجمالي الجولات = عدد اللاعبين × هذا الرقم.',
                'default' => $defaults['rounds_per_player'],
                'options' => array_map(
                    fn (int $value) => ['value' => $value, 'label' => (string) $value],
                    config('harf.rounds_per_player_options'),
                ),
            ],
            [
                'key' => 'writeSeconds',
                'type' => 'choice',
                'label' => 'مدة الكتابة',
                'hint' => null,
                'default' => $defaults['write_seconds'],
                'options' => array_map(
                    fn (int $value) => ['value' => $value, 'label' => $value.' ثانية'],
                    config('harf.write_seconds_options'),
                ),
            ],
            [
                'key' => 'flexibleMode',
                'type' => 'toggle',
                'label' => 'الوضع المرن',
                'hint' => '3 أعمدة فقط (اسم، حيوان، طعام) و'.config('harf.flexible_write_seconds').' ثانية — للصغار وكبار السن.',
                'default' => $defaults['flexible_mode'],
                'options' => [],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalizeConfig(array $input): array
    {
        return HarfConfig::fromArray($input)->toArray();
    }

    /** @param array<string, mixed> $config */
    public function estimateSeconds(array $config, int $playerCount): int
    {
        return $this->engine->estimateSeconds($config, $playerCount);
    }

    public function openLobby(Game $game, User $host): void
    {
        $this->engine->openLobby($game, $host);
    }

    public function join(Game $game, User $user): void
    {
        $this->engine->join($game, $user);
    }

    public function leave(Game $game, User $user): void
    {
        $this->engine->leave($game, $user);
    }

    public function start(Game $game, User $host): void
    {
        $this->engine->start($game, $host);
    }

    public function endEarly(Game $game, User $host): void
    {
        $this->engine->endEarly($game, $host);
    }

    /** @return array<string, mixed> */
    public function snapshot(Game $game, User $viewer): array
    {
        return $this->engine->snapshot($game, $viewer) ?? [
            'gameId' => $game->id,
            'channelId' => $game->channel_id,
            'gameType' => $this->type(),
            'status' => $game->status,
            'config' => $game->config,
            'result' => $game->result,
            'round' => null,
        ];
    }

    public function abandon(Game $game): void
    {
        $this->store->forget($game->id);
    }
}
