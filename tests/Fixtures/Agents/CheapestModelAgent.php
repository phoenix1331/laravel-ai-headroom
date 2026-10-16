<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Tests\Fixtures\Agents;

use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Promptable;

#[UseCheapestModel]
class CheapestModelAgent implements Agent, HasMiddleware
{
    use CapturesSteps;
    use Promptable;

    public function instructions(): string
    {
        return 'Route the request.';
    }

    public function provider(): string
    {
        return 'openai';
    }
}
