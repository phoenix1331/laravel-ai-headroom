<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Data;

use InvalidArgumentException;
use Phoenix1331\AiHeadroom\Enums\LimitType;

/**
 * Limits a request is checked against. The context window always applies;
 * these add tighter ceilings. Cost is in the configured currency.
 */
final readonly class Limits
{
    public function __construct(
        public ?float $maxPercentOfContext = null,
        public ?float $maxCost = null,
    ) {
        if ($maxPercentOfContext !== null && ($maxPercentOfContext <= 0 || $maxPercentOfContext > 100)) {
            throw new InvalidArgumentException('maxPercentOfContext must be greater than 0 and at most 100.');
        }

        if ($maxCost !== null && $maxCost <= 0) {
            throw new InvalidArgumentException('maxCost must be greater than zero.');
        }
    }

    /**
     * The limits the count goes over, in the order: context window, percent of context, cost.
     *
     * @return list<Breach>
     */
    public function breachesOf(TokenCount $count): array
    {
        $breaches = [];

        if (! $count->fits()) {
            $breaches[] = new Breach(LimitType::ContextWindow, (float) $count->contextWindow(), (float) $count->total());
        }

        // Compared unrounded: percentUsed() is rounded for display and would let 80.004% pass an 80% limit.
        $percent = $count->total() / $count->contextWindow() * 100;

        if ($this->maxPercentOfContext !== null && $percent > $this->maxPercentOfContext) {
            $breaches[] = new Breach(LimitType::PercentOfContext, $this->maxPercentOfContext, $percent);
        }

        $cost = $count->estimatedCost();

        if ($this->maxCost !== null && $cost->total() > $this->maxCost) {
            $breaches[] = new Breach(LimitType::Cost, $this->maxCost, $cost->total(), $cost->currency->value);
        }

        return $breaches;
    }
}
