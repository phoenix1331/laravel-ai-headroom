<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Promptable;

class MethodModelAgent implements Agent, HasMiddleware
{
    use CapturesSteps;
    use Promptable;

    public function instructions(): string
    {
        return 'Be concise.';
    }

    public function provider(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return 'gpt-6-luna';
    }
}
