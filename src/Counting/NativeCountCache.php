<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Counting;

use Closure;
use Illuminate\Contracts\Cache\Factory;
use InvalidArgumentException;
use Laravel\Ai\PendingStep;

/**
 * Caches native token totals by counter and step fingerprint, so the same
 * request is never sent to a counting endpoint twice within the TTL. Stores
 * the hash and the count only.
 */
class NativeCountCache
{
    private const string PREFIX = 'headroom:native-count:';

    public function __construct(
        private readonly Factory $cache,
        private readonly bool $enabled,
        private readonly ?string $store,
        private readonly int $ttl,
    ) {
        if ($ttl <= 0) {
            throw new InvalidArgumentException('The Headroom cache ttl must be a number of seconds greater than zero.');
        }
    }

    /**
     * @param  Closure(): int  $count
     */
    public function remember(string $counter, PendingStep $step, Closure $count): int
    {
        if (! $this->enabled) {
            return $count();
        }

        $repository = $this->cache->store($this->store);
        $key = $this->key($counter, $step);

        $cached = $repository->get($key);

        if (is_int($cached)) {
            return $cached;
        }

        $tokens = $count();

        $repository->put($key, $tokens, $this->ttl);

        return $tokens;
    }

    public function key(string $counter, PendingStep $step): string
    {
        return self::PREFIX.hash('sha256', $counter.'|'.StepFingerprint::of($step));
    }
}
