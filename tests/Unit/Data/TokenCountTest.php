<?php

declare(strict_types=1);

use Phoenix1331\AiHeadroom\Data\Cost;
use Phoenix1331\AiHeadroom\Data\TokenCount;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroom\Enums\Currency;

function tokenCount(int $total = 50_000, int $contextWindow = 200_000, array $breakdown = [], float $confidence = 0.02): TokenCount
{
    return new TokenCount(
        total: $total,
        breakdown: $breakdown,
        method: CountMethod::Native,
        confidence: $confidence,
        model: 'claude-sonnet-5-5',
        contextWindow: $contextWindow,
        estimatedCost: new Cost(0.15, 0.96, Currency::Usd),
    );
}

it('exposes what it was built with', function (): void {
    $count = tokenCount();

    expect($count->total())->toBe(50_000)
        ->and($count->method())->toBe(CountMethod::Native)
        ->and($count->confidence())->toBe(0.02)
        ->and($count->model())->toBe('claude-sonnet-5-5')
        ->and($count->contextWindow())->toBe(200_000)
        ->and($count->estimatedCost()->total())->toEqualWithDelta(1.11, 1e-9);
});

it('fills missing breakdown parts with zero, in send order', function (): void {
    $count = tokenCount(breakdown: ['prompt' => 12, 'history' => 4_000]);

    expect($count->breakdown())->toBe([
        'instructions' => 0,
        'history' => 4_000,
        'tools' => 0,
        'attachments' => 0,
        'prompt' => 12,
    ]);
});

it('keeps the total separate from the breakdown sum', function (): void {
    $count = tokenCount(total: 1_030, breakdown: ['instructions' => 400, 'prompt' => 600]);

    expect($count->total())->toBe(1_030)
        ->and(array_sum($count->breakdown()))->toBe(1_000);
});

it('works out context usage', function (int $total, int $window, float $percent, int $remaining, bool $fits): void {
    $count = tokenCount(total: $total, contextWindow: $window);

    expect($count->percentUsed())->toBe($percent)
        ->and($count->remaining())->toBe($remaining)
        ->and($count->fits())->toBe($fits);
})->with([
    'empty' => [0, 200_000, 0.0, 200_000, true],
    'quarter' => [50_000, 200_000, 25.0, 150_000, true],
    'rounded to two places' => [1, 3, 33.33, 2, true],
    'exactly full' => [200_000, 200_000, 100.0, 0, true],
    'one over' => [200_001, 200_000, 100.0, -1, false],
    'well over' => [300_000, 200_000, 150.0, -100_000, false],
]);

it('serialises to an array', function (): void {
    $count = tokenCount(total: 1_030, breakdown: ['instructions' => 400, 'prompt' => 600]);

    expect($count->toArray())->toBe([
        'total' => 1_030,
        'breakdown' => [
            'instructions' => 400,
            'history' => 0,
            'tools' => 0,
            'attachments' => 0,
            'prompt' => 600,
        ],
        'method' => 'native',
        'confidence' => 0.02,
        'model' => 'claude-sonnet-5-5',
        'context_window' => 200_000,
        'percent_used' => 0.52,
        'remaining' => 198_970,
        'fits' => true,
        'estimated_cost' => [
            'input' => 0.15,
            'output' => 0.96,
            'total' => 1.11,
            'currency' => 'USD',
        ],
    ]);
});

it('rejects invalid values', function (Closure $make, string $message): void {
    expect($make)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'negative total' => [fn () => tokenCount(total: -1), 'total cannot be negative'],
    'zero context window' => [fn () => tokenCount(contextWindow: 0), 'context window must be greater than zero'],
    'confidence below zero' => [fn () => tokenCount(confidence: -0.1), 'between 0 and 1'],
    'confidence above one' => [fn () => tokenCount(confidence: 1.5), 'between 0 and 1'],
    'unknown part' => [fn () => tokenCount(breakdown: ['system' => 10]), 'Unknown breakdown parts: system'],
    'negative part' => [fn () => tokenCount(breakdown: ['tools' => -5]), '[tools] token count cannot be negative'],
]);
