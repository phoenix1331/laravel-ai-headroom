<?php

declare(strict_types=1);

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\ToolChoice;
use Phoenix1331\AiHeadroom\Counting\StepFingerprint;
use Phoenix1331\AiHeadroom\Tests\Fixtures\LookupOrderTool;

function fingerprintStep(array $overrides = []): PendingStep
{
    $step = new PendingStep(
        number: 0,
        isFinalStep: true,
        provider: 'anthropic',
        model: 'claude-sonnet-5-5',
        instructions: 'You are a support agent.',
        messages: [new UserMessage('Where is my refund?')],
        tools: [],
        schema: null,
        options: null,
    );

    return new PendingStep(...[...get_object_vars($step), ...$overrides]);
}

it('is a stable SHA-256 hash', function (): void {
    expect(StepFingerprint::of(fingerprintStep()))
        ->toMatch('/^[a-f0-9]{64}$/')
        ->toBe(StepFingerprint::of(fingerprintStep()));
});

it('ignores parts of the step that do not change the token count', function (): void {
    expect(StepFingerprint::of(fingerprintStep(['number' => 3, 'isFinalStep' => false, 'timeout' => 5, 'invocationId' => 'abc'])))
        ->toBe(StepFingerprint::of(fingerprintStep()));
});

it('ignores generation-only options', function (): void {
    expect(StepFingerprint::of(fingerprintStep(['options' => new TextGenerationOptions(maxTokens: 10, temperature: 0.5)])))
        ->toBe(StepFingerprint::of(fingerprintStep(['options' => new TextGenerationOptions(maxTokens: 9_000)])));
});

it('changes when anything that is counted changes', function (array $overrides): void {
    expect(StepFingerprint::of(fingerprintStep($overrides)))->not->toBe(StepFingerprint::of(fingerprintStep()));
})->with([
    'provider' => [['provider' => 'claude-eu']],
    'model' => [['model' => 'claude-haiku-5-5']],
    'instructions' => [['instructions' => 'Be brief.']],
    'no instructions' => [['instructions' => null]],
    'prompt' => [['messages' => [new UserMessage('Where is my order?')]]],
    'history' => [['messages' => [new UserMessage('Hi'), new AssistantMessage('Hello'), new UserMessage('Where is my refund?')]]],
    'attachment' => [['messages' => [new UserMessage('Where is my refund?', [new Base64Image('aW1n', 'image/png')])]]],
    'tool call' => [['messages' => [new UserMessage('Where is my refund?'), new AssistantMessage('', collect([new ToolCall('c1', 'lookup', ['ref' => 'A'])]))]]],
    'tool result' => [['messages' => [new UserMessage('Where is my refund?'), new ToolResultMessage(collect([new ToolResult('c1', 'lookup', [], 'shipped')]))]]],
    'replay blocks' => [['messages' => [new UserMessage('Where is my refund?'), new AssistantMessage('ok', null, [['type' => 'thought', 'signature' => 's']])]]],
    'tools' => [['tools' => [new LookupOrderTool]]],
    'schema' => [['schema' => ['answer' => (new JsonSchemaTypeFactory)->string()]]],
    'tool choice' => [['options' => new TextGenerationOptions(toolChoice: ToolChoice::tool('lookup'))]],
    'provider options' => [['options' => new TextGenerationOptions(providerOptions: ['thinking' => ['type' => 'adaptive']])]],
]);

it('changes when a local attachment is edited in place', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'headroom');
    file_put_contents($path, 'first version');
    $step = fn (): PendingStep => fingerprintStep(['messages' => [new UserMessage('Summarise this', [new LocalDocument($path, 'text/plain')])]]);

    try {
        $before = StepFingerprint::of($step());
        file_put_contents($path, 'a much longer second version');
        clearstatcache();

        expect(StepFingerprint::of($step()))->not->toBe($before);
    } finally {
        unlink($path);
    }
});
