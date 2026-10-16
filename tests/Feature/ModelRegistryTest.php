<?php

declare(strict_types=1);

use Laravel\Ai\Ai;
use Phoenix1331\AiHeadroom\Enums\Currency;
use Phoenix1331\AiHeadroom\Models\ModelRegistry;

it('is a singleton built from the shipped models and the app config', function (): void {
    config(['ai-headroom.models' => ['claude-sonnet-5-5' => ['input_price' => 1.80]]]);

    $registry = app(ModelRegistry::class);

    expect(app(ModelRegistry::class))->toBe($registry)
        ->and($registry->get('claude-sonnet-5-5')->inputPrice)->toBe(1.8)
        ->and($registry->get('claude-sonnet-5-5')->contextWindow)->toBe(1_000_000)
        ->and($registry->currency())->toBe(Currency::Usd)
        ->and($registry->lastChecked())->toMatch('/^\d{4}-\d{2}-\d{2}$/');
});

it('uses the configured fallback for unknown models', function (): void {
    config(['ai-headroom.fallback_model.context_window' => 32_000]);

    expect(app(ModelRegistry::class)->get('unknown-model')->contextWindow)->toBe(32_000);
});

it('ships a spec for every default, cheapest and smartest model laravel/ai uses', function (string $provider): void {
    config(["ai.providers.{$provider}" => ['driver' => $provider, 'key' => 'test']]);

    $textProvider = Ai::textProvider($provider);
    $registry = app(ModelRegistry::class);

    foreach ([$textProvider->defaultTextModel(), $textProvider->cheapestTextModel(), $textProvider->smartestTextModel()] as $model) {
        expect($registry->has($model))->toBeTrue("laravel/ai uses [{$model}] but resources/models.php has no entry for it.")
            ->and($registry->get($model)->provider)->toBe($provider);
    }
})->with(['anthropic', 'openai', 'gemini']);

it('reports costs in the configured currency', function (string $code, Currency $currency, float $input): void {
    config(['ai-headroom.currency' => $code]);

    $cost = app(ModelRegistry::class)->costFor('claude-sonnet-5-5', 1_000_000, 1);

    expect($cost->currency)->toBe($currency)
        ->and($cost->input)->toBe($input);
})->with([
    'usd' => ['USD', Currency::Usd, 2.0],
    'gbp' => ['GBP', Currency::Gbp, 1.512],
    'lowercase gbp' => ['gbp', Currency::Gbp, 1.512],
]);

it('uses the configured exchange rate', function (): void {
    config(['ai-headroom.currency' => 'GBP', 'ai-headroom.exchange_rates.GBP' => 0.8]);

    expect(app(ModelRegistry::class)->costFor('claude-sonnet-5-5', 1_000_000, 1)->input)->toBe(1.6);
});

it('rejects an unsupported currency with the supported list', function (): void {
    config(['ai-headroom.currency' => 'EUR']);

    app(ModelRegistry::class);
})->throws(InvalidArgumentException::class, 'Headroom does not support the currency [EUR]. Supported: USD, GBP.');
