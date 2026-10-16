<?php

declare(strict_types=1);

use Phoenix1331\AiHeadroom\Enums\Currency;

it('resolves a currency from its code, ignoring case and spaces', function (string $code, Currency $currency): void {
    expect(Currency::fromCode($code))->toBe($currency);
})->with([
    ['USD', Currency::Usd],
    ['gbp', Currency::Gbp],
    [' Gbp ', Currency::Gbp],
]);

it('names the supported currencies when given an unknown code', function (): void {
    Currency::fromCode('EUR');
})->throws(InvalidArgumentException::class, 'Headroom does not support the currency [EUR]. Supported: USD, GBP.');

it('has a symbol for every currency', function (Currency $currency, string $symbol): void {
    expect($currency->symbol())->toBe($symbol);
})->with([
    [Currency::Usd, '$'],
    [Currency::Gbp, '£'],
]);
