<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Phoenix1331\AiHeadroom\Counting\Contracts\Tokeniser;
use Phoenix1331\AiHeadroom\Data\TokenCount;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroom\Facades\Headroom;
use Phoenix1331\AiHeadroom\Tests\Fixtures\Agents\AttributeProviderAgent;
use Phoenix1331\AiHeadroom\Tests\Fixtures\Agents\ConversationAgent;
use Phoenix1331\AiHeadroom\Tests\Fixtures\Agents\InstructionsOnlyAgent;
use Phoenix1331\AiHeadroom\Tests\Fixtures\Agents\StructuredOutputAgent;
use Phoenix1331\AiHeadroom\Tests\Fixtures\Agents\ToolsAgent;
use Phoenix1331\AiHeadroom\Tests\Fixtures\LookupOrderTool;
use Phoenix1331\AiHeadroom\Tests\Fixtures\WordTokeniser;

beforeEach(function (): void {
    app()->instance(Tokeniser::class, new WordTokeniser);
    config([
        'ai-headroom.method' => 'estimate',
        'ai.default' => 'anthropic',
        'ai.providers.anthropic' => ['driver' => 'anthropic', 'key' => 'sk-ant-test'],
        'ai.providers.gemini' => ['driver' => 'gemini', 'key' => 'g-test'],
        'ai.providers.openai' => ['driver' => 'openai', 'key' => 'sk-test'],
    ]);
});

it('counts an instructions-only agent request', function (): void {
    $count = Headroom::for(new InstructionsOnlyAgent, 'Where is my refund?');

    expect($count)->toBeInstanceOf(TokenCount::class)
        ->and($count->model())->toBe('claude-sonnet-5-5')
        ->and($count->contextWindow())->toBe(1_000_000)
        ->and($count->method())->toBe(CountMethod::Estimated)
        ->and($count->breakdown()['instructions'])->toBeGreaterThan(0)
        ->and($count->breakdown()['prompt'])->toBeGreaterThan(0)
        ->and($count->breakdown()['history'])->toBe(0)
        ->and($count->breakdown()['tools'])->toBe(0)
        ->and($count->breakdown()['attachments'])->toBe(0)
        ->and($count->total())->toBeGreaterThan(array_sum($count->breakdown()));
});

it('counts conversation history as history, not prompt', function (): void {
    $withHistory = Headroom::for(new ConversationAgent, 'Never mind, thanks.');
    $without = Headroom::for(new InstructionsOnlyAgent, 'Never mind, thanks.');

    expect($withHistory->breakdown()['history'])->toBeGreaterThan(0)
        ->and($withHistory->breakdown()['prompt'])->toBe($without->breakdown()['prompt']);
});

it('counts tool definitions', function (): void {
    expect(Headroom::for(new ToolsAgent, 'Has AB-1 shipped?')->breakdown()['tools'])->toBeGreaterThan(0);
});

it('counts attachments passed with the prompt as approximate', function (): void {
    $count = Headroom::for(new InstructionsOnlyAgent, 'What is in this photo?', [new Base64Image('aW1n', 'image/png')]);

    expect($count->breakdown()['attachments'])->toBe(1_600)
        ->and($count->confidence())->toBe(0.5);
});

it('counts the structured output schema and prices output at the agent max tokens', function (): void {
    $count = Headroom::for(new StructuredOutputAgent, 'I want my money back now!');

    expect($count->breakdown()['tools'])->toBeGreaterThan(0)
        ->and($count->estimatedCost()->output)->toBe(2_000 * 10.00 / 1_000_000);
});

it('prices output at the model maximum when the agent sets no max tokens', function (): void {
    expect(Headroom::for(new InstructionsOnlyAgent, 'Hello')->estimatedCost()->output)->toBe(128_000 * 10.00 / 1_000_000);
});

it('resolves the provider and model the agent would use', function (): void {
    $count = Headroom::for(new AttributeProviderAgent, 'Hello');

    expect($count->model())->toBe('gemini-3.1-flash-lite')
        ->and($count->contextWindow())->toBe(1_048_576);
});

it('accepts the provider and model as arguments, like prompt()', function (): void {
    expect(Headroom::for(new InstructionsOnlyAgent, 'Hello', provider: 'openai', model: 'gpt-6-luna')->model())->toBe('gpt-6-luna');
});

it('accepts a user message as the prompt (OpenAI model, so counts are unscaled words)', function (): void {
    $count = Headroom::for(new InstructionsOnlyAgent, new UserMessage('What about this?', [new Base64Image('aW1n', 'image/png')]), provider: 'openai', model: 'gpt-6-luna');

    expect($count->breakdown()['prompt'])->toBe(3)
        ->and($count->breakdown()['attachments'])->toBe(1_600);
});

it('counts history and tools that would be set with withMessages() and withTools()', function (): void {
    $count = Headroom::for(
        new InstructionsOnlyAgent,
        'Follow up',
        provider: 'openai',
        model: 'gpt-6-luna',
        messages: [new UserMessage('Earlier question'), new AssistantMessage('Earlier answer')],
        tools: [new LookupOrderTool],
    );

    expect($count->breakdown()['history'])->toBe(4)
        ->and($count->breakdown()['tools'])->toBeGreaterThan(0);
});

it('uses the native counter in auto mode when the provider has a key', function (): void {
    config(['ai-headroom.method' => 'auto']);
    Http::fake(['api.anthropic.com/v1/messages/count_tokens' => Http::response(responseFixture('anthropic/count_tokens.json'))]);

    $count = Headroom::for(new ToolsAgent, 'Has AB-1 shipped?');

    expect($count->total())->toBe(2095)
        ->and($count->method())->toBe(CountMethod::Native)
        ->and($count->breakdown()['tools'])->toBeGreaterThan(0);

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['system'] === 'You can look up orders and search the web.');
});

it('never calls the model', function (): void {
    InstructionsOnlyAgent::$captured = [];

    Headroom::for(new InstructionsOnlyAgent, 'Hello');

    expect(InstructionsOnlyAgent::$captured)->toBeEmpty();
    Http::assertNothingSent();
});
