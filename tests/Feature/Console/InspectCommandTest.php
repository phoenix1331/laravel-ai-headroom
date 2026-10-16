<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Phoenix1331\AiHeadroom\Counting\Contracts\Tokeniser;
use Phoenix1331\AiHeadroom\Events\TokensCounted;
use Phoenix1331\AiHeadroom\Tests\Fixtures\Agents\ArgumentAgent;
use Phoenix1331\AiHeadroom\Tests\Fixtures\Agents\InstructionsOnlyAgent;
use Phoenix1331\AiHeadroom\Tests\Fixtures\Agents\ToolsAgent;
use Phoenix1331\AiHeadroom\Tests\Fixtures\WordTokeniser;

beforeEach(function (): void {
    app()->instance(Tokeniser::class, new WordTokeniser);
    config([
        'ai-headroom.method' => 'estimate',
        'ai.default' => 'anthropic',
        'ai.providers.anthropic' => ['driver' => 'anthropic', 'key' => 'sk-ant-test'],
        'ai.providers.openai' => ['driver' => 'openai', 'key' => 'sk-test'],
    ]);
});

/**
 * InstructionsOnlyAgent on gpt-6-luna counts exact words: 13 instructions, 4 prompt, 6 overhead = 23.
 *
 * @return array<string, mixed>
 */
function lunaArguments(array $extra = []): array
{
    return [
        'agent' => InstructionsOnlyAgent::class,
        'prompt' => 'Where is my refund?',
        '--provider' => 'openai',
        '--model' => 'gpt-6-luna',
        ...$extra,
    ];
}

it('shows the breakdown with shares, context usage and estimated cost', function (): void {
    $this->artisan('headroom:inspect', lunaArguments())
        ->expectsOutputToContain(InstructionsOnlyAgent::class)
        ->expectsOutputToContain('gpt-6-luna (openai)')
        ->expectsOutputToContain('Estimated (expected error 2%)')
        ->expectsTable(['Part', 'Tokens', 'Share'], [
            ['Instructions', '13', '56.52%'],
            ['History', '0', '0%'],
            ['Tools', '0', '0%'],
            ['Attachments', '0', '0%'],
            ['Prompt', '4', '17.39%'],
            ['Message overhead', '6', '26.09%'],
            ['<options=bold>Total</>', '<options=bold>23</>', '100%'],
        ])
        ->expectsOutputToContain('23 of 1,050,000 tokens (0%), 1,049,977 remaining')
        ->expectsOutputToContain('$0.000002 input + up to $0.0640 output = up to $0.0640 USD')
        ->expectsOutputToContain('The request fits the context window.')
        ->assertSuccessful();
});

it('uses the agent provider and model when none are given', function (): void {
    $this->artisan('headroom:inspect', ['agent' => ToolsAgent::class, 'prompt' => 'Has AB-1 shipped?'])
        ->expectsOutputToContain('claude-sonnet-5-5 (anthropic)')
        ->assertSuccessful();
});

it('outputs the full count as JSON', function (): void {
    Artisan::call('headroom:inspect', lunaArguments(['--json' => true]));

    $json = json_decode(Artisan::output(), true);

    expect($json)->toMatchArray([
        'agent' => InstructionsOnlyAgent::class,
        'provider' => 'openai',
        'model' => 'gpt-6-luna',
        'total' => 23,
        'method' => 'estimated',
        'fits' => true,
    ])
        ->and($json['breakdown'])->toBe(['instructions' => 13, 'history' => 0, 'tools' => 0, 'attachments' => 0, 'prompt' => 4])
        ->and($json['request_id'])->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
});

it('fails, so it can gate CI, when the request does not fit', function (): void {
    config(['ai-headroom.models.gpt-6-luna.context_window' => 20]);

    $this->artisan('headroom:inspect', lunaArguments())
        ->expectsOutputToContain('The request is 3 tokens over the context window.')
        ->assertFailed();
});

it('fires no events, so inspecting never reaches a dashboard', function (): void {
    Event::fake([TokensCounted::class]);

    $this->artisan('headroom:inspect', lunaArguments())->assertSuccessful();

    Event::assertNotDispatched(TokensCounted::class);
});

it('explains an agent class that cannot be inspected', function (string $class, string $message): void {
    $this->artisan('headroom:inspect', ['agent' => $class, 'prompt' => 'Hello'])
        ->expectsOutputToContain($message)
        ->assertExitCode(2);
})->with([
    'missing' => ['App\\Ai\\Agents\\MissingAgent', 'Agent class [App\\Ai\\Agents\\MissingAgent] does not exist.'],
    'not an agent' => [stdClass::class, '[stdClass] is not a laravel/ai agent.'],
    'needs arguments' => [ArgumentAgent::class, 'it needs constructor arguments the container cannot provide'],
]);
