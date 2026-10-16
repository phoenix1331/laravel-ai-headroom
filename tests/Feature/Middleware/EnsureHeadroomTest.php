<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\TextResponse;
use Phoenix1331\AiHeadroom\Counting\Contracts\Tokeniser;
use Phoenix1331\AiHeadroom\Enums\LimitType;
use Phoenix1331\AiHeadroom\Events\HeadroomLow;
use Phoenix1331\AiHeadroom\Events\RequestBlocked;
use Phoenix1331\AiHeadroom\Events\RequestFailed;
use Phoenix1331\AiHeadroom\Events\TokensCounted;
use Phoenix1331\AiHeadroom\Events\TokensUsed;
use Phoenix1331\AiHeadroom\Exceptions\TokenLimitExceeded;
use Phoenix1331\AiHeadroom\Facades\Headroom;
use Phoenix1331\AiHeadroom\Middleware\EnsureHeadroom;
use Phoenix1331\AiHeadroom\Tests\Fixtures\Agents\GuardedAgent;
use Phoenix1331\AiHeadroom\Tests\Fixtures\Agents\InstructionsOnlyAgent;
use Phoenix1331\AiHeadroom\Tests\Fixtures\WordTokeniser;
use Phoenix1331\AiHeadroom\Tracking\RequestTracker;

const GUARDED_SECRET = 'GUARDED-SECRET-9091 please refund order AB-1';

beforeEach(function (): void {
    app()->instance(Tokeniser::class, new WordTokeniser);
    config([
        'ai-headroom.method' => 'estimate',
        'ai.default' => 'anthropic',
        'ai.providers.anthropic' => ['driver' => 'anthropic', 'key' => 'sk-ant-test'],
        'ai.providers.openai' => ['driver' => 'openai', 'key' => 'sk-test'],
    ]);
    GuardedAgent::$guard = null;
    GuardedAgent::$providers = 'anthropic';
    Event::fake([TokensCounted::class, TokensUsed::class, HeadroomLow::class, RequestBlocked::class, RequestFailed::class]);
});

function reply(string $text = 'Your refund is on its way.', string $provider = 'anthropic', string $model = 'claude-sonnet-5-5'): TextResponse
{
    return new TextResponse($text, new TextUsage(1_200, 80, cacheReadInputTokens: 1_000), new Meta($provider, $model));
}

/**
 * @return list<object>
 */
function headroomEvents(): array
{
    return collect([TokensCounted::class, TokensUsed::class, HeadroomLow::class, RequestBlocked::class, RequestFailed::class])
        ->flatMap(fn (string $class) => Event::dispatched($class)->map(fn (array $args): object => $args[0]))
        ->all();
}

function only(string $class): object
{
    $events = Event::dispatched($class);

    expect($events)->toHaveCount(1);

    return $events->first()[0];
}

it('fires TokensCounted before and TokensUsed after a prompt, with one request ID', function (): void {
    GuardedAgent::fake([reply()]);

    $response = (new GuardedAgent)->prompt('Where is my refund?');

    $counted = only(TokensCounted::class);
    $used = only(TokensUsed::class);

    expect($used->requestId)->toBe($counted->requestId)
        ->and($used->invocationId)->toBe($counted->invocationId)
        ->and($used->invocationId)->toBe($response->invocationId)
        ->and($counted->agent)->toBe(GuardedAgent::class)
        ->and($counted->provider)->toBe('anthropic')
        ->and($used->inputTokens)->toBe(1_200)
        ->and($used->outputTokens)->toBe(80)
        ->and($used->cacheReadTokens)->toBe(1_000)
        ->and($used->streamed)->toBeFalse()
        ->and($used->estimatedInputTokens)->toBe($counted->estimatedInputTokens)
        ->and(app(RequestTracker::class)->count())->toBe(0);
});

it('fires TokensUsed with the same request ID once a stream completes', function (): void {
    GuardedAgent::fake([reply()]);

    $stream = (new GuardedAgent)->stream('Where is my refund?');

    foreach ($stream as $event) {
        //
    }

    $counted = only(TokensCounted::class);
    $used = only(TokensUsed::class);

    expect($used->requestId)->toBe($counted->requestId)
        ->and($used->streamed)->toBeTrue()
        ->and($used->inputTokens)->toBe(1_200);
});

it('counts every step of a tool loop with its own request ID and a shared invocation ID', function (): void {
    GuardedAgent::fake([new ToolCall('call_1', 'LookupOrderTool', ['reference' => 'AB-1']), reply()]);

    (new GuardedAgent)->prompt(GUARDED_SECRET);

    $counted = Event::dispatched(TokensCounted::class)->map(fn (array $args) => $args[0]);
    $used = Event::dispatched(TokensUsed::class)->map(fn (array $args) => $args[0]);

    expect($counted)->toHaveCount(2)
        ->and($used)->toHaveCount(2)
        ->and($counted[0]->requestId)->not->toBe($counted[1]->requestId)
        ->and($used->pluck('requestId')->all())->toBe($counted->pluck('requestId')->all())
        ->and($counted->pluck('invocationId')->unique())->toHaveCount(1)
        ->and($counted[1]->breakdown['history'])->toBeGreaterThan($counted[0]->breakdown['history']);
});

it('fires RequestBlocked before throwing TokenLimitExceeded, so a caught block is still recorded', function (): void {
    GuardedAgent::$guard = new EnsureHeadroom(maxPercentOfContext: 0.0001);
    GuardedAgent::fake([reply()]);

    // RequestBlocked stays real here so its listener runs and the order can be observed.
    Event::fake([TokensCounted::class, TokensUsed::class, RequestFailed::class]);

    $timeline = [];
    $blocked = null;
    Event::listen(RequestBlocked::class, function (RequestBlocked $event) use (&$timeline, &$blocked): void {
        $timeline[] = 'blocked';
        $blocked = $event;
    });

    try {
        (new GuardedAgent)->prompt('Where is my refund?');
    } catch (TokenLimitExceeded $exception) {
        $timeline[] = 'thrown';
    }

    expect($timeline)->toBe(['blocked', 'thrown'])
        ->and($exception->count->requestId())->toBe($blocked->requestId)
        ->and($blocked->requestId)->toBe(only(TokensCounted::class)->requestId)
        ->and($blocked->limit)->toBe(LimitType::PercentOfContext)
        ->and($blocked->limitValue)->toBe(0.0001);

    Event::assertNotDispatched(TokensUsed::class);
    Event::assertNotDispatched(RequestFailed::class);
    expect(app(RequestTracker::class)->count())->toBe(0);
});

it('blocks on the first breached limit in order: context window, percent, cost', function (?float $percent, ?float $cost, int $window, LimitType $limit): void {
    config(['ai-headroom.models.claude-sonnet-5-5.context_window' => $window]);
    GuardedAgent::$guard = new EnsureHeadroom(maxPercentOfContext: $percent, maxCost: $cost);
    GuardedAgent::fake([reply()]);

    expect(fn () => (new GuardedAgent)->prompt('Where is my refund?'))
        ->toThrow(fn (TokenLimitExceeded $exception) => expect($exception->limit)->toBe($limit));
})->with([
    'context window' => [null, null, 5, LimitType::ContextWindow],
    'percent of context' => [0.0001, null, 1_000_000, LimitType::PercentOfContext],
    'cost' => [null, 0.0001, 1_000_000, LimitType::Cost],
    'window wins over the others' => [0.0001, 0.0001, 5, LimitType::ContextWindow],
]);

it('lets the request through and fires HeadroomLow for each breach in report mode', function (): void {
    GuardedAgent::$guard = (new EnsureHeadroom(maxPercentOfContext: 0.0001, maxCost: 0.0001))->report();
    GuardedAgent::fake([reply()]);

    $response = (new GuardedAgent)->prompt('Where is my refund?');

    $lows = Event::dispatched(HeadroomLow::class)->map(fn (array $args) => $args[0]);

    expect((string) $response)->toBe('Your refund is on its way.')
        ->and($lows->pluck('limit')->all())->toBe([LimitType::PercentOfContext, LimitType::Cost])
        ->and($lows->pluck('requestId')->unique()->all())->toBe([only(TokensCounted::class)->requestId])
        ->and($lows[0]->reason)->toContain('% of context used, limit 0.0001%')
        ->and($lows[1]->reason)->toContain('exceeds the limit of 0.0001 USD');

    Event::assertNotDispatched(RequestBlocked::class);
    Event::assertDispatched(TokensUsed::class);
});

it('takes its limits and mode from config when not given', function (): void {
    config(['ai-headroom.middleware' => ['max_percent_of_context' => 0.0001, 'max_cost' => null, 'mode' => 'report']]);
    GuardedAgent::fake([reply()]);

    (new GuardedAgent)->prompt('Where is my refund?');

    Event::assertDispatched(HeadroomLow::class, fn (HeadroomLow $event): bool => $event->limit === LimitType::PercentOfContext);
});

it('passes requests within its limits without any warning', function (): void {
    GuardedAgent::$guard = new EnsureHeadroom(maxPercentOfContext: 80, maxCost: 5.00);
    GuardedAgent::fake([reply()]);

    (new GuardedAgent)->prompt('Where is my refund?');

    Event::assertNotDispatched(HeadroomLow::class);
    Event::assertNotDispatched(RequestBlocked::class);
});

it('fires RequestFailed when the provider call throws, without the exception message', function (): void {
    GuardedAgent::fake(fn () => throw new RuntimeException('Provider echoed: '.GUARDED_SECRET));

    expect(fn () => (new GuardedAgent)->prompt(GUARDED_SECRET))->toThrow(RuntimeException::class);

    $failed = only(RequestFailed::class);

    expect($failed->requestId)->toBe(only(TokensCounted::class)->requestId)
        ->and($failed->exception)->toBe(RuntimeException::class)
        ->and($failed->provider)->toBe('anthropic')
        ->and($failed->model)->toBe('claude-sonnet-5-5')
        ->and(serialize($failed))->not->toContain('GUARDED-SECRET-9091')
        ->and(app(RequestTracker::class)->count())->toBe(0);

    Event::assertNotDispatched(TokensUsed::class);
});

it('fires RequestFailed when a stream fails part-way', function (): void {
    GuardedAgent::fake(fn () => throw new RuntimeException('stream broke'));

    try {
        foreach ((new GuardedAgent)->stream('Where is my refund?') as $event) {
            //
        }
    } catch (RuntimeException) {
        //
    }

    expect(only(RequestFailed::class)->requestId)->toBe(only(TokensCounted::class)->requestId);
    Event::assertNotDispatched(TokensUsed::class);
});

it('reports a failed failover attempt and the usage of the provider that answered', function (): void {
    GuardedAgent::$providers = ['anthropic', 'openai'];
    GuardedAgent::fake(fn (string $prompt, $attachments, $provider) => $provider->name() === 'anthropic'
        ? throw ProviderOverloadedException::forProvider('anthropic')
        : reply(provider: 'openai', model: 'gpt-6.1-sol'));

    (new GuardedAgent)->prompt('Where is my refund?');

    $counted = Event::dispatched(TokensCounted::class)->map(fn (array $args) => $args[0]);
    $failed = only(RequestFailed::class);
    $used = only(TokensUsed::class);

    expect($counted->pluck('provider')->all())->toBe(['anthropic', 'openai'])
        ->and($failed->requestId)->toBe($counted[0]->requestId)
        ->and($failed->exception)->toBe(ProviderOverloadedException::class)
        ->and($used->requestId)->toBe($counted[1]->requestId)
        ->and($used->provider)->toBe('openai')
        ->and($used->model)->toBe('gpt-6.1-sol');
});

it('leaves an abandoned stream counted but never used or failed', function (): void {
    GuardedAgent::fake([reply()]);

    $stream = (new GuardedAgent)->stream('Where is my refund?');

    foreach ($stream as $event) {
        break;
    }

    Event::assertDispatched(TokensCounted::class);
    Event::assertNotDispatched(TokensUsed::class);
    Event::assertNotDispatched(RequestFailed::class);
});

it('tags middleware events with the app context', function (): void {
    Headroom::resolveContextUsing(fn ($agent): array => ['tenant_id' => 7, 'agent' => $agent::class]);
    GuardedAgent::fake([reply()]);

    (new GuardedAgent)->prompt('Where is my refund?');

    expect(only(TokensCounted::class)->context)->toBe(['tenant_id' => 7, 'agent' => GuardedAgent::class])
        ->and(only(TokensUsed::class)->context)->toBe(['tenant_id' => 7, 'agent' => GuardedAgent::class]);
});

it('never puts prompt, instruction, tool or response text in the events it fires', function (): void {
    GuardedAgent::$guard = (new EnsureHeadroom(maxPercentOfContext: 0.0001))->report();
    GuardedAgent::fake([new ToolCall('call_1', 'LookupOrderTool', ['reference' => 'AB-1']), reply('GUARDED-RESPONSE-1144')]);

    (new GuardedAgent)->prompt(GUARDED_SECRET);

    $events = headroomEvents();

    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        foreach ([serialize($event), (string) json_encode($event)] as $payload) {
            expect($payload)
                ->not->toContain('GUARDED-SECRET-9091')
                ->not->toContain('GUARDED-RESPONSE-1144')
                ->not->toContain('careful support agent')
                ->not->toContain('Look up an order by reference');
        }
    }
});

it('ignores failures of agents that do not use the middleware', function (): void {
    InstructionsOnlyAgent::fake(fn () => throw new RuntimeException('boom'));

    expect(fn () => (new InstructionsOnlyAgent)->prompt('Hello'))->toThrow(RuntimeException::class);

    Event::assertNotDispatched(RequestFailed::class);
});

it('rejects an unknown mode', function (): void {
    new EnsureHeadroom(mode: 'warn');
})->throws(InvalidArgumentException::class, 'Headroom does not recognise the middleware mode [warn]. Use throw or report.');
