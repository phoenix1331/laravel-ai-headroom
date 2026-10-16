<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * Needs a constructor argument the container cannot provide.
 */
class ArgumentAgent implements Agent
{
    use Promptable;

    public function __construct(private readonly string $customerName) {}

    public function instructions(): string
    {
        return "You are helping {$this->customerName}.";
    }
}
