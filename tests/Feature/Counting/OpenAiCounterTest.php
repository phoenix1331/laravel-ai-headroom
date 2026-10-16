<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;
use Phoenix1331\AiHeadroom\Counting\Contracts\Tokeniser;
use Phoenix1331\AiHeadroom\Counting\OpenAiCounter;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroom\Tests\Fixtures\LookupOrderTool;
use Phoenix1331\AiHeadroom\Tests\Fixtures\WordTokeniser;

beforeEach(function (): void {
    config(['ai.providers.openai' => ['driver' => 'openai', 'key' => 'sk-test']]);
    app()->instance(Tokeniser::class, new WordTokeniser);
});

function openAiStep(array $tools = [], ?TextGenerationOptions $options = null): PendingStep
{
    return new PendingStep(0, true, 'openai', 'gpt-6.1-sol', 'You are a support agent.', [
        new UserMessage('Hi'),
        new AssistantMessage('Hello'),
        new UserMessage('Where is my refund?'),
    ], $tools, null, $options);
}

it('sends the SDK Responses body to input_tokens, without generation-only fields', function (): void {
    Http::fake(['api.openai.com/v1/responses/input_tokens' => Http::response(responseFixture('openai/input_tokens.json'))]);

    app(OpenAiCounter::class)->count(openAiStep(
        tools: [new LookupOrderTool],
        options: new TextGenerationOptions(maxTokens: 4_000, temperature: 0.2),
    ));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/responses/input_tokens'
        && $request->method() === 'POST'
        && $request->header('Authorization') === ['Bearer sk-test']
        && json_decode($request->body(), true) === [
            'model' => 'gpt-6.1-sol',
            'input' => [
                ['role' => 'system', 'content' => 'You are a support agent.'],
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Hi']]],
                ['role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Hello']]],
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Where is my refund?']]],
            ],
            'tool_choice' => 'auto',
            'tools' => [[
                'type' => 'function',
                'name' => 'LookupOrderTool',
                'description' => 'Look up an order by reference',
                'strict' => false,
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['reference' => ['type' => 'string']],
                    'required' => ['reference'],
                    'additionalProperties' => false,
                ],
            ]],
        ]);
    Http::assertSentCount(1);
});

it('returns the exact total as a native count', function (): void {
    Http::fake(['*' => Http::response(responseFixture('openai/input_tokens.json'))]);

    $tally = app(OpenAiCounter::class)->count(openAiStep());

    expect($tally->total)->toBe(11)
        ->and($tally->method)->toBe(CountMethod::Native)
        ->and($tally->confidence)->toBe(0.0)
        ->and(array_sum($tally->breakdown))->toBeLessThanOrEqual(11);
});

it('uses the base URL configured for the provider', function (): void {
    config(['ai.providers.openai' => ['driver' => 'openai', 'key' => 'k', 'url' => 'https://proxy.example.com/openai/v1/']]);
    Http::fake(['*' => Http::response(responseFixture('openai/input_tokens.json'))]);

    app(OpenAiCounter::class)->count(openAiStep());

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://proxy.example.com/openai/v1/responses/input_tokens');
});

it('surfaces provider errors', function (): void {
    Http::fake(['*' => Http::response(responseFixture('openai/input_tokens_invalid_request.json'), 400)]);

    app(OpenAiCounter::class)->count(openAiStep());
})->throws(Exception::class);

it('rejects a response without an integer input_tokens', function (): void {
    Http::fake(['*' => Http::response(['object' => 'response.input_tokens'])]);

    app(OpenAiCounter::class)->count(openAiStep());
})->throws(UnexpectedValueException::class, 'did not return an integer input_tokens');
