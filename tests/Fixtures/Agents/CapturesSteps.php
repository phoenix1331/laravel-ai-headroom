<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Tests\Fixtures\Agents;

use Closure;
use Laravel\Ai\PendingStep;

/**
 * Records the PendingStep the real SDK hands to agent middleware, for parity tests.
 */
trait CapturesSteps
{
    /** @var list<PendingStep> */
    public static array $captured = [];

    public function middleware(): array
    {
        return [function (PendingStep $step, Closure $next) {
            self::$captured[] = $step;

            return $next($step);
        }];
    }
}
