<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Data;

use InvalidArgumentException;

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
}
