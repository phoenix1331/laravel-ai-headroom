<?php

declare(strict_types=1);

use Phoenix1331\AiHeadroom\Currency\ConfiguredExchangeRates;
use Phoenix1331\AiHeadroom\Enums\Currency;

it('converts between currencies via their rate per USD', function (Currency $from, Currency $to, float $rate): void {
    expect((new ConfiguredExchangeRates(['GBP' => 0.8]))->rate($from, $to))->toBe($rate);
})->with([
    'usd to usd' => [Currency::Usd, Currency::Usd, 1.0],
    'usd to gbp' => [Currency::Usd, Currency::Gbp, 0.8],
    'gbp to usd' => [Currency::Gbp, Currency::Usd, 1.25],
    'gbp to gbp' => [Currency::Gbp, Currency::Gbp, 1.0],
]);

it('accepts numeric strings from env', function (): void {
    expect((new ConfiguredExchangeRates(['gbp' => '0.75']))->rate(Currency::Usd, Currency::Gbp))->toBe(0.75);
});

it('explains a missing rate', function (): void {
    (new ConfiguredExchangeRates([]))->rate(Currency::Usd, Currency::Gbp);
})->throws(InvalidArgumentException::class, 'No exchange rate is configured for [GBP]. Set ai-headroom.exchange_rates.GBP.');

it('rejects invalid rates', function (mixed $rate): void {
    new ConfiguredExchangeRates(['GBP' => $rate]);
})->with([0, -0.5, 'abc'])->throws(InvalidArgumentException::class, 'must be a number greater than zero');

it('rejects rates for unsupported currencies', function (): void {
    new ConfiguredExchangeRates(['EUR' => 0.9]);
})->throws(InvalidArgumentException::class, 'does not support the currency [EUR]');
