<?php

namespace App\Games\Spy;

use App\Games\GameModule;
use App\Games\State\GameStateStore;
use App\Models\Game;
use App\Models\User;

/**
 * وحدة "لعبة الجاسوس" — واجهتها نحو المنصّة.
 *
 * المنصّة لا تعرف شيئاً عن الكلمة السرّية ولا عن الجاسوس؛ تتعامل مع هذا
 * الصنف فقط. المنطق كله في SpyEngine، وهذا غلاف يحقّق العقد المشترك.
 */
class SpyModule implements GameModule
{
    public function __construct(
        private readonly SpyEngine $engine,
        private readonly GameStateStore $store,
    ) {}

    public function type(): string
    {
        return SpyEngine::TYPE;
    }

    public function name(): string
    {
        return 'لعبة الجاسوس';
    }

    public function icon(): string
    {
        return '🕵️';
    }

    public function description(): string
    {
        return 'الكل بيعرف الكلمة إلا واحد — اسألوا، جاوبوا، وشوفوا مين بيمثّل.';
    }

    public function minPlayers(): int
    {
        return (int) config('spy.limits.min_players');
    }

    public function maxPlayers(): int
    {
        return (int) config('spy.limits.max_players');
    }

    /**
     * شاشة الشروط: تعرّفها الوحدة، وتعرضها المنصّة بقالب موحّد.
     *
     * @return array<int, array<string, mixed>>
     */
    public function configSchema(): array
    {
        $defaults = config('spy.defaults');

        $categories = [];

        foreach (config('spy.categories') as $key => $category) {
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
                'label' => 'مجموعة الكلمات',
                'hint' => 'المجموعة معروفة للجميع — حتى للجاسوس. الكلمة وحدها هي السرّ.',
                'default' => $defaults['category'],
                'options' => array_merge(
                    [['value' => null, 'label' => '🎲 مفاجأة', 'level' => null]],
                    $categories,
                ),
            ],
            [
                'key' => 'turnSeconds',
                'type' => 'choice',
                'label' => 'مدة الدور',
                'hint' => 'للسؤال وللجواب — لكل واحد منهما على حدة.',
                'default' => $defaults['turn_seconds'],
                'options' => array_map(
                    fn (int $value) => ['value' => $value, 'label' => $value.' ثانية'],
                    config('spy.turn_seconds_options'),
                ),
            ],
            [
                'key' => 'maxRounds',
                'type' => 'choice',
                'label' => 'أقصى عدد جولات',
                'hint' => 'إذا خلصت الجولات والجاسوس لسا بينكم — بيفوز.',
                'default' => $defaults['max_rounds'],
                'options' => array_map(
                    fn (int $value) => ['value' => $value, 'label' => (string) $value],
                    config('spy.max_rounds_options'),
                ),
            ],
            [
                'key' => 'lastGuess',
                'type' => 'toggle',
                'label' => 'فرصة الجاسوس الأخيرة',
                'hint' => 'إذا انكشف، بياخد فرصة يخمّن الكلمة — وإذا صحّت بيفوز رغم انكشافه.',
                'default' => $defaults['last_guess'],
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
        return SpyConfig::fromArray($input)->toArray();
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
