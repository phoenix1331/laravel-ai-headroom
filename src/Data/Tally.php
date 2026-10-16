<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Data;

use InvalidArgumentException;
use Phoenix1331\AiHeadroom\Enums\CountMethod;

/**
 * What a counter returns: token figures only. HeadroomManager adds the model's
 * context window and cost to turn it into a TokenCount.
 */
final readonly class Tally
{
    /**
     * @param  array{instructions: int, history: int, tools: int, attachments: int, prompt: int}  $breakdown
     * @param  float  $confidence  Expected relative error of the total.
     */
    public function __construct(
        public int $total,
        public array $breakdown,
        public CountMethod $method,
        public float $confidence,
    ) {
        if ($total < 0) {
            throw new InvalidArgumentException('A tally total cannot be negative.');
        }
    }
}
