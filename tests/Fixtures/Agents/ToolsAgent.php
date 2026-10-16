<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Phoenix1331\AiHeadroom\Tests\Fixtures\LookupOrderTool;

/**
 * Function tools, a sub-agent, and provider tools that only some providers support.
 */
class ToolsAgent implements Agent, HasMiddleware, HasTools
{
    use CapturesSteps;
    use Promptable;

    public function instructions(): string
    {
        return 'You can look up orders and search the web.';
    }

    public function tools(): iterable
    {
        return [
            new LookupOrderTool,
            new InstructionsOnlyAgent,
            new WebSearch,
            new WebFetch,
            new ToolSearch([new LookupOrderTool]),
        ];
    }
}
