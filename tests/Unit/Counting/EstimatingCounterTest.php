<?php

declare(strict_types=1);

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Base64Video;
use Laravel\Ai\Files\File;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Phoenix1331\AiHeadroom\Counting\EstimatingCounter;
use Phoenix1331\AiHeadroom\Currency\ConfiguredExchangeRates;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroom\Enums\Currency;
use Phoenix1331\AiHeadroom\Models\ModelRegistry;
use Phoenix1331\AiHeadroom\Tests\Fixtures\LookupOrderTool;
use Phoenix1331\AiHeadroom\Tests\Fixtures\WordTokeniser;

function estimator(float $margin = 0.10, ?array $ratios = null, ?array $attachments = null): EstimatingCounter
{
    $spec = ['context_window' => 100_000, 'max_output_tokens' => 1_000, 'input_price' => 1, 'output_price' => 1];

    return new EstimatingCounter(
        tokeniser: new WordTokeniser,
        models: new ModelRegistry(
            defaults: [
                'gpt-test' => ['provider' => 'openai', ...$spec],
                'claude-test' => ['provider' => 'anthropic', ...$spec],
            ],
            overrides: [],
            fallback: $spec,
            currency: Currency::Usd,
            exchangeRates: new ConfiguredExchangeRates([]),
        ),
        ratios: $ratios ?? ['openai' => 1.0, 'anthropic' => 2.0, 'default' => 3.0],
        attachmentTokens: $attachments ?? ['image' => 100, 'document' => 500, 'other' => 50],
        safetyMargin: $margin,
    );
}

function step(string $model, array $messages, ?string $instructions = null, array $tools = [], ?array $schema = null, string $provider = 'openai'): PendingStep
{
    return new PendingStep(
        number: 0,
        isFinalStep: true,
        provider: $provider,
        model: $model,
        instructions: $instructions,
        messages: $messages,
        tools: $tools,
        schema: $schema,
        options: null,
    );
}

it('counts OpenAI models with the local tokeniser and no margin', function (): void {
    $tally = estimator()->count(step('gpt-test', [new UserMessage('where is my refund')], instructions: 'you are a support agent'));

    expect($tally->breakdown)->toBe([
        'instructions' => 5,
        'history' => 0,
        'tools' => 0,
        'attachments' => 0,
        'prompt' => 4,
    ])
        ->and($tally->total)->toBe(5 + 4 + 6)
        ->and($tally->method)->toBe(CountMethod::Estimated)
        ->and($tally->confidence)->toBe(0.02);
});

it('adds message overhead to the total only, per message plus once per request', function (): void {
    $tally = estimator()->count(step('gpt-test', [
        new UserMessage('one'),
        new AssistantMessage('two'),
        new UserMessage('three'),
    ]));

    expect(array_sum($tally->breakdown))->toBe(3)
        ->and($tally->total)->toBe(3 + 3 * 4);
});

it('scales other providers by their ratio and pads them with the safety margin', function (): void {
    $tally = estimator(margin: 0.10)->count(step('claude-test', [new UserMessage('where is my refund')], instructions: 'you are a support agent', provider: 'anthropic'));

    expect($tally->breakdown['instructions'])->toBe((int) ceil(5 * 2.0 * 1.1))
        ->and($tally->breakdown['prompt'])->toBe((int) ceil(4 * 2.0 * 1.1))
        ->and($tally->total)->toBe(11 + 9 + (int) ceil(6 * 2.2))
        ->and($tally->confidence)->toBe(0.10);
});

it('uses the model registry provider over the step provider', function (): void {
    $tally = estimator()->count(step('gpt-test', [new UserMessage('hello')], provider: 'openrouter'));

    expect($tally->confidence)->toBe(0.02);
});

it('falls back to the step provider, then the default ratio, for unknown models', function (): void {
    $known = estimator(margin: 0.0)->count(step('mystery', [new UserMessage('a b')], provider: 'anthropic'));
    $unknown = estimator(margin: 0.0)->count(step('mystery', [new UserMessage('a b')], provider: 'someone-else'));

    expect($known->breakdown['prompt'])->toBe(4)
        ->and($unknown->breakdown['prompt'])->toBe(6);
});

it('treats a ratio of one with no default as unscaled', function (): void {
    $tally = estimator(margin: 0.0, ratios: [])->count(step('mystery', [new UserMessage('a b')], provider: 'someone-else'));

    expect($tally->breakdown['prompt'])->toBe(2);
});

it('splits the final user message into the prompt and everything before it into history', function (): void {
    $tally = estimator()->count(step('gpt-test', [
        new UserMessage('first question here'),
        new AssistantMessage('first answer'),
        new UserMessage('follow up'),
    ]));

    expect($tally->breakdown['history'])->toBe(5)
        ->and($tally->breakdown['prompt'])->toBe(2);
});

it('counts everything as history when the last message is not from the user', function (): void {
    $tally = estimator()->count(step('gpt-test', [
        new UserMessage('look up AB-1'),
        new AssistantMessage('checking', collect([new ToolCall('call_1', 'lookup', ['reference' => 'AB-1'])])),
        new ToolResultMessage(collect([new ToolResult('call_1', 'lookup', ['reference' => 'AB-1'], 'it has shipped')])),
    ]));

    $call = (new WordTokeniser)->count((string) json_encode(['name' => 'lookup', 'arguments' => ['reference' => 'AB-1']], JSON_UNESCAPED_SLASHES));

    expect($tally->breakdown['prompt'])->toBe(0)
        ->and($tally->breakdown['history'])->toBe(3 + 1 + $call + 3);
});

it('counts tool definitions and the structured output schema as tools', function (): void {
    $schema = ['answer' => (new JsonSchemaTypeFactory)->string()->required()];

    $withTools = estimator()->count(step('gpt-test', [new UserMessage('hi')], tools: [new LookupOrderTool, new WebSearch], schema: $schema));
    $without = estimator()->count(step('gpt-test', [new UserMessage('hi')]));

    expect($without->breakdown['tools'])->toBe(0)
        ->and($withTools->breakdown['tools'])->toBeGreaterThan(5)
        ->and($withTools->breakdown['prompt'])->toBe($without->breakdown['prompt']);
});

it('assumes a fixed cost per attachment by kind and marks the count approximate', function (): void {
    $tally = estimator()->count(step('gpt-test', [
        new UserMessage('see attached', [new Base64Image(base64_encode('png'), 'image/png')]),
        new AssistantMessage('thanks'),
        new UserMessage('and this', [new Base64Document(base64_encode('pdf'), 'application/pdf')]),
    ]));

    expect($tally->breakdown['attachments'])->toBe(600)
        ->and($tally->confidence)->toBe(0.5);
});

it('does not scale attachments by the provider ratio', function (): void {
    $tally = estimator()->count(step('claude-test', [new UserMessage('see attached', [new Base64Image(base64_encode('png'), 'image/png')])], provider: 'anthropic'));

    expect($tally->breakdown['attachments'])->toBe(100);
});

it('counts each attachment kind, including custom files as other', function (File $file, int $tokens): void {
    $counter = estimator(attachments: ['image' => 1, 'document' => 2, 'audio' => 3, 'video' => 4, 'other' => 5]);

    expect($counter->count(step('gpt-test', [new UserMessage('see attached', [$file])]))->breakdown['attachments'])->toBe($tokens);
})->with([
    'image' => [fn () => new Base64Image(base64_encode('png'), 'image/png'), 1],
    'document' => [fn () => new Base64Document(base64_encode('pdf'), 'application/pdf'), 2],
    'audio' => [fn () => new Base64Audio(base64_encode('mp3'), 'audio/mpeg'), 3],
    'video' => [fn () => new Base64Video(base64_encode('mp4'), 'video/mp4'), 4],
    'custom' => [fn () => new class extends File {}, 5],
]);

it('counts an attachment kind with no configured tokens as zero', function (): void {
    $tally = estimator(attachments: ['image' => 100])->count(step('gpt-test', [new UserMessage('see attached', [new Base64Document(base64_encode('pdf'), 'application/pdf')])]));

    expect($tally->breakdown['attachments'])->toBe(0);
});

it('rejects invalid configuration', function (Closure $make, string $message): void {
    expect($make)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'zero ratio' => [fn () => estimator(ratios: ['anthropic' => 0]), 'estimate ratio for [anthropic] must be a number greater than zero'],
    'text ratio' => [fn () => estimator(ratios: ['anthropic' => 'big']), 'estimate ratio for [anthropic]'],
    'negative attachment' => [fn () => estimator(attachments: ['image' => -1]), 'attachment tokens for [image]'],
    'negative margin' => [fn () => estimator(margin: -0.1), 'safety margin cannot be negative'],
]);
