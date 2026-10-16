<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Phoenix1331\AiHeadroom\Middleware\EnsureHeadroom;
use Phoenix1331\AiHeadroom\Tests\Fixtures\LookupOrderTool;

/**
 * An agent guarded by EnsureHeadroom; tests set the guard and provider list.
 */
class GuardedAgent implements Agent, HasMiddleware, HasTools
{
    use Promptable;

    public static ?EnsureHeadroom $guard = null;

    /** @var array<int|string, string>|string */
    public static array|string $providers = 'anthropic';

    public function instructions(): string
    {
        return 'You are a careful support agent.';
    }

    public function middleware(): array
    {
        return [self::$guard ?? new EnsureHeadroom];
    }

    public function tools(): iterable
    {
        return [new LookupOrderTool];
    }

    public function provider(): array|string
    {
        return self::$providers;
    }
}
