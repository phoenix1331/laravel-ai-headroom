<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Events;

use Carbon\CarbonImmutable;
use Phoenix1331\AiHeadroom\Data\TokenCount;
use Phoenix1331\AiHeadroom\Enums\LimitType;

/**
 * A request breached a limit and is about to be stopped with TokenLimitExceeded.
 * Fired before the exception is thrown, so blocks are recorded even when the app
 * catches it. Public API covered by SemVer. Never contains prompt or response text.
 */
final readonly class RequestBlocked
{
    public function __construct(
        public string $requestId,
        public ?string $invocationId,
        public LimitType $limit,
        public float $limitValue,
        public float $actualValue,
        public TokenCount $count,
        public CarbonImmutable $occurredAt,
    ) {}
}
