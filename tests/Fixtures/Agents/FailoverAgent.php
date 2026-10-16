<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

class FailoverAgent implements Agent, HasMiddleware
{
    use CapturesSteps;
    use Promptable;

    public function instructions(): string
    {
        return 'You have backups.';
    }

    public function provider(): array
    {
        return [Lab::OpenAI->value => 'gpt-6-luna', Lab::Anthropic->value => 'claude-haiku-5-5'];
    }

    public function model(): string
    {
        return 'ignored-for-failover-lists';
    }
}
