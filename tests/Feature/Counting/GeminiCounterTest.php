<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\ProviderDocument;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Phoenix1331\AiHeadroom\Counting\Contracts\Tokeniser;
use Phoenix1331\AiHeadroom\Counting\GeminiCounter;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroom\Tests\Fixtures\LookupOrderTool;
use Phoenix1331\AiHeadroom\Tests\Fixtures\WordTokeniser;

beforeEach(function (): void {
    config(['ai.providers.gemini' => ['driver' => 'gemini', 'key' => 'g-test']]);
    app()->instance(Tokeniser::class, new WordTokeniser);
});

function geminiStep(array $messages, ?string $instructions = null, array $tools = [], ?array $schema = null, string $model = 'gemini-3.8-flash'): PendingStep
{
    return new PendingStep(0, true, 'gemini', $model, $instructions, $messages, $tools, $schema, null);
}

it('maps the step to a generateContentRequest for countTokens', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(responseFixture('gemini/count_tokens.json'))]);

    app(GeminiCounter::class)->count(geminiStep(
        messages: [
            new UserMessage('Is AB-1 shipped?', [new Base64Image('aW1n', 'image/png'), new ProviderDocument('files/abc123')]),
            new AssistantMessage('', collect([new ToolCall('call_1', 'LookupOrderTool', ['reference' => 'AB-1'])])),
            new ToolResultMessage(collect([new ToolResult('call_1', 'LookupOrderTool', ['reference' => 'AB-1'], 'shipped')])),
            new AssistantMessage('It has shipped.'),
            new UserMessage('Thanks'),
        ],
        instructions: 'You are a support agent.',
        tools: [new LookupOrderTool, new WebSearch],
        schema: ['answer' => (new JsonSchemaTypeFactory)->string()->required()],
    ));

    $objectSchema = fn (string $property): array => [
        'additionalProperties' => false,
        'properties' => [$property => ['type' => 'string']],
        'type' => 'object',
        'required' => [$property],
    ];

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:countTokens'
        && $request->method() === 'POST'
        && $request->header('x-goog-api-key') === ['g-test']
        && json_decode($request->body(), true) === ['generateContentRequest' => [
            'model' => 'models/gemini-3.8-flash',
            'contents' => [
                ['role' => 'user', 'parts' => [
                    ['text' => 'Is AB-1 shipped?'],
                    ['inlineData' => ['mimeType' => 'image/png', 'data' => 'aW1n']],
                    ['fileData' => ['fileUri' => 'files/abc123']],
                ]],
                ['role' => 'model', 'parts' => [
                    ['functionCall' => ['id' => 'call_1', 'name' => 'LookupOrderTool', 'args' => ['reference' => 'AB-1']]],
                ]],
                ['role' => 'user', 'parts' => [
                    ['functionResponse' => ['id' => 'call_1', 'name' => 'LookupOrderTool', 'response' => ['result' => 'shipped']]],
                ]],
                ['role' => 'model', 'parts' => [['text' => 'It has shipped.']]],
                ['role' => 'user', 'parts' => [['text' => 'Thanks']]],
            ],
            'systemInstruction' => ['parts' => [['text' => 'You are a support agent.']]],
            'tools' => [['functionDeclarations' => [[
                'name' => 'LookupOrderTool',
                'description' => 'Look up an order by reference',
                'parametersJsonSchema' => $objectSchema('reference'),
            ]]]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseJsonSchema' => $objectSchema('answer'),
            ],
        ]]);
    Http::assertSentCount(1);
});

it('sends only the fields a plain prompt needs', function (): void {
    Http::fake(['*' => Http::response(responseFixture('gemini/count_tokens.json'))]);

    app(GeminiCounter::class)->count(geminiStep([new UserMessage('Hello')]));

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true) === ['generateContentRequest' => [
        'model' => 'models/gemini-3.8-flash',
        'contents' => [['role' => 'user', 'parts' => [['text' => 'Hello']]]],
    ]]);
});

it('skips messages with nothing to send, since Gemini rejects empty content', function (): void {
    Http::fake(['*' => Http::response(responseFixture('gemini/count_tokens.json'))]);

    app(GeminiCounter::class)->count(geminiStep([new UserMessage('Hello'), new AssistantMessage(''), new UserMessage('Anyone there?')]));

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['generateContentRequest']['contents'] === [
        ['role' => 'user', 'parts' => [['text' => 'Hello']]],
        ['role' => 'user', 'parts' => [['text' => 'Anyone there?']]],
    ]);
});

it('accepts model names that already carry the models/ prefix', function (): void {
    Http::fake(['*' => Http::response(responseFixture('gemini/count_tokens.json'))]);

    app(GeminiCounter::class)->count(geminiStep([new UserMessage('Hello')], model: 'models/gemini-3.1-flash-lite'));

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1beta/models/gemini-3.1-flash-lite:countTokens')
        && json_decode($request->body(), true)['generateContentRequest']['model'] === 'models/gemini-3.1-flash-lite');
});

it('returns the exact total as a native count', function (): void {
    Http::fake(['*' => Http::response(responseFixture('gemini/count_tokens.json'))]);

    $tally = app(GeminiCounter::class)->count(geminiStep([new UserMessage('Where is my refund?')], instructions: 'Be brief.'));

    expect($tally->total)->toBe(31)
        ->and($tally->method)->toBe(CountMethod::Native)
        ->and($tally->confidence)->toBe(0.0)
        ->and(array_sum($tally->breakdown))->toBeLessThanOrEqual(31);
});

it('uses the base URL configured for the provider', function (): void {
    config(['ai.providers.gemini' => ['driver' => 'gemini', 'key' => 'k', 'url' => 'https://proxy.example.com/gemini/v1beta/']]);
    Http::fake(['*' => Http::response(responseFixture('gemini/count_tokens.json'))]);

    app(GeminiCounter::class)->count(geminiStep([new UserMessage('Hello')]));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://proxy.example.com/gemini/v1beta/models/gemini-3.8-flash:countTokens');
});

it('surfaces provider errors', function (): void {
    Http::fake(['*' => Http::response(responseFixture('gemini/count_tokens_invalid_request.json'), 400)]);

    app(GeminiCounter::class)->count(geminiStep([new UserMessage('Hello')]));
})->throws(Exception::class);

it('rejects a response without an integer totalTokens', function (): void {
    Http::fake(['*' => Http::response(['totalTokens' => '31'])]);

    app(GeminiCounter::class)->count(geminiStep([new UserMessage('Hello')]));
})->throws(UnexpectedValueException::class, 'did not return an integer totalTokens');
