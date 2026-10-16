<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Events;

use Carbon\CarbonImmutable;
use Phoenix1331\AiHeadroom\Data\Cost;
use Phoenix1331\AiHeadroom\Data\TokenCount;
use Phoenix1331\AiHeadroom\Enums\CountMethod;

/**
 * An agent request was counted, before it is sent. Public API covered by SemVer.
 * Never contains prompt or response text.
 */
final readonly class TokensCounted
{
    /**
     * @param  class-string|null  $agent
     * @param  array{instructions: int, history: int, tools: int, attachments: int, prompt: int}  $breakdown
     * @param  array<string, scalar>  $context
     */
    public function __construct(
        public string $requestId,
        public ?string $invocationId,
        public ?string $agent,
        public ?string $provider,
        public string $model,
        public CountMethod $countMethod,
        public int $estimatedInputTokens,
        public array $breakdown,
        public int $contextWindow,
        public float $percentUsed,
        public Cost $estimatedCost,
        public array $context,
        public CarbonImmutable $occurredAt,
    ) {}

    public static function from(TokenCount $count): self
    {
        return new self(
            requestId: $count->requestId(),
            invocationId: $count->invocationId(),
            agent: $count->agent(),
            provider: $count->provider(),
            model: $count->model(),
            countMethod: $count->method(),
            estimatedInputTokens: $count->total(),
            breakdown: $count->breakdown(),
            contextWindow: $count->contextWindow(),
            percentUsed: $count->percentUsed(),
            estimatedCost: $count->estimatedCost(),
            context: $count->context(),
            occurredAt: CarbonImmutable::now(),
        );
    }
}
