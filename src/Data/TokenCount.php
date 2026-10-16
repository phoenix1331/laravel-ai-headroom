<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Data;

use InvalidArgumentException;
use Phoenix1331\AiHeadroom\Enums\CountMethod;

/**
 * How many input tokens a request uses, where they come from, and how that
 * compares with the model's context window. Holds numbers only, never content.
 */
final readonly class TokenCount
{
    /** The parts of a request, in the order they are sent. */
    public const array PARTS = ['instructions', 'history', 'tools', 'attachments', 'prompt'];

    /** @var array{instructions: int, history: int, tools: int, attachments: int, prompt: int} */
    private array $breakdown;

    /**
     * @param  int  $total  Tokens for the whole request. Can differ from the breakdown's sum, because providers add message and tool overhead that belongs to no single part.
     * @param  array<string, int>  $breakdown  Tokens per part; keys from PARTS, missing parts count as zero.
     * @param  float  $confidence  Expected relative error of the total, e.g. 0.02 for plus or minus 2%.
     */
    public function __construct(
        private int $total,
        array $breakdown,
        private CountMethod $method,
        private float $confidence,
        private string $model,
        private int $contextWindow,
        private Cost $estimatedCost,
    ) {
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
     * @return array{total: int, breakdown: array{instructions: int, history: int, tools: int, attachments: int, prompt: int}, method: string, confidence: float, model: string, context_window: int, percent_used: float, remaining: int, fits: bool, estimated_cost: array{input: float, output: float, total: float, currency: string}}
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'breakdown' => $this->breakdown,
            'method' => $this->method->value,
            'confidence' => $this->confidence,
            'model' => $this->model,
            'context_window' => $this->contextWindow,
            'percent_used' => $this->percentUsed(),
            'remaining' => $this->remaining(),
            'fits' => $this->fits(),
            'estimated_cost' => $this->estimatedCost->toArray(),
        ];
    }
}
