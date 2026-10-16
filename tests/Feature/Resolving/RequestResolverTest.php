<?php

declare(strict_types=1);

use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Phoenix1331\AiHeadroom\Resolving\RequestResolver;
use Phoenix1331\AiHeadroom\Tests\Fixtures\Agents\InstructionsOnlyAgent;

beforeEach(function (): void {
    config([
        'ai.default' => 'anthropic',
        'ai.providers.anthropic' => ['driver' => 'anthropic', 'key' => 'sk-ant-test'],
    ]);
});

function agentInput(?UserMessage $message): AgentInput
{
    return new class($message) implements AgentInput
    {
        public function __construct(private readonly ?UserMessage $message) {}

        public function message(): ?UserMessage
        {
            return $this->message;
        }

        public function decisions(): ?Decisions
        {
            return null;
        }
    };
}

it('builds a step from agent input that carries a user message', function (): void {
    $step = app(RequestResolver::class)->resolve(new InstructionsOnlyAgent, agentInput(new UserMessage('Where is my refund?')));

    expect($step->messages)->toHaveCount(1)
        ->and($step->messages[0]->content)->toBe('Where is my refund?');
});

it('rejects agent input without a user message, such as approval decisions', function (): void {
    app(RequestResolver::class)->resolve(new InstructionsOnlyAgent, agentInput(null));
})->throws(InvalidArgumentException::class, 'Headroom can only count agent input that contains a user message.');

it('rejects an agent whose provider() returns something that is not a provider', function (): void {
    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return '';
        }

        public function provider(): int
        {
            return 42;
        }
    };

    app(RequestResolver::class)->resolve($agent, 'Hello');
})->throws(InvalidArgumentException::class, 'Headroom could not work out the provider for');

it('rejects an agent with an empty provider list', function (): void {
    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return '';
        }

        public function provider(): array
        {
            return [];
        }
    };

    app(RequestResolver::class)->resolve($agent, 'Hello');
})->throws(InvalidArgumentException::class, 'No AI providers were configured for');
