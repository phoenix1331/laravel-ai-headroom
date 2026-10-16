<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Counting;

use Laravel\Ai\PendingStep;
use Phoenix1331\AiHeadroom\Counting\Contracts\Counter;
use Phoenix1331\AiHeadroom\Data\Tally;
use Phoenix1331\AiHeadroom\Enums\CountMethod;

/**
 * A counter backed by a provider's own token counting endpoint. Providers only
 * return the total, so the per-part breakdown is the local estimate's split
 * scaled to that total.
 */
abstract class NativeCounter implements Counter
{
    public function __construct(private readonly EstimatingCounter $estimator) {}

    /**
     * The provider's exact input token count for the step.
     */
    abstract protected function countTotal(PendingStep $step): int;

    public function count(PendingStep $step): Tally
    {
        $total = $this->countTotal($step);
        $estimate = $this->estimator->count($step);

        $scale = $estimate->total > 0 ? $total / $estimate->total : 0.0;

        return new Tally(
            total: $total,
            breakdown: array_map(fn (int $tokens): int => (int) floor($tokens * $scale), $estimate->breakdown),
            method: CountMethod::Native,
            confidence: 0.0,
        );
    }
}
