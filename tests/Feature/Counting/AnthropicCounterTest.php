<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;
use Phoenix1331\AiHeadroom\Counting\AnthropicCounter;
use Phoenix1331\AiHeadroom\Counting\Contracts\Tokeniser;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroom\Tests\Fixtures\LookupOrderTool;
use Phoenix1331\AiHeadroom\Tests\Fixtures\WordTokeniser;

beforeEach(function (): void {
    config(['ai.providers.anthropic' => ['driver' => 'anthropic', 'key' => 'sk-ant-test']]);
    app()->instance(Tokeniser::class, new WordTokeniser);
});

function anthropicStep(?TextGenerationOptions $options = null, array $tools = []): PendingStep
{
    return new PendingStep(
        number: 0,
        isFinalStep: true,
        provider: 'anthropic',
        model: 'claude-sonnet-5-5',
        instructions: 'You are a support agent.',
        messages: [
            new UserMessage('Hi'),
            new AssistantMessage('Hello, how can I help?'),
            new UserMessage('Where is my refund?'),
        ],
        tools: $tools,
        schema: null,
        options: $options,
    );
}

it('sends the SDK request body to count_tokens, without generation-only fields', function (): void {
    Http::fake(['api.anthropic.com/v1/messages/count_tokens' => Http::response(responseFixture('anthropic/count_tokens.json'))]);

    app(AnthropicCounter::class)->count(anthropicStep(
        options: new TextGenerationOptions(maxTokens: 4_000, temperature: 0.2),
        tools: [new LookupOrderTool],
    ));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.anthropic.com/v1/messages/count_tokens'
        && $request->method() === 'POST'
        && $request->header('x-api-key') === ['sk-ant-test']
        && $request->header('anthropic-version') === ['2023-06-01']
        && json_decode($request->body(), true) === [
            'model' => 'claude-sonnet-5-5',
            'messages' => [
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]],
                ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Hello, how can I help?']]],
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Where is my refund?']]],
            ],
            'system' => 'You are a support agent.',
            'tools' => [[
                'name' => 'LookupOrderTool',
                'description' => 'Look up an order by reference',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['reference' => ['type' => 'string']],
                    'required' => ['reference'],
                ],
            ]],
            'tool_choice' => ['type' => 'auto'],
        ]);
    Http::assertSentCount(1);
});

it('returns the exact total as a native count with the estimate split scaled to it', function (): void {
    Http::fake(['*' => Http::response(responseFixture('anthropic/count_tokens.json'))]);

    $tally = app(AnthropicCounter::class)->count(anthropicStep());

    expect($tally->total)->toBe(2095)
        ->and($tally->method)->toBe(CountMethod::Native)
        ->and($tally->confidence)->toBe(0.0)
        ->and($tally->breakdown['instructions'])->toBeGreaterThan(0)
        ->and($tally->breakdown['history'])->toBeGreaterThan(0)
        ->and($tally->breakdown['prompt'])->toBeGreaterThan(0)
        ->and(array_sum($tally->breakdown))->toBeLessThanOrEqual(2095);
});

it('uses the base URL and version configured for the provider', function (): void {
    config(['ai.providers.anthropic' => ['driver' => 'anthropic', 'key' => 'k', 'url' => 'https://proxy.example.com/anthropic/v1/', 'version' => '2024-01-01']]);
    Http::fake(['*' => Http::response(responseFixture('anthropic/count_tokens.json'))]);

    app(AnthropicCounter::class)->count(anthropicStep());

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://proxy.example.com/anthropic/v1/messages/count_tokens'
        && $request->header('anthropic-version') === ['2024-01-01']);
});

it('gives a zero breakdown when the estimate has nothing to split', function (): void {
    Http::fake(['*' => Http::response(['input_tokens' => 7])]);

    $tally = app(AnthropicCounter::class)->count(new PendingStep(0, true, 'anthropic', 'claude-sonnet-5-5', null, [], [], null, null));

    expect($tally->total)->toBe(7)
        ->and(array_sum($tally->breakdown))->toBe(0);
});

it('surfaces provider errors', function (): void {
    Http::fake(['*' => Http::response(responseFixture('anthropic/count_tokens_invalid_request.json'), 400)]);

    app(AnthropicCounter::class)->count(anthropicStep());
})->throws(Exception::class);

it('rejects a response without an integer input_tokens', function (): void {
    Http::fake(['*' => Http::response(['tokens' => '2095'])]);

    app(AnthropicCounter::class)->count(anthropicStep());
})->throws(UnexpectedValueException::class, 'did not return an integer input_tokens');

it('rejects a laravel/ai provider it cannot count with', function (): void {
    $ai = Mockery::mock(AiManager::class);
    $ai->shouldReceive('textProvider')->with('custom')->andReturn(Mockery::mock(TextProvider::class));
    app()->instance(AiManager::class, $ai);

    $step = new PendingStep(0, true, 'custom', 'claude-sonnet-5-5', null, [new UserMessage('hi')], [], null, null);

    app(AnthropicCounter::class)->count($step);
})->throws(LogicException::class, 'The laravel/ai provider [custom] cannot be used for native token counting.');
