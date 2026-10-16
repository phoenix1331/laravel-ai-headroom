<?php

declare(strict_types=1);

use Phoenix1331\AiHeadroom\Counting\Contracts\Tokeniser;
use Phoenix1331\AiHeadroom\Counting\TiktokenTokeniser;
use Phoenix1331\AiHeadroom\Data\TokenCount;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroom\Enums\Currency;
use Phoenix1331\AiHeadroom\Facades\Headroom;
use Phoenix1331\AiHeadroom\HeadroomManager;
use Phoenix1331\AiHeadroom\Tests\Fixtures\WordTokeniser;

beforeEach(function (): void {
    config(['ai-headroom.method' => 'estimate']);
    app()->instance(Tokeniser::class, new WordTokeniser);
});

it('counts plain text for a given model', function (): void {
    $count = Headroom::count('where is my refund', model: 'gpt-6.1-sol');

    expect($count)->toBeInstanceOf(TokenCount::class)
        ->and($count->model())->toBe('gpt-6.1-sol')
        ->and($count->breakdown()['prompt'])->toBe(4)
        ->and($count->total())->toBe(4 + 6)
        ->and($count->method())->toBe(CountMethod::Estimated)
        ->and($count->contextWindow())->toBe(1_050_000)
        ->and($count->fits())->toBeTrue();
});

it('prices output at the model maximum for plain text', function (): void {
    $cost = Headroom::count('hello', model: 'gpt-6.1-sol')->estimatedCost();

    expect($cost->output)->toBe(128_000 * 10.00 / 1_000_000)
        ->and($cost->currency)->toBe(Currency::Usd);
});

it('reports cost in the configured currency', function (): void {
    config(['ai-headroom.currency' => 'GBP']);

    expect(Headroom::count('hello', model: 'gpt-6.1-sol')->estimatedCost()->currency)->toBe(Currency::Gbp);
});

it('applies the safety margin to non-OpenAI models', function (): void {
    config(['ai-headroom.estimate_safety_margin' => 0.25, 'ai-headroom.estimate_ratios.anthropic' => 1.0]);

    $count = Headroom::count('one two three four', model: 'claude-sonnet-5-5');

    expect($count->breakdown()['prompt'])->toBe(5)
        ->and($count->confidence())->toBe(0.25);
});

it('uses the configured default model', function (): void {
    config(['ai-headroom.default_model' => 'gemini-3.8-flash']);

    expect(Headroom::count('hello')->model())->toBe('gemini-3.8-flash');
});

it('falls back to laravel/ai default provider and its default text model', function (): void {
    config(['ai-headroom.default_model' => null, 'ai.default' => 'anthropic', 'ai.providers.anthropic' => ['driver' => 'anthropic', 'key' => 'test']]);

    expect(Headroom::count('hello')->model())->toBe('claude-sonnet-5-5');
});

it('uses the default provider for an unknown model', function (): void {
    config(['ai.default' => 'anthropic']);

    expect(Headroom::count('hello', model: 'my-private-model')->contextWindow())->toBe(128_000);
});

it('explains when no default model can be worked out', function (): void {
    config(['ai-headroom.default_model' => null, 'ai.default' => null]);

    Headroom::count('hello');
})->throws(InvalidArgumentException::class, 'set ai-headroom.default_model, or a string ai.default provider');

it('resolves the manager as a singleton behind the facade', function (): void {
    expect(Headroom::getFacadeRoot())->toBe(app(HeadroomManager::class));
});

it('binds the o200k tokeniser by default', function (): void {
    app()->forgetInstance(Tokeniser::class);

    expect(app(Tokeniser::class))->toBeInstanceOf(TiktokenTokeniser::class);
});

it('rejects an empty tokeniser cache path', function (): void {
    app()->forgetInstance(Tokeniser::class);
    config(['ai-headroom.tokeniser.cache_path' => '']);

    app(Tokeniser::class);
})->throws(InvalidArgumentException::class, 'Set ai-headroom.tokeniser.cache_path');
