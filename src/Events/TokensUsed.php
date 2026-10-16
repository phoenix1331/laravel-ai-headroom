<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Events;

use Carbon\CarbonImmutable;
use Phoenix1331\AiHeadroom\Data\Cost;
use Phoenix1331\AiHeadroom\Enums\CountMethod;

/**
 * A counted request completed and the provider reported its usage. Public API
 * covered by SemVer. Never contains prompt or response text.
 *
 * $provider and $model are what actually answered, which can differ from the
 * counted ones after failover. $countMethod and $estimatedInputTokens repeat
 * the pre-flight count so consumers (and calibration) can measure its error.
 */
final readonly class TokensUsed
{
    /**
     * @param  class-string|null  $agent
     * @param  int|null  $cacheReadTokens  Subset of $inputTokens read from a prompt cache, when reported.
     * @param  int|null  $cacheWriteTokens  Subset of $inputTokens written to a prompt cache, when reported.
     * @param  array<string, scalar>  $context
     */
    public function __construct(
        public string $requestId,
        public ?string $invocationId,
        public ?string $agent,
        public ?string $provider,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public ?int $cacheReadTokens,
        public ?int $cacheWriteTokens,
        public Cost $cost,
        public bool $streamed,
        public CountMethod $countMethod,
        public int $estimatedInputTokens,
        public array $context,
        public CarbonImmutable $occurredAt,
    ) {}
}
