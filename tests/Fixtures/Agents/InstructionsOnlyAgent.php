<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Promptable;

class InstructionsOnlyAgent implements Agent, HasMiddleware
{
    use CapturesSteps;
    use Promptable;

    public function instructions(): string
    {
        return 'You are a support agent for an online shop. Be brief and friendly.';
    }
}
