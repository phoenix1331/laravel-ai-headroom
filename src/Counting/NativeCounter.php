<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Counting;

use Laravel\Ai\AiManager;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Providers\Provider;
use LogicException;
use Phoenix1331\AiHeadroom\Counting\Contracts\Counter;
use Phoenix1331\AiHeadroom\Data\Tally;
use Phoenix1331\AiHeadroom\Enums\CountMethod;

/**
 * A counter backed by a provider's own token counting endpoint, called with
 * the credentials and base URL of the step's laravel/ai provider. Providers
 * only return the total, so the per-part breakdown is the local estimate's
 * split scaled to that total.
 */
abstract class NativeCounter implements Counter
{
    protected const int TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly EstimatingCounter $estimator,
        private readonly AiManager $ai,
        private readonly NativeCountCache $cache,
    ) {}

    /**
     * The provider's exact input token count for the step.
     */
    abstract protected function countTotal(Provider $provider, PendingStep $step): int;

    public function count(PendingStep $step): Tally
    {
        $total = $this->cache->remember(static::class, $step, fn (): int => $this->countTotal($this->provider($step), $step));
        $estimate = $this->estimator->count($step);

        $scale = $estimate->total > 0 ? $total / $estimate->total : 0.0;

        return new Tally(
            total: $total,
            breakdown: array_map(fn (int $tokens): int => (int) floor($tokens * $scale), $estimate->breakdown),
            method: CountMethod::Native,
            confidence: 0.0,
        );
    }

    private function provider(PendingStep $step): Provider
    {
        $provider = $this->ai->textProvider($step->provider);

        if (! $provider instanceof Provider) {
            throw new LogicException("The laravel/ai provider [{$step->provider}] cannot be used for native token counting.");
        }

        return $provider;
    }
}
