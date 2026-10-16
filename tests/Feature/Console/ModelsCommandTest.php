<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Phoenix1331\AiHeadroom\Currency\ConfiguredExchangeRates;
use Phoenix1331\AiHeadroom\Enums\Currency;
use Phoenix1331\AiHeadroom\Models\ModelRegistry;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00'));
});

$headers = ['Model', 'Provider', 'Source', 'Context', 'Max output', 'Input /M (USD)', 'Output /M (USD)', 'Cache read /M', 'Cache write /M', 'Long prompts'];

it('lists shipped models with windows, limits, prices and tiers', function () use ($headers): void {
    $this->artisan('headroom:models', ['--provider' => 'anthropic'])
        ->expectsTable($headers, [
            ['claude-fable-5-1', 'anthropic', 'shipped', '1,000,000', '128,000', '$10', '$50', '$0.25', '$12.5', '-'],
            ['claude-opus-5-5', 'anthropic', 'shipped', '1,000,000', '128,000', '$4', '$20', '$0.2', '$5', '-'],
            ['claude-sonnet-5-5', 'anthropic', 'shipped', '1,000,000', '128,000', '$2', '$10', '$0.1', '$2.5', '-'],
            ['claude-haiku-5-5', 'anthropic', 'shipped', '1,000,000', '128,000', '$0.1', '$0.5', '$0.01', '$0.125', 'over 100,000: $0.5 / $2.5'],
        ])
        ->expectsOutputToContain('128,000 context, 16,000 max output, $10 / $50 per million')
        ->expectsOutputToContain('2026-10-16 (10 days ago)')
        ->assertSuccessful();
});

it('lists every provider by default', function (): void {
    Artisan::call('headroom:models');

    expect(Artisan::output())
        ->toContain('claude-sonnet-5-5')
        ->toContain('gpt-6.1-sol')
        ->toContain('gemini-3.8-flash');
});

it('marks overridden and app-added models', function () use ($headers): void {
    config(['ai-headroom.models' => [
        'claude-sonnet-5-5' => ['input_price' => 1.80],
        'my-fine-tune' => ['provider' => 'custom', 'context_window' => 32_000, 'max_output_tokens' => 4_000, 'input_price' => 3, 'output_price' => 12],
    ]]);

    $this->artisan('headroom:models', ['--provider' => 'custom'])
        ->expectsTable($headers, [['my-fine-tune', 'custom', 'app', '32,000', '4,000', '$3', '$12', '-', '-', '-']])
        ->assertSuccessful();

    Artisan::call('headroom:models', ['--provider' => 'anthropic']);

    expect(Artisan::output())->toMatch('/claude-sonnet-5-5\s+\|\s+anthropic\s+\|\s+overridden\s+\|.*\$1\.8 /');
});

it('shows prices in the configured currency', function (): void {
    config(['ai-headroom.currency' => 'GBP', 'ai-headroom.exchange_rates.GBP' => 0.5]);

    Artisan::call('headroom:models', ['--provider' => 'anthropic']);

    expect(Artisan::output())
        ->toContain('Input /M (GBP)')
        ->toMatch('/claude-sonnet-5-5\s+\|.*£1 \s+\|\s+£5 /');
});

it('warns when the shipped prices are more than 60 days old', function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-01-01 12:00:00'));

    $this->artisan('headroom:models')
        ->expectsOutputToContain('Shipped prices were checked over 60 days ago')
        ->assertSuccessful();
});

it('outputs the registry as JSON', function (): void {
    config(['ai-headroom.models' => ['claude-sonnet-5-5' => ['input_price' => 1.80]]]);

    Artisan::call('headroom:models', ['--provider' => 'anthropic', '--json' => true]);

    $json = json_decode(Artisan::output(), true);

    expect($json['currency'])->toBe('USD')
        ->and($json['last_checked'])->toBe('2026-10-16')
        ->and(array_column($json['models'], 'model'))->toBe(['claude-fable-5-1', 'claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'])
        ->and($json['models'][2])->toMatchArray(['source' => 'overridden', 'input_price' => 1.8, 'cache_read_price' => 0.1])
        ->and($json['models'][3]['tiers'][0])->toBe(['above' => 100_000, 'input_price' => 0.5, 'output_price' => 2.5, 'cache_read_price' => 0.05, 'cache_write_price' => 0.625])
        ->and($json['fallback'])->toMatchArray(['source' => 'fallback', 'context_window' => 128_000]);
});

it('omits the checked date for a registry built without one', function (): void {
    app()->instance(ModelRegistry::class, new ModelRegistry(
        defaults: ['m' => ['provider' => 'custom', 'context_window' => 10, 'max_output_tokens' => 1, 'input_price' => 1, 'output_price' => 1]],
        overrides: [],
        fallback: ['context_window' => 10, 'max_output_tokens' => 1, 'input_price' => 1, 'output_price' => 1],
        currency: Currency::Usd,
        exchangeRates: new ConfiguredExchangeRates([]),
    ));

    $this->artisan('headroom:models')
        ->doesntExpectOutputToContain('Shipped prices checked')
        ->assertSuccessful();
});
