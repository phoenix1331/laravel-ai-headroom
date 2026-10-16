<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;
use Phoenix1331\AiHeadroom\Counting\AnthropicCounter;
use Phoenix1331\AiHeadroom\Counting\Contracts\Counter;
use Phoenix1331\AiHeadroom\Counting\Contracts\Tokeniser;
use Phoenix1331\AiHeadroom\Counting\CounterResolver;
use Phoenix1331\AiHeadroom\Counting\EstimatingCounter;
use Phoenix1331\AiHeadroom\Counting\GeminiCounter;
use Phoenix1331\AiHeadroom\Counting\OpenAiCounter;
use Phoenix1331\AiHeadroom\Data\Tally;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroom\Tests\Fixtures\WordTokeniser;

beforeEach(function (): void {
    app()->instance(Tokeniser::class, new WordTokeniser);
    config([
        'ai.providers.anthropic' => ['driver' => 'anthropic', 'key' => 'sk-ant-test'],
        'ai.providers.gemini' => ['driver' => 'gemini', 'key' => 'g-test'],
        'ai.providers.openai' => ['driver' => 'openai', 'key' => 'sk-test'],
        'ai.providers.claude-eu' => ['driver' => 'anthropic', 'key' => 'sk-ant-eu'],
        'ai.providers.keyless' => ['driver' => 'anthropic', 'key' => null],
        'ai.providers.mistral' => ['driver' => 'mistral', 'key' => 'm-test'],
    ]);
});

function resolverStep(string $provider, string $model = 'claude-sonnet-5-5'): PendingStep
{
    return new PendingStep(0, true, $provider, $model, null, [new UserMessage('Where is my refund?')], [], null, null);
}

function resolver(string $method): CounterResolver
{
    config(['ai-headroom.method' => $method]);

    return app(CounterResolver::class);
}

it('picks the native counter for the step driver in auto and native mode', function (string $method, string $provider, string $counter): void {
    expect(resolver($method)->counterFor(resolverStep($provider)))->toBeInstanceOf($counter);
})->with(['auto', 'native'])->with([
    'anthropic' => ['anthropic', AnthropicCounter::class],
    'gemini' => ['gemini', GeminiCounter::class],
    'openai' => ['openai', OpenAiCounter::class],
    'custom provider name on the anthropic driver' => ['claude-eu', AnthropicCounter::class],
]);

it('always estimates in estimate mode, without resolving the provider', function (): void {
    expect(resolver('estimate')->counterFor(resolverStep('not-a-provider')))->toBeInstanceOf(EstimatingCounter::class);
});

it('estimates drivers that have no native counter', function (string $method): void {
    expect(resolver($method)->counterFor(resolverStep('mistral')))->toBeInstanceOf(EstimatingCounter::class);
})->with(['auto', 'native']);

it('estimates in auto mode when the provider has no API key', function (): void {
    expect(resolver('auto')->counterFor(resolverStep('keyless')))->toBeInstanceOf(EstimatingCounter::class);
});

it('still goes native in native mode when the provider has no API key, so the misconfiguration surfaces', function (): void {
    expect(resolver('native')->counterFor(resolverStep('keyless')))->toBeInstanceOf(AnthropicCounter::class);
});

it('estimates in auto mode when the provider is unknown to laravel/ai', function (): void {
    expect(resolver('auto')->counterFor(resolverStep('not-a-provider')))->toBeInstanceOf(EstimatingCounter::class);
});

it('throws in native mode when the provider is unknown to laravel/ai', function (): void {
    resolver('native')->counterFor(resolverStep('not-a-provider'));
})->throws(InvalidArgumentException::class);

it('counts through the chosen counter', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(responseFixture('anthropic/count_tokens.json'))]);

    $tally = resolver('auto')->count(resolverStep('anthropic'));

    expect($tally->total)->toBe(2095)
        ->and($tally->method)->toBe(CountMethod::Native);
});

it('is what the Counter contract resolves to', function (): void {
    expect(app(Counter::class))->toBeInstanceOf(CounterResolver::class);
});

it('accepts a counting method in any case', function (): void {
    expect(resolver(' NATIVE ')->counterFor(resolverStep('mistral')))->toBeInstanceOf(EstimatingCounter::class);
});

it('rejects an unknown counting method', function (): void {
    resolver('exact');
})->throws(InvalidArgumentException::class, 'Headroom does not recognise the counting method [exact]. Use auto, native or estimate.');

it('rejects a registered counter that is not a Counter', function (): void {
    config(['ai-headroom.counters.mistral' => stdClass::class]);

    resolver('auto');
})->throws(InvalidArgumentException::class, 'The Headroom counter for [mistral] must be a class implementing');

it('uses a counter registered by the app for another driver', function (): void {
    $custom = new class implements Counter
    {
        public function count(PendingStep $step): Tally
        {
            return new Tally(42, ['instructions' => 0, 'history' => 0, 'tools' => 0, 'attachments' => 0, 'prompt' => 42], CountMethod::Native, 0.0);
        }
    };
    app()->instance($custom::class, $custom);
    config(['ai-headroom.counters.mistral' => $custom::class]);

    expect(resolver('auto')->count(resolverStep('mistral'))->total)->toBe(42);
});

it('rejects a counter class the container resolves to something else', function (): void {
    $custom = new class implements Counter
    {
        public function count(PendingStep $step): Tally
        {
            throw new RuntimeException('unused');
        }
    };
    app()->bind($custom::class, fn (): stdClass => new stdClass);
    config(['ai-headroom.counters.mistral' => $custom::class]);

    resolver('auto')->counterFor(resolverStep('mistral'));
})->throws(LogicException::class, 'did not resolve to a');
