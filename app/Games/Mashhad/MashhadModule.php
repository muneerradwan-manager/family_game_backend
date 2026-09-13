<?php

namespace App\Games\Mashhad;

use App\Games\GameModule;
use App\Games\State\GameStateStore;
use App\Models\Game;
use App\Models\User;

/**
 * وحدة "لعبة المشهد" — واجهتها نحو المنصّة.
 *
 * المنطق كله في MashhadEngine، وهذا غلاف يحقّق العقد المشترك.
 */
class MashhadModule implements GameModule
{
    public function __construct(
        private readonly MashhadEngine $engine,
        private readonly GameStateStore $store,
    ) {}

    public function type(): string
    {
        return MashhadEngine::TYPE;
    }

    public function name(): string
    {
        return 'لعبة المشهد';
    }

    public function icon(): string
    {
        return '🎬';
    }

    public function description(): string
    {
        return 'قصة وحدة وكل واحد فيها شخصية وهدف سرّي — مثّل، فاوض، واقلب الأحداث لصالحك.';
    }

    public function minPlayers(): int
    {
        return (int) config('mashhad.limits.min_players');
    }

    public function maxPlayers(): int
    {
        return (int) config('mashhad.limits.max_players');
    }

    /** @return array<int, array<string, mixed>> */
    public function configSchema(): array
    {
        $defaults = config('mashhad.defaults');

        $categories = [];

        foreach (config('mashhad.categories') as $key => $category) {
            $categories[] = [
                'value' => $key,
                'label' => $category['emoji'].' '.$category['label'],
                'level' => null,
            ];
        }

        return [
            [
                'key' => 'category',
                'type' => 'select',
                'label' => 'نوع المشاهد',
                'hint' => 'المزيج بيخلط كل الأنواع — والمفاجأة نصّ المتعة.',
                'default' => $defaults['category'],
                'options' => array_merge(
                    [['value' => null, 'label' => '🎲 مزيج', 'level' => null]],
                    $categories,
                ),
            ],
            [
                'key' => 'scenes',
                'type' => 'choice',
                'label' => 'عدد المشاهد',
                'hint' => 'كل مشهد قصة مستقلة بأدوار وأهداف جديدة.',
                'default' => $defaults['scenes'],
                'options' => array_map(
                    fn (int $value) => ['value' => $value, 'label' => (string) $value],
                    config('mashhad.scenes_options'),
                ),
            ],
            [
                'key' => 'sceneSeconds',
                'type' => 'choice',
                'label' => 'مدة المشهد',
                'hint' => null,
                'default' => $defaults['scene_seconds'],
                'options' => array_map(
                    fn (int $value) => [
                        'value' => $value,
                        'label' => intdiv($value, 60).' دقائق',
                    ],
                    config('mashhad.scene_seconds_options'),
                ),
            ],
            [
                'key' => 'familyMode',
                'type' => 'toggle',
                'label' => 'الوضع العائلي',
                'hint' => 'أدوار أبسط بلا أسرار ولا أهداف إضافية، و'
                    .intdiv((int) config('mashhad.family.scene_seconds'), 60)
                    .' دقائق للمشهد — للصغار وكبار السن.',
                'default' => $defaults['family_mode'],
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
        return MashhadConfig::fromArray($input)->toArray();
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
