<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Events;

use Carbon\CarbonImmutable;
use Phoenix1331\AiHeadroom\Enums\LimitType;

/**
 * A request reached a limit in report mode and was allowed through. Public API
 * covered by SemVer. Never contains prompt or response text.
 */
final readonly class HeadroomLow
{
    /**
     * @param  string  $reason  A generated description of the limit and figures, e.g. "85.2% of context used, limit 80%".
     */
    public function __construct(
        public string $requestId,
        public ?string $invocationId,
        public LimitType $limit,
        public float $limitValue,
        public float $actualValue,
        public string $reason,
        public CarbonImmutable $occurredAt,
    ) {}
}
