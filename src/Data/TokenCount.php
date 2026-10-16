<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Data;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Phoenix1331\AiHeadroom\Enums\CountMethod;

/**
 * How many input tokens a request uses, where they come from, and how that
 * compares with the model's context window. Holds figures, identifiers and
 * scalar context tags only, never prompt or response content.
 */
final readonly class TokenCount
{
    /** The parts of a request, in the order they are sent. */
    public const array PARTS = ['instructions', 'history', 'tools', 'attachments', 'prompt'];

    /** @var array{instructions: int, history: int, tools: int, attachments: int, prompt: int} */
    private array $breakdown;

    private string $requestId;

    /**
     * @param  int  $total  Tokens for the whole request. Can differ from the breakdown's sum, because providers add message and tool overhead that belongs to no single part.
     * @param  array<string, int>  $breakdown  Tokens per part; keys from PARTS, missing parts count as zero.
     * @param  float  $confidence  Expected relative error of the total, e.g. 0.02 for plus or minus 2%.
     * @param  string|null  $requestId  ULID linking this count to the usage reported after the request; generated when null.
     * @param  string|null  $provider  The laravel/ai provider name the request was counted for.
     * @param  class-string|null  $agent  The agent class, or null for plain text.
     * @param  string|null  $invocationId  The SDK's ID for the agent run this step belongs to, when known.
     * @param  array<string, scalar>  $context  Tags from Headroom::resolveContextUsing().
     */
    public function __construct(
        private int $total,
        array $breakdown,
        private CountMethod $method,
        private float $confidence,
        private string $model,
        private int $contextWindow,
        private Cost $estimatedCost,
        ?string $requestId = null,
        private ?string $provider = null,
        private ?string $agent = null,
        private ?string $invocationId = null,
        private array $context = [],
    ) {
        $this->requestId = $requestId ?? (string) Str::ulid();

        if ($total < 0) {
            throw new InvalidArgumentException('The token total cannot be negative.');
        }

        if ($contextWindow <= 0) {
            throw new InvalidArgumentException('The context window must be greater than zero.');
        }

        if ($confidence < 0 || $confidence > 1) {
            throw new InvalidArgumentException('Confidence must be between 0 and 1.');
        }

        $unknown = array_diff(array_keys($breakdown), self::PARTS);

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown breakdown parts: '.implode(', ', $unknown).'.');
        }

        foreach ($breakdown as $part => $tokens) {
            if ($tokens < 0) {
                throw new InvalidArgumentException("The [{$part}] token count cannot be negative.");
            }
        }

        $this->breakdown = [
            'instructions' => $breakdown['instructions'] ?? 0,
            'history' => $breakdown['history'] ?? 0,
            'tools' => $breakdown['tools'] ?? 0,
            'attachments' => $breakdown['attachments'] ?? 0,
            'prompt' => $breakdown['prompt'] ?? 0,
        ];
    }

    /**
     * ULID shared by every event about this request.
     */
    public function requestId(): string
    {
        return $this->requestId;
    }

    public function provider(): ?string
    {
        return $this->provider;
    }

    /**
     * @return class-string|null
     */
    public function agent(): ?string
    {
        return $this->agent;
    }

    public function invocationId(): ?string
    {
        return $this->invocationId;
    }

    /**
     * @return array<string, scalar>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function total(): int
    {
        return $this->total;
    }

    /**
     * @return array{instructions: int, history: int, tools: int, attachments: int, prompt: int}
     */
    public function breakdown(): array
    {
        return $this->breakdown;
    }

    public function method(): CountMethod
    {
        return $this->method;
    }

    public function confidence(): float
    {
        return $this->confidence;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function contextWindow(): int
    {
        return $this->contextWindow;
    }

    /**
     * Share of the context window used, from 0 upwards; above 100 means it does not fit.
     */
    public function percentUsed(): float
    {
        return round($this->total / $this->contextWindow * 100, 2);
    }

    /**
     * Tokens left in the context window; negative when the request overflows it.
     */
    public function remaining(): int
    {
        return $this->contextWindow - $this->total;
    }

    public function fits(): bool
    {
        return $this->total <= $this->contextWindow;
    }

    public function estimatedCost(): Cost
    {
        return $this->estimatedCost;
    }

    /**
     * @return array{request_id: string, provider: string|null, agent: string|null, invocation_id: string|null, model: string, total: int, breakdown: array{instructions: int, history: int, tools: int, attachments: int, prompt: int}, method: string, confidence: float, context_window: int, percent_used: float, remaining: int, fits: bool, estimated_cost: array{input: float, output: float, total: float, currency: string, approximate: bool}, context: array<string, scalar>}
     */
    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'provider' => $this->provider,
            'agent' => $this->agent,
            'invocation_id' => $this->invocationId,
            'model' => $this->model,
            'total' => $this->total,
            'breakdown' => $this->breakdown,
            'method' => $this->method->value,
            'confidence' => $this->confidence,
            'context_window' => $this->contextWindow,
            'percent_used' => $this->percentUsed(),
            'remaining' => $this->remaining(),
            'fits' => $this->fits(),
            'estimated_cost' => $this->estimatedCost->toArray(),
            'context' => $this->context,
        ];
    }
}
