<?php

namespace App\Games\State;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use RuntimeException;

/**
 * تنفيذ الحالة الحيّة فوق مخزن كاش يدعم الأقفال الذرّية.
 *
 * الإنتاج: redis (مشترك بين السيرفرات + أقفال ذرّية حقيقية).
 * التطوير/الاختبار: أي مخزن آخر مضبوط في GAME_STATE_STORE.
 *
 * نخزّن حالة الجلسة كلها في مفتاح واحد: البيانات صغيرة (20 لاعباً كحد أقصى)
 * والتعديلات كلها ذرّية بطبيعتها، فينتفي خطر التعديل الجزئي المتشابك.
 */
class CacheGameStateStore implements GameStateStore
{
    /** الحالة الحيّة تعيش بعمر الجلسة فقط ثم تُرحَّل نتيجتها إلى القاعدة. */
    private const TTL_SECONDS = 6 * 3600;

    private const LOCK_SECONDS = 10;

    private const LOCK_WAIT_SECONDS = 5;

    public function __construct(private readonly Repository $cache) {}

    public function get(string $gameId): ?array
    {
        return $this->cache->get($this->key($gameId));
    }

    public function put(string $gameId, array $state): void
    {
        $this->cache->put($this->key($gameId), $state, self::TTL_SECONDS);
    }

    public function forget(string $gameId): void
    {
        $this->cache->forget($this->key($gameId));
    }

    public function mutate(string $gameId, Closure $mutator): mixed
    {
        $store = $this->cache->getStore();

        if (! method_exists($store, 'lock')) {
            throw new RuntimeException('مخزن الحالة الحيّة لا يدعم الأقفال — استخدم redis.');
        }

        $lock = $store->lock('game-state-lock:'.$gameId, self::LOCK_SECONDS);

        return $lock->block(self::LOCK_WAIT_SECONDS, function () use ($gameId, $mutator) {
            [$next, $return] = $mutator($this->get($gameId));

            if ($next !== null) {
                $this->put($gameId, $next);
            }

            return $return;
        });
    }

    private function key(string $gameId): string
    {
        return 'game:'.$gameId.':state';
    }
}
