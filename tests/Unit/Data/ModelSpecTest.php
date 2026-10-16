<?php

declare(strict_types=1);

use Phoenix1331\AiHeadroom\Data\ModelSpec;

function tieredSpec(): ModelSpec
{
    return new ModelSpec(
        name: 'tiered-model',
        provider: 'anthropic',
        contextWindow: 1_000_000,
        maxOutputTokens: 128_000,
        inputPrice: 0.10,
        outputPrice: 0.50,
        tiers: [
            ['above' => 500_000, 'input_price' => 1.00, 'output_price' => 5.00],
            ['above' => 100_000, 'input_price' => 0.50, 'output_price' => 2.50],
        ],
    );
}

it('uses base prices at or below the first tier threshold', function (int $tokens): void {
    expect(tieredSpec()->pricesFor($tokens))->toBe(['input_price' => 0.10, 'output_price' => 0.50]);
})->with([0, 99_999, 100_000]);

it('switches to the highest tier the prompt is above, whatever order tiers are listed in', function (int $tokens, float $input, float $output): void {
    expect(tieredSpec()->pricesFor($tokens))->toBe(['input_price' => $input, 'output_price' => $output]);
})->with([
    'just over 100k' => [100_001, 0.50, 2.50],
    'at 500k' => [500_000, 0.50, 2.50],
    'over 500k' => [500_001, 1.00, 5.00],
]);

it('prices output at the model maximum when the request sets no max tokens', function (): void {
    $cost = tieredSpec()->costFor(10_000, null, 'USD');

    expect($cost->input)->toBe(0.001)
        ->and($cost->output)->toBe(0.064);
});

it('prices output at the request max tokens, capped at the model maximum', function (?int $maxTokens, float $output): void {
    expect(tieredSpec()->costFor(10_000, $maxTokens, 'USD')->output)->toBe($output);
})->with([
    'below the cap' => [4_000, 0.002],
    'above the cap' => [500_000, 0.064],
]);

it('applies tier prices to output as well as input', function (): void {
    $cost = tieredSpec()->costFor(200_000, 1_000, 'USD');

    expect($cost->input)->toBe(0.1)
        ->and($cost->output)->toBe(0.0025);
});

it('rejects invalid specs', function (Closure $make, string $message): void {
    expect($make)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'zero window' => [fn () => new ModelSpec('m', null, 0, 1, 1.0, 1.0), 'positive context_window'],
    'zero max output' => [fn () => new ModelSpec('m', null, 1, 0, 1.0, 1.0), 'positive context_window and max_output_tokens'],
    'negative price' => [fn () => new ModelSpec('m', null, 1, 1, -1.0, 1.0), 'prices cannot be negative'],
    'negative tier' => [fn () => new ModelSpec('m', null, 1, 1, 1.0, 1.0, [['above' => -1, 'input_price' => 1.0, 'output_price' => 1.0]]), 'tier with a negative value'],
]);
