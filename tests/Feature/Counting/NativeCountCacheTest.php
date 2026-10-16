<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;
use Phoenix1331\AiHeadroom\Counting\AnthropicCounter;
use Phoenix1331\AiHeadroom\Counting\Contracts\Tokeniser;
use Phoenix1331\AiHeadroom\Counting\NativeCountCache;
use Phoenix1331\AiHeadroom\Counting\OpenAiCounter;
use Phoenix1331\AiHeadroom\Tests\Fixtures\WordTokeniser;

beforeEach(function (): void {
    app()->instance(Tokeniser::class, new WordTokeniser);
    config([
        'ai.providers.anthropic' => ['driver' => 'anthropic', 'key' => 'sk-ant-test'],
        'ai-headroom.cache.enabled' => true,
        'cache.stores.headroom' => ['driver' => 'array'],
    ]);
    Http::fake(['api.anthropic.com/*' => Http::response(responseFixture('anthropic/count_tokens.json'))]);
});

function cachedStep(string $prompt = 'Where is my refund?'): PendingStep
{
    return new PendingStep(0, true, 'anthropic', 'claude-sonnet-5-5', null, [new UserMessage($prompt)], [], null, null);
}

it('is off unless the app opts in', function (): void {
    $defaults = require __DIR__.'/../../../config/ai-headroom.php';

    expect($defaults['cache']['enabled'])->toBeFalse()
        ->and($defaults['cache']['ttl'])->toBe(86_400);
});

it('reads the opt-in and ttl from the environment', function (): void {
    putenv('HEADROOM_CACHE=true');
    putenv('HEADROOM_CACHE_TTL=3600');

    try {
        $config = require __DIR__.'/../../../config/ai-headroom.php';
    } finally {
        putenv('HEADROOM_CACHE');
        putenv('HEADROOM_CACHE_TTL');
    }

    expect($config['cache']['enabled'])->toBeTrue()
        ->and($config['cache']['ttl'])->toBe(3600);
});

it('counts the same request natively only once', function (): void {
    $counter = app(AnthropicCounter::class);

    expect($counter->count(cachedStep())->total)->toBe(2095)
        ->and($counter->count(cachedStep())->total)->toBe(2095);

    Http::assertSentCount(1);
});

it('counts a different request again', function (): void {
    $counter = app(AnthropicCounter::class);

    $counter->count(cachedStep('Where is my refund?'));
    $counter->count(cachedStep('Where is my order?'));

    Http::assertSentCount(2);
});

it('calls the provider every time when the cache is disabled', function (): void {
    config(['ai-headroom.cache.enabled' => false]);
    $counter = app(AnthropicCounter::class);

    $counter->count(cachedStep());
    $counter->count(cachedStep());

    Http::assertSentCount(2);
});

it('counts again once the ttl has passed', function (): void {
    config(['ai-headroom.cache.ttl' => 60]);
    $counter = app(AnthropicCounter::class);

    $counter->count(cachedStep());
    $this->travel(61)->seconds();
    $counter->count(cachedStep());

    Http::assertSentCount(2);
});

it('uses the configured cache store and stores only the count under a hashed key', function (): void {
    config(['ai-headroom.cache.store' => 'headroom']);

    app(AnthropicCounter::class)->count(cachedStep());

    $key = app(NativeCountCache::class)->key(AnthropicCounter::class, cachedStep());

    expect($key)->toMatch('/^headroom:native-count:[a-f0-9]{64}$/')
        ->and(Cache::store('headroom')->get($key))->toBe(2095)
        ->and(Cache::store()->get($key))->toBeNull();
});

it('keys counts by counter as well as content', function (): void {
    $cache = app(NativeCountCache::class);

    expect($cache->key(AnthropicCounter::class, cachedStep()))->not->toBe($cache->key(OpenAiCounter::class, cachedStep()));
});

it('ignores a cached value that is not a count', function (): void {
    Cache::put(app(NativeCountCache::class)->key(AnthropicCounter::class, cachedStep()), 'corrupt', 60);

    expect(app(AnthropicCounter::class)->count(cachedStep())->total)->toBe(2095);

    Http::assertSentCount(1);
});

it('rejects a ttl of zero or less', function (): void {
    config(['ai-headroom.cache.ttl' => 0]);

    app(NativeCountCache::class);
})->throws(InvalidArgumentException::class, 'cache ttl must be a number of seconds greater than zero');
