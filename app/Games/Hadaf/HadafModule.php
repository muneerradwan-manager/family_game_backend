<?php

namespace App\Games\Hadaf;

use App\Games\GameModule;
use App\Games\State\GameStateStore;
use App\Models\Game;
use App\Models\User;

/**
 * وحدة "لعبة الهدف" — واجهتها نحو المنصّة.
 *
 * المنطق كله في HadafEngine، وهذا غلاف يحقّق العقد المشترك.
 */
class HadafModule implements GameModule
{
    public function __construct(
        private readonly HadafEngine $engine,
        private readonly GameStateStore $store,
    ) {}

    public function type(): string
    {
        return HadafEngine::TYPE;
    }

    public function name(): string
    {
        return 'لعبة الهدف';
    }

    public function icon(): string
    {
        return '🎯';
    }

    public function description(): string
    {
        return 'تحدّي واحد والكل ينطلق معاً — الأسرع والأدقّ بياخد النقاط.';
    }

    public function minPlayers(): int
    {
        return (int) config('hadaf.limits.min_players');
    }

    public function maxPlayers(): int
    {
        return (int) config('hadaf.limits.max_players');
    }

    /** @return array<int, array<string, mixed>> */
    public function configSchema(): array
    {
        $defaults = config('hadaf.defaults');

        $categories = [];

        foreach (config('hadaf.categories') as $key => $category) {
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
                'label' => 'المجموعة',
                'hint' => 'المزيج بيخلط كل المجموعات — وهو الأمتع للعيلة.',
                'default' => $defaults['category'],
                'options' => array_merge(
                    [['value' => null, 'label' => '🎲 مزيج', 'level' => null]],
                    $categories,
                ),
            ],
            [
                'key' => 'rounds',
                'type' => 'choice',
                'label' => 'عدد الجولات',
                'hint' => 'الصعوبة بتتدرّج: أول الجولات سهلة وآخرها صعبة.',
                'default' => $defaults['rounds'],
                'options' => array_map(
                    fn (int $value) => ['value' => $value, 'label' => (string) $value],
                    config('hadaf.rounds_options'),
                ),
            ],
            [
                'key' => 'questionSeconds',
                'type' => 'choice',
                'label' => 'وقت السؤال',
                'hint' => null,
                'default' => $defaults['question_seconds'],
                'options' => array_map(
                    fn (int $value) => ['value' => $value, 'label' => $value.' ثانية'],
                    config('hadaf.question_seconds_options'),
                ),
            ],
            [
                'key' => 'flexibleMode',
                'type' => 'toggle',
                'label' => 'الوضع المرن',
                'hint' => 'أسئلة سهلة فقط و'.config('hadaf.flexible.question_seconds')
                    .' ثانية — للصغار وكبار السن.',
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
        return HadafConfig::fromArray($input)->toArray();
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
