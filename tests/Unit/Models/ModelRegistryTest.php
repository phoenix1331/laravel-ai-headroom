<?php

declare(strict_types=1);

use Phoenix1331\AiHeadroom\Currency\ConfiguredExchangeRates;
use Phoenix1331\AiHeadroom\Enums\Currency;
use Phoenix1331\AiHeadroom\Models\ModelRegistry;

function registry(array $overrides = [], ?array $fallback = null, Currency $currency = Currency::Usd): ModelRegistry
{
    return new ModelRegistry(
        defaults: [
            'shipped-model' => [
                'provider' => 'anthropic',
                'context_window' => 200_000,
                'max_output_tokens' => 64_000,
                'input_price' => 3.00,
                'output_price' => 15.00,
                'tiers' => [
                    ['above' => 100_000, 'input_price' => 6.00, 'output_price' => 22.50],
                    ['above' => 150_000, 'input_price' => 9.00, 'output_price' => 30.00],
                ],
            ],
        ],
        overrides: $overrides,
        fallback: $fallback ?? [
            'context_window' => 128_000,
            'max_output_tokens' => 16_000,
            'input_price' => 10.00,
            'output_price' => 50.00,
        ],
        currency: $currency,
        exchangeRates: new ConfiguredExchangeRates(['GBP' => 0.75]),
        lastChecked: '2026-10-16',
    );
}

it('returns the spec for a known model', function (): void {
    $spec = registry()->get('shipped-model');

    expect($spec->name)->toBe('shipped-model')
        ->and($spec->provider)->toBe('anthropic')
        ->and($spec->contextWindow)->toBe(200_000)
        ->and($spec->maxOutputTokens)->toBe(64_000)
        ->and($spec->inputPrice)->toBe(3.0)
        ->and($spec->outputPrice)->toBe(15.0)
        ->and($spec->tiers)->toHaveCount(2)
        ->and($spec->isFallback)->toBeFalse();
});

it('falls back to cautious values for an unknown model, keeping its name', function (): void {
    $registry = registry();
    $spec = $registry->get('mystery-model');

    expect($registry->has('mystery-model'))->toBeFalse()
        ->and($spec->name)->toBe('mystery-model')
        ->and($spec->provider)->toBeNull()
        ->and($spec->contextWindow)->toBe(128_000)
        ->and($spec->inputPrice)->toBe(10.0)
        ->and($spec->isFallback)->toBeTrue();
});

it('overrides only the fields given for a shipped model', function (): void {
    $spec = registry(['shipped-model' => ['input_price' => 2.50]])->get('shipped-model');

    expect($spec->inputPrice)->toBe(2.5)
        ->and($spec->outputPrice)->toBe(15.0)
        ->and($spec->contextWindow)->toBe(200_000);
});

it('replaces tiers whole rather than merging them by position', function (): void {
    $spec = registry(['shipped-model' => ['tiers' => [['above' => 50_000, 'input_price' => 4.00, 'output_price' => 20.00]]]])->get('shipped-model');

    expect($spec->tiers)->toBe([['above' => 50_000, 'input_price' => 4.0, 'output_price' => 20.0]]);
});

it('adds models from the overrides', function (): void {
    $registry = registry(['my-fine-tune' => [
        'provider' => 'openai',
        'context_window' => 128_000,
        'max_output_tokens' => 16_000,
        'input_price' => '3.00',
        'output_price' => 12,
    ]]);

    expect($registry->has('my-fine-tune'))->toBeTrue()
        ->and($registry->get('my-fine-tune')->inputPrice)->toBe(3.0)
        ->and($registry->get('my-fine-tune')->outputPrice)->toBe(12.0)
        ->and(array_keys($registry->all()))->toBe(['shipped-model', 'my-fine-tune']);
});

it('exposes the currency and last checked date', function (): void {
    expect(registry()->currency())->toBe(Currency::Usd)
        ->and(registry()->lastChecked())->toBe('2026-10-16');
});

it('explains what is wrong with an invalid model entry', function (array $overrides, string $message): void {
    expect(fn () => registry($overrides))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'not an array' => [['broken' => 'yes'], 'Model [broken] in the Headroom model registry must be an array'],
    'missing window' => [['broken' => ['max_output_tokens' => 1, 'input_price' => 1, 'output_price' => 1]], 'Model [broken] is missing a numeric [context_window]'],
    'non-numeric price' => [['shipped-model' => ['input_price' => 'free']], 'Model [shipped-model] is missing a numeric [input_price]'],
    'tiers not an array' => [['shipped-model' => ['tiers' => 'none']], 'Model [shipped-model] tiers must be an array'],
    'incomplete tier' => [['shipped-model' => ['tiers' => [['above' => 1]]]], 'Model [shipped-model] has a tier without numeric'],
]);

it('validates the fallback too', function (): void {
    registry(fallback: ['context_window' => 128_000]);
})->throws(InvalidArgumentException::class, 'Model [fallback] is missing a numeric [max_output_tokens]');

it('rejects a malformed shipped models file', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'headroom').'.php';
    file_put_contents($path, '<?php return ["models" => []];');

    try {
        ModelRegistry::withShippedModels([], [], Currency::Usd, new ConfiguredExchangeRates([]), $path);
    } finally {
        unlink($path);
    }
})->throws(InvalidArgumentException::class, 'must return [last_checked => string, models => array]');

it('prices a prompt in USD by default', function (): void {
    $cost = registry()->costFor('shipped-model', 10_000, 1_000);

    expect($cost->currency)->toBe(Currency::Usd)
        ->and($cost->input)->toBe(0.03)
        ->and($cost->output)->toBe(0.015);
});

it('converts the price into the configured currency', function (): void {
    $cost = registry(currency: Currency::Gbp)->costFor('shipped-model', 10_000, 1_000);

    expect($cost->currency)->toBe(Currency::Gbp)
        ->and($cost->input)->toBe(0.0225)
        ->and($cost->output)->toBe(0.01125);
});

it('prices unknown models with the fallback', function (): void {
    expect(registry()->costFor('mystery-model', 1_000_000, null)->total())->toBe(10.8);
});
