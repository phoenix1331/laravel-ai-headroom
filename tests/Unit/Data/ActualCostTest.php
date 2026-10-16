<?php

declare(strict_types=1);

use Phoenix1331\AiHeadroom\Data\ModelSpec;

function cachedSpec(?float $read = 0.10, ?float $write = 2.50, array $tiers = []): ModelSpec
{
    return new ModelSpec('m', 'anthropic', 1_000_000, 1_000, 2.00, 10.00, $tiers, false, $read, $write);
}

it('prices uncached input, output and both cache kinds separately', function (): void {
    $cost = cachedSpec()->actualCostFor(1_000_000, 100_000, cacheReadTokens: 500_000, cacheWriteTokens: 200_000);

    expect($cost->input)->toBe((300_000 * 2.00 + 500_000 * 0.10 + 200_000 * 2.50) / 1_000_000)
        ->and($cost->output)->toBe(1.0)
        ->and($cost->approximate)->toBeFalse();
});

it('is exact when no cache tokens are reported, whether or not cache prices exist', function (?int $read): void {
    $cost = cachedSpec(read: null, write: null)->actualCostFor(1_000_000, 0, cacheReadTokens: $read);

    expect($cost->total())->toBe(2.0)
        ->and($cost->approximate)->toBeFalse();
})->with([null, 0]);

it('prices cache tokens as normal input and flags approximate when the price is missing', function (?float $read, ?float $write, int $readTokens, int $writeTokens): void {
    $cost = cachedSpec($read, $write)->actualCostFor(1_000_000, 0, $readTokens, $writeTokens);

    expect($cost->approximate)->toBeTrue();
})->with([
    'missing read price' => [null, 2.50, 500_000, 0],
    'missing write price' => [0.10, null, 0, 200_000],
]);

it('uses tier cache prices above the tier threshold, and treats a tier without them as unknown', function (): void {
    $priced = cachedSpec(tiers: [['above' => 100_000, 'input_price' => 4.0, 'output_price' => 20.0, 'cache_read_price' => 0.2, 'cache_write_price' => 5.0]]);
    $unpriced = cachedSpec(tiers: [['above' => 100_000, 'input_price' => 4.0, 'output_price' => 20.0]]);

    expect($priced->actualCostFor(1_000_000, 0, 1_000_000)->total())->toBe(0.2)
        ->and($priced->actualCostFor(1_000_000, 0, 1_000_000)->approximate)->toBeFalse()
        ->and($unpriced->actualCostFor(1_000_000, 0, 1_000_000)->total())->toBe(4.0)
        ->and($unpriced->actualCostFor(1_000_000, 0, 1_000_000)->approximate)->toBeTrue();
});

it('rejects negative cache prices', function (): void {
    cachedSpec(read: -0.1);
})->throws(InvalidArgumentException::class, 'prices cannot be negative');

it('rejects negative tier cache prices', function (): void {
    cachedSpec(tiers: [['above' => 1, 'input_price' => 1.0, 'output_price' => 1.0, 'cache_read_price' => -1.0]]);
})->throws(InvalidArgumentException::class, 'tier with a negative value');
