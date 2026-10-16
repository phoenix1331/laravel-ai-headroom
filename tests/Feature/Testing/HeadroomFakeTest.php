<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\PendingStep;
use Phoenix1331\AiHeadroom\Counting\Contracts\Tokeniser;
use Phoenix1331\AiHeadroom\Data\Tally;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroom\Enums\LimitType;
use Phoenix1331\AiHeadroom\Events\TokensCounted;
use Phoenix1331\AiHeadroom\Exceptions\TokenLimitExceeded;
use Phoenix1331\AiHeadroom\Facades\Headroom;
use Phoenix1331\AiHeadroom\Middleware\EnsureHeadroom;
use Phoenix1331\AiHeadroom\Testing\HeadroomFake;
use Phoenix1331\AiHeadroom\Tests\Fixtures\Agents\GuardedAgent;
use Phoenix1331\AiHeadroom\Tests\Fixtures\Agents\InstructionsOnlyAgent;
use Phoenix1331\AiHeadroom\Tests\Fixtures\Agents\ToolsAgent;
use Phoenix1331\AiHeadroom\Tests\Fixtures\WordTokeniser;
use PHPUnit\Framework\ExpectationFailedException;

beforeEach(function (): void {
    app()->instance(Tokeniser::class, new WordTokeniser);
    config([
        'ai.default' => 'anthropic',
        'ai.providers.anthropic' => ['driver' => 'anthropic', 'key' => 'sk-ant-test'],
    ]);
    GuardedAgent::$guard = null;
    GuardedAgent::$providers = 'anthropic';
});

it('fakes every count with a wildcard total', function (): void {
    Headroom::fake(['*' => 1_200]);

    $count = Headroom::for(new InstructionsOnlyAgent, 'Where is my refund?');

    expect($count->total())->toBe(1_200)
        ->and($count->breakdown()['prompt'])->toBe(1_200)
        ->and($count->method())->toBe(CountMethod::Native)
        ->and($count->contextWindow())->toBe(1_000_000)
        ->and(Headroom::count('hello', model: 'gpt-6.1-sol')->total())->toBe(1_200);
});

it('fakes by agent class, model and wildcard pattern, exact matches first', function (): void {
    Headroom::fake([
        'claude-*' => 300,
        ToolsAgent::class => 900,
        'claude-sonnet-5-5' => 500,
    ]);

    expect(Headroom::for(new ToolsAgent, 'Hello')->total())->toBe(900)
        ->and(Headroom::for(new InstructionsOnlyAgent, 'Hello')->total())->toBe(500)
        ->and(Headroom::for(new InstructionsOnlyAgent, 'Hello', model: 'claude-haiku-5-5')->total())->toBe(300);
});

it('fakes a full breakdown', function (): void {
    Headroom::fake([InstructionsOnlyAgent::class => ['instructions' => 400, 'history' => 5_000, 'prompt' => 200]]);

    $count = Headroom::for(new InstructionsOnlyAgent, 'Hello');

    expect($count->total())->toBe(5_600)
        ->and($count->breakdown())->toBe(['instructions' => 400, 'history' => 5_000, 'tools' => 0, 'attachments' => 0, 'prompt' => 200]);
});

it('estimates unmatched requests locally and never calls a provider, even in native mode', function (): void {
    config(['ai-headroom.method' => 'native']);
    Headroom::fake([ToolsAgent::class => 900]);

    $count = Headroom::for(new InstructionsOnlyAgent, 'Hello');

    expect($count->method())->toBe(CountMethod::Estimated);
    Http::assertNothingSent();
});

it('runs the middleware against faked totals, so guards can be tested', function (): void {
    Headroom::fake([GuardedAgent::class => 900_000]);
    GuardedAgent::$guard = new EnsureHeadroom(maxPercentOfContext: 80);
    GuardedAgent::fake(['ok']);

    expect(fn () => (new GuardedAgent)->prompt('Where is my refund?'))
        ->toThrow(fn (TokenLimitExceeded $exception) => expect($exception->limit)->toBe(LimitType::PercentOfContext)
            ->and($exception->count->total())->toBe(900_000));
});

it('still fires events with the faked figures', function (): void {
    Event::fake([TokensCounted::class]);
    Headroom::fake(['*' => 1_200]);

    Headroom::for(new InstructionsOnlyAgent, 'Hello');

    Event::assertDispatched(TokensCounted::class, fn (TokensCounted $event): bool => $event->estimatedInputTokens === 1_200);
});

it('asserts what was counted, by agent class or model', function (): void {
    $fake = Headroom::fake(['*' => 1_200]);

    Headroom::for(new InstructionsOnlyAgent, 'Where is my refund?');
    Headroom::for(new InstructionsOnlyAgent, 'Anything else?');
    Headroom::count('plain text', model: 'gpt-6.1-sol');

    Headroom::assertCounted(InstructionsOnlyAgent::class);
    Headroom::assertCounted('gpt-6.1-sol');
    Headroom::assertCounted(InstructionsOnlyAgent::class, fn (PendingStep $step, Tally $tally): bool => $step->messages[0]->content === 'Anything else?' && $tally->total === 1_200);
    Headroom::assertCountedTimes(InstructionsOnlyAgent::class, 2);
    Headroom::assertCountedTimes('claude-sonnet-5-5', 2);
    Headroom::assertNotCounted(ToolsAgent::class);

    expect($fake)->toBeInstanceOf(HeadroomFake::class)
        ->and($fake->counted())->toHaveCount(3);
});

it('asserts nothing was counted', function (): void {
    Headroom::fake();

    Headroom::assertNothingCounted();
});

it('fails assertions that do not hold, with a clear message', function (Closure $assertion, string $message): void {
    Headroom::fake(['*' => 1_200]);
    Headroom::for(new InstructionsOnlyAgent, 'Hello');

    expect($assertion)->toThrow(ExpectationFailedException::class, $message);
})->with([
    'counted' => [fn () => Headroom::assertCounted(ToolsAgent::class), 'No request was counted for ['.ToolsAgent::class.'].'],
    'counted with callback' => [fn () => Headroom::assertCounted(InstructionsOnlyAgent::class, fn (): bool => false), 'matching the callback'],
    'not counted' => [fn () => Headroom::assertNotCounted(InstructionsOnlyAgent::class), 'was counted for ['.InstructionsOnlyAgent::class.'] unexpectedly'],
    'times' => [fn () => Headroom::assertCountedTimes(InstructionsOnlyAgent::class, 3), 'Expected 3 requests counted for ['.InstructionsOnlyAgent::class.'], counted 1.'],
    'nothing' => [fn () => Headroom::assertNothingCounted(), 'Requests were counted unexpectedly.'],
]);

it('requires fake() before assertions', function (): void {
    Headroom::assertNothingCounted();
})->throws(RuntimeException::class, 'Call Headroom::fake() before using Headroom assertions.');

it('rejects invalid fake values', function (array $totals, string $message): void {
    expect(fn () => Headroom::fake($totals))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown part' => [['*' => ['system' => 10]], 'Headroom::fake() for [*] has unknown parts: system.'],
    'negative' => [['*' => -1], 'Headroom::fake() for [*] cannot have negative tokens.'],
]);
