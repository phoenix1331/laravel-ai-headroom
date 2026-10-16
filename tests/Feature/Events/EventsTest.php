<?php

declare(strict_types=1);

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Phoenix1331\AiHeadroom\Counting\Contracts\Tokeniser;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroom\Enums\Currency;
use Phoenix1331\AiHeadroom\Enums\LimitType;
use Phoenix1331\AiHeadroom\Events\HeadroomLow;
use Phoenix1331\AiHeadroom\Events\RequestBlocked;
use Phoenix1331\AiHeadroom\Events\RequestFailed;
use Phoenix1331\AiHeadroom\Events\TokensCounted;
use Phoenix1331\AiHeadroom\Events\TokensUsed;
use Phoenix1331\AiHeadroom\Exceptions\TokenLimitExceeded;
use Phoenix1331\AiHeadroom\Facades\Headroom;
use Phoenix1331\AiHeadroom\HeadroomManager;
use Phoenix1331\AiHeadroom\Models\ModelRegistry;
use Phoenix1331\AiHeadroom\Tests\Fixtures\Agents\InstructionsOnlyAgent;
use Phoenix1331\AiHeadroom\Tests\Fixtures\WordTokeniser;

const SECRET_PROMPT = 'SECRET-PROMPT-7731 my card number is 4111';
const SECRET_RESPONSE = 'SECRET-RESPONSE-5520 your refund was approved';

beforeEach(function (): void {
    app()->instance(Tokeniser::class, new WordTokeniser);
    config([
        'ai-headroom.method' => 'estimate',
        'ai.default' => 'anthropic',
        'ai.providers.anthropic' => ['driver' => 'anthropic', 'key' => 'sk-ant-test'],
        'ai.providers.openai' => ['driver' => 'openai', 'key' => 'sk-test'],
    ]);
    Event::fake([TokensCounted::class, TokensUsed::class]);
});

function agentResponse(string $provider = 'anthropic', string $model = 'claude-sonnet-5-5', ?TextUsage $usage = null): AgentResponse
{
    return new AgentResponse('inv-123', SECRET_RESPONSE, $usage ?? new TextUsage(1_200, 80), new Meta($provider, $model));
}

it('fires TokensCounted for agent requests, with the count figures', function (): void {
    $count = Headroom::for(new InstructionsOnlyAgent, 'Where is my refund?');

    Event::assertDispatchedTimes(TokensCounted::class, 1);
    Event::assertDispatched(TokensCounted::class, fn (TokensCounted $event): bool => $event->requestId === $count->requestId()
        && $event->agent === InstructionsOnlyAgent::class
        && $event->provider === 'anthropic'
        && $event->model === 'claude-sonnet-5-5'
        && $event->countMethod === CountMethod::Estimated
        && $event->estimatedInputTokens === $count->total()
        && $event->breakdown === $count->breakdown()
        && $event->contextWindow === 1_000_000
        && $event->percentUsed === $count->percentUsed()
        && $event->estimatedCost === $count->estimatedCost()
        && $event->context === []);
});

it('fires no event for plain text counts', function (): void {
    Headroom::count('Where is my refund?', model: 'claude-sonnet-5-5');

    Event::assertNotDispatched(TokensCounted::class);
});

it('links TokensCounted and TokensUsed with one request ID for a prompt', function (): void {
    $count = Headroom::for(new InstructionsOnlyAgent, 'Where is my refund?');

    Headroom::recordUsage($count, agentResponse(usage: new TextUsage(1_200, 80)));

    $counted = Event::dispatched(TokensCounted::class)->first()[0];
    $used = Event::dispatched(TokensUsed::class)->first()[0];

    expect($used->requestId)->toBe($counted->requestId)
        ->and($used->invocationId)->toBe('inv-123')
        ->and($used->inputTokens)->toBe(1_200)
        ->and($used->outputTokens)->toBe(80)
        ->and($used->streamed)->toBeFalse()
        ->and($used->countMethod)->toBe(CountMethod::Estimated)
        ->and($used->estimatedInputTokens)->toBe($count->total())
        ->and($used->cost->total())->toBe((1_200 * 2.00 + 80 * 10.00) / 1_000_000);
});

it('links TokensCounted and TokensUsed with one request ID for a stream, once it completes', function (): void {
    InstructionsOnlyAgent::fake(['streamed reply']);

    $count = Headroom::for(new InstructionsOnlyAgent, 'Where is my refund?');
    $stream = (new InstructionsOnlyAgent)->stream('Where is my refund?');

    Headroom::recordUsage($count, $stream);

    Event::assertNotDispatched(TokensUsed::class);

    foreach ($stream as $event) {
        //
    }

    Event::assertDispatched(TokensUsed::class, fn (TokensUsed $event): bool => $event->requestId === $count->requestId() && $event->streamed);
});

it('reports the provider and model that actually answered after failover', function (): void {
    $count = Headroom::for(new InstructionsOnlyAgent, 'Where is my refund?');

    Headroom::recordUsage($count, agentResponse(provider: 'openai', model: 'gpt-6-luna', usage: new TextUsage(200_000, 0)));

    Event::assertDispatched(TokensUsed::class, fn (TokensUsed $event): bool => $event->provider === 'openai'
        && $event->model === 'gpt-6-luna'
        && $event->countedModel === 'claude-sonnet-5-5'
        && $event->cost->total() === 200_000 * 0.10 / 1_000_000);
});

it('prices an unknown reported model snapshot as the counted model', function (): void {
    $count = Headroom::for(new InstructionsOnlyAgent, 'Hello');

    Headroom::recordUsage($count, agentResponse(model: 'claude-sonnet-5-5-20261001', usage: new TextUsage(1_000_000, 0)));

    Event::assertDispatched(TokensUsed::class, fn (TokensUsed $event): bool => $event->model === 'claude-sonnet-5-5-20261001' && $event->cost->total() === 2.0);
});

it('prices cache reads and writes, and flags the cost approximate when a price is missing', function (): void {
    $count = Headroom::for(new InstructionsOnlyAgent, 'Hello');

    Headroom::recordUsage($count, agentResponse(usage: new TextUsage(1_000_000, 0, cacheReadInputTokens: 600_000, cacheWriteInputTokens: 100_000)));
    Headroom::recordUsage($count, agentResponse(provider: 'openai', model: 'gpt-6-luna', usage: new TextUsage(200_000, 0, cacheReadInputTokens: 150_000)));

    [$claude, $openai] = Event::dispatched(TokensUsed::class)->map(fn (array $args): TokensUsed => $args[0])->all();

    expect($claude->cacheReadTokens)->toBe(600_000)
        ->and($claude->cacheWriteTokens)->toBe(100_000)
        ->and($claude->cost->total())->toBe(round((600_000 * 0.10 + 100_000 * 2.50 + 300_000 * 2.00) / 1_000_000, 8))
        ->and($claude->cost->approximate)->toBeFalse()
        ->and($openai->cost->total())->toBe(200_000 * 0.10 / 1_000_000)
        ->and($openai->cost->approximate)->toBeTrue();
});

it('reports actual cost in the configured currency', function (): void {
    config(['ai-headroom.currency' => 'GBP', 'ai-headroom.exchange_rates.GBP' => 0.5]);
    app()->forgetInstance(ModelRegistry::class);
    app()->forgetInstance(HeadroomManager::class);
    Headroom::clearResolvedInstances();

    $count = Headroom::for(new InstructionsOnlyAgent, 'Hello');
    Headroom::recordUsage($count, agentResponse(usage: new TextUsage(1_000_000, 0)));

    Event::assertDispatched(TokensUsed::class, fn (TokensUsed $event): bool => $event->cost->currency === Currency::Gbp && $event->cost->total() === 1.0);
});

it('adds context tags to TokensCounted and TokensUsed', function (): void {
    Headroom::resolveContextUsing(fn (?Agent $agent): array => ['tenant_id' => 42, 'plan' => 'pro', 'agent' => $agent::class]);

    $count = Headroom::for(new InstructionsOnlyAgent, 'Hello');
    Headroom::recordUsage($count, agentResponse());

    $tags = ['tenant_id' => 42, 'plan' => 'pro', 'agent' => InstructionsOnlyAgent::class];

    expect($count->context())->toBe($tags);
    Event::assertDispatched(TokensCounted::class, fn (TokensCounted $event): bool => $event->context === $tags);
    Event::assertDispatched(TokensUsed::class, fn (TokensUsed $event): bool => $event->context === $tags);
});

it('drops non-scalar tags with a warning and keeps the rest', function (): void {
    Log::spy();
    Headroom::resolveContextUsing(fn (): array => ['tenant_id' => 42, 'user' => new stdClass, 'roles' => ['admin'], 'nothing' => null, 0 => 'unnamed']);

    expect(Headroom::for(new InstructionsOnlyAgent, 'Hello')->context())->toBe(['tenant_id' => 42]);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'must be scalars') && $context['dropped'] === ['user', 'roles', 'nothing', '0']);
});

it('continues with no tags and logs only the exception class when the resolver throws', function (): void {
    Log::spy();
    Headroom::resolveContextUsing(fn (): array => throw new RuntimeException('tenant lookup failed for '.SECRET_PROMPT));

    $count = Headroom::for(new InstructionsOnlyAgent, 'Hello');

    expect($count->context())->toBe([]);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context === ['exception' => RuntimeException::class]);
});

it('continues with no tags when the resolver does not return an array', function (): void {
    Log::spy();
    Headroom::resolveContextUsing(fn (): string => 'tenant-42');

    expect(Headroom::for(new InstructionsOnlyAgent, 'Hello')->context())->toBe([]);
    Log::shouldHaveReceived('warning')->once();
});

it('never puts prompt, instruction or response text in any event', function (): void {
    Headroom::resolveContextUsing(fn (): array => ['tenant_id' => 42]);

    $count = Headroom::for(new InstructionsOnlyAgent, SECRET_PROMPT);
    Headroom::recordUsage($count, agentResponse());

    $provider = new RequestException(new Response(new GuzzleHttp\Psr7\Response(400, [], '{"error":"'.SECRET_PROMPT.'"}')));

    $events = [
        Event::dispatched(TokensCounted::class)->first()[0],
        Event::dispatched(TokensUsed::class)->first()[0],
        new HeadroomLow($count->requestId(), 'inv-1', LimitType::PercentOfContext, 80.0, 85.2, '85.2% of context used, limit 80%', now()->toImmutable()),
        new RequestBlocked($count->requestId(), 'inv-1', LimitType::Cost, 0.2, 0.3, $count, now()->toImmutable()),
        RequestFailed::from($count->requestId(), 'inv-1', 'anthropic', 'claude-sonnet-5-5', new RuntimeException('Provider said: '.SECRET_PROMPT, 0, $provider)),
    ];

    foreach ($events as $event) {
        $payloads = [serialize($event), (string) json_encode($event), var_export($event, true)];

        foreach ($payloads as $payload) {
            expect($payload)
                ->not->toContain('SECRET-PROMPT-7731')
                ->not->toContain('SECRET-RESPONSE-5520')
                ->not->toContain((new InstructionsOnlyAgent)->instructions());
        }
    }
});

it('records the exception class and HTTP status on RequestFailed, never the message', function (): void {
    $provider = new RequestException(new Response(new GuzzleHttp\Psr7\Response(429)));

    $event = RequestFailed::from('req-1', 'inv-1', 'anthropic', 'claude-sonnet-5-5', new RuntimeException('Rate limited: '.SECRET_PROMPT, 0, $provider));

    expect($event->exception)->toBe(RuntimeException::class)
        ->and($event->httpStatus)->toBe(429)
        ->and((array) $event)->not->toHaveKey('message');
});

it('leaves the HTTP status empty when no HTTP response is involved', function (): void {
    expect(RequestFailed::from('req-1', null, null, 'm', new LogicException('nope'))->httpStatus)->toBeNull();
});

it('describes a breached limit with figures only', function (): void {
    $count = Headroom::for(new InstructionsOnlyAgent, SECRET_PROMPT);

    $exception = new TokenLimitExceeded($count, LimitType::PercentOfContext, 80.0, 85.25);

    expect($exception->getMessage())->toBe("Request {$count->requestId()} exceeds max_percent_of_context: 85.25 against a limit of 80 (model claude-sonnet-5-5).")
        ->and($exception->count)->toBe($count)
        ->and($exception->limit)->toBe(LimitType::PercentOfContext);
});
