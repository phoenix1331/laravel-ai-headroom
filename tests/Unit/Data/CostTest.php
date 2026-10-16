<?php

declare(strict_types=1);

use Phoenix1331\AiHeadroom\Data\Cost;
use Phoenix1331\AiHeadroom\Enums\Currency;

it('prices input and worst-case output per million tokens', function (): void {
    $cost = Cost::forTokens(
        inputTokens: 12_000,
        maxOutputTokens: 4_000,
        inputPricePerMillion: 3.00,
        outputPricePerMillion: 15.00,
        currency: Currency::Usd,
    );

    expect($cost->input)->toBe(0.036)
        ->and($cost->output)->toBe(0.06)
        ->and($cost->total())->toBe(0.096)
        ->and($cost->currency)->toBe(Currency::Usd);
});

it('costs nothing for zero tokens', function (): void {
    $cost = Cost::forTokens(0, 0, 3.00, 15.00, Currency::Usd);

    expect($cost->total())->toBe(0.0);
});

it('costs nothing for a free model', function (): void {
    $cost = Cost::forTokens(50_000, 8_000, 0.0, 0.0, Currency::Usd);

    expect($cost->total())->toBe(0.0);
});

it('removes floating point noise from totals', function (): void {
    expect((new Cost(0.1, 0.2, Currency::Usd))->total())->toBe(0.3)
        ->and((new Cost(0.15, 0.96, Currency::Usd))->total())->toBe(1.11);
});

it('keeps per-token precision on cheap models', function (): void {
    $cost = Cost::forTokens(1, 1, 0.05, 0.40, Currency::Usd);

    expect($cost->input)->toBe(0.00000005)
        ->and($cost->output)->toBe(0.0000004);
});

it('serialises to an array including the total', function (): void {
    expect((new Cost(0.25, 0.5, Currency::Gbp))->toArray())->toBe([
        'input' => 0.25,
        'output' => 0.5,
        'total' => 0.75,
        'currency' => 'GBP',
    ]);
});

it('converts to another currency at the given rate', function (): void {
    $gbp = (new Cost(0.10, 0.50, Currency::Usd))->convertTo(Currency::Gbp, 0.756);

    expect($gbp->input)->toBe(0.0756)
        ->and($gbp->output)->toBe(0.378)
        ->and($gbp->total())->toBe(0.4536)
        ->and($gbp->currency)->toBe(Currency::Gbp);
});

it('rejects a conversion rate of zero or less', function (float $rate): void {
    (new Cost(0.10, 0.50, Currency::Usd))->convertTo(Currency::Gbp, $rate);
})->with([0.0, -1.0])->throws(InvalidArgumentException::class, 'greater than zero');

it('rejects negative amounts', function (float $input, float $output): void {
    new Cost($input, $output, Currency::Usd);
})->with([
    'input' => [-0.01, 0.0],
    'output' => [0.0, -0.01],
])->throws(InvalidArgumentException::class, 'cannot be negative');

it('rejects negative token counts', function (int $input, int $output): void {
    Cost::forTokens($input, $output, 1.0, 1.0, Currency::Usd);
})->with([
    'input' => [-1, 0],
    'output' => [0, -1],
])->throws(InvalidArgumentException::class, 'cannot be negative');
