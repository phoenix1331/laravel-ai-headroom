<?php

declare(strict_types=1);

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Providers\Tools\WebSearch;
use Phoenix1331\AiHeadroom\Counting\StepFingerprint;
use Phoenix1331\AiHeadroom\Resolving\RequestResolver;
use Phoenix1331\AiHeadroom\Tests\Fixtures\Agents\AttributeProviderAgent;
use Phoenix1331\AiHeadroom\Tests\Fixtures\Agents\CheapestModelAgent;
use Phoenix1331\AiHeadroom\Tests\Fixtures\Agents\ConversationAgent;
use Phoenix1331\AiHeadroom\Tests\Fixtures\Agents\FailoverAgent;
use Phoenix1331\AiHeadroom\Tests\Fixtures\Agents\InstructionsOnlyAgent;
use Phoenix1331\AiHeadroom\Tests\Fixtures\Agents\MethodModelAgent;
use Phoenix1331\AiHeadroom\Tests\Fixtures\Agents\SmartestModelAgent;
use Phoenix1331\AiHeadroom\Tests\Fixtures\Agents\StructuredOutputAgent;
use Phoenix1331\AiHeadroom\Tests\Fixtures\Agents\ToolsAgent;
use Phoenix1331\AiHeadroom\Tests\Fixtures\LookupOrderTool;

beforeEach(function (): void {
    config([
        'ai.default' => 'anthropic',
        'ai.providers.anthropic' => ['driver' => 'anthropic', 'key' => 'sk-ant-test'],
        'ai.providers.gemini' => ['driver' => 'gemini', 'key' => 'g-test'],
        'ai.providers.openai' => ['driver' => 'openai', 'key' => 'sk-test'],
    ]);
});

/**
 * Run the agent through the real SDK with a fake gateway and return the first step its middleware saw.
 *
 * @param  Closure(Agent): mixed  $send
 */
function sdkStep(Agent $agent, Closure $send): PendingStep
{
    $agent::$captured = [];
    $agent::fake();

    $send($agent);

    expect($agent::$captured)->not->toBeEmpty();

    return $agent::$captured[0];
}

function expectParity(PendingStep $resolved, PendingStep $sdk): void
{
    expect($resolved->provider)->toBe($sdk->provider)
        ->and($resolved->model)->toBe($sdk->model)
        ->and($resolved->instructions)->toBe($sdk->instructions)
        ->and(array_map(fn ($message): string => $message::class, $resolved->messages))->toBe(array_map(fn ($message): string => $message::class, $sdk->messages))
        ->and(array_map(fn ($tool): string => $tool::class, $resolved->tools))->toBe(array_map(fn ($tool): string => $tool::class, $sdk->tools))
        ->and($resolved->options?->maxTokens)->toBe($sdk->options?->maxTokens)
        ->and($resolved->options?->temperature)->toBe($sdk->options?->temperature)
        ->and($resolved->options?->maxSteps)->toBe($sdk->options?->maxSteps)
        ->and(StepFingerprint::of($resolved))->toBe(StepFingerprint::of($sdk));
}

it('matches the SDK for the agent shapes Headroom supports', function (Closure $makeAgent, string|UserMessage $prompt, array $attachments): void {
    $sdk = sdkStep($makeAgent(), fn (Agent $agent) => $agent->prompt($prompt, $attachments));

    expectParity(app(RequestResolver::class)->resolve($makeAgent(), $prompt, $attachments), $sdk);
})->with([
    'instructions only' => [fn () => new InstructionsOnlyAgent, 'Where is my refund?', []],
    'conversation history' => [fn () => new ConversationAgent, 'Never mind, thanks.', []],
    'tools' => [fn () => new ToolsAgent, 'Has AB-1 shipped?', []],
    'attachments' => [fn () => new InstructionsOnlyAgent, 'What is in this photo?', [new Base64Image('aW1n', 'image/png')]],
    'user message with attachments' => [fn () => new InstructionsOnlyAgent, new UserMessage('And this one?', [new Base64Image('aW1nMg==', 'image/png')]), [new Base64Image('aW1nMw==', 'image/jpeg')]],
    'structured output' => [fn () => new StructuredOutputAgent, 'I want my money back now!', []],
    'provider and model attributes' => [fn () => new AttributeProviderAgent, 'Hello', []],
    'provider() and model() methods' => [fn () => new MethodModelAgent, 'Hello', []],
    'cheapest model on a provider() method' => [fn () => new CheapestModelAgent, 'Hello', []],
    'smartest model' => [fn () => new SmartestModelAgent, 'Hello', []],
    'failover list resolves to its first provider' => [fn () => new FailoverAgent, 'Hello', []],
]);

it('matches the SDK when the provider and model are passed explicitly', function (Lab|string $provider, ?string $model): void {
    $sdk = sdkStep(new ToolsAgent, fn (Agent $agent) => $agent->prompt('Hello', provider: $provider, model: $model));

    expectParity(app(RequestResolver::class)->resolve(new ToolsAgent, 'Hello', provider: $provider, model: $model), $sdk);
})->with([
    'provider only' => ['gemini', null],
    'lab enum and model' => [Lab::OpenAI, 'gpt-6-luna'],
]);

it('matches the SDK for ad-hoc history added with withMessages()', function (): void {
    $history = [new UserMessage('Earlier question'), new AssistantMessage('Earlier answer')];

    $sdk = sdkStep(new InstructionsOnlyAgent, fn (InstructionsOnlyAgent $agent) => $agent->withMessages($history)->prompt('Follow up'));

    expectParity(app(RequestResolver::class)->resolve(new InstructionsOnlyAgent, 'Follow up', messages: $history), $sdk);
});

it('matches the SDK for tools replaced with withTools()', function (): void {
    $tools = [new LookupOrderTool, new WebSearch];

    $sdk = sdkStep(new ToolsAgent, fn (ToolsAgent $agent) => $agent->withTools($tools)->prompt('Hello'));

    expectParity(app(RequestResolver::class)->resolve(new ToolsAgent, 'Hello', tools: $tools), $sdk);
});
