<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\PendingStep;
use Phoenix1331\AiHeadroom\Data\Limits;
use Phoenix1331\AiHeadroom\Enums\GuardMode;
use Phoenix1331\AiHeadroom\Events\HeadroomLow;
use Phoenix1331\AiHeadroom\Events\RequestBlocked;
use Phoenix1331\AiHeadroom\Events\TokensCounted;
use Phoenix1331\AiHeadroom\Exceptions\TokenLimitExceeded;
use Phoenix1331\AiHeadroom\HeadroomManager;
use Phoenix1331\AiHeadroom\Tracking\RequestTracker;

/**
 * Agent middleware that counts every generation step before it is sent, stops
 * (or reports) steps over the limits, and reports actual usage afterwards.
 *
 *     public function middleware(): array
 *     {
 *         return [new EnsureHeadroom(maxPercentOfContext: 80, maxCost: 0.20)];
 *     }
 *
 * Events: TokensCounted before each step; RequestBlocked then TokenLimitExceeded
 * (throw mode) or HeadroomLow (report mode) on a breach; TokensUsed when the step
 * completes, including after a stream; RequestFailed if the provider call throws.
 */
final class EnsureHeadroom
{
    private ?GuardMode $mode;

    /**
     * @param  float|null  $maxPercentOfContext  Null uses ai-headroom.middleware.max_percent_of_context.
     * @param  float|null  $maxCost  In the configured currency. Null uses ai-headroom.middleware.max_cost.
     * @param  GuardMode|string|null  $mode  Null uses ai-headroom.middleware.mode.
     */
    public function __construct(
        private readonly ?float $maxPercentOfContext = null,
        private readonly ?float $maxCost = null,
        GuardMode|string|null $mode = null,
    ) {
        $this->mode = is_string($mode) ? GuardMode::fromConfig($mode) : $mode;
    }

    /**
     * Fire HeadroomLow and let breaching requests through instead of throwing.
     */
    public function report(): self
    {
        return new self($this->maxPercentOfContext, $this->maxCost, GuardMode::Report);
    }

    /**
     * @param  Closure(PendingStep): StepResult  $next
     */
    public function handle(PendingStep $step, Closure $next): StepResult
    {
        $container = Container::getInstance();
        $manager = $container->make(HeadroomManager::class);
        $events = $container->make(Dispatcher::class);
        $config = $container->make(Repository::class);

        $count = $manager->countStep($step, $limits = $this->limits($config), $step->options?->agent);

        $events->dispatch(TokensCounted::from($count));

        $mode = $this->mode ?? GuardMode::fromConfig($config->string('ai-headroom.middleware.mode', 'throw'));

        foreach ($limits->breachesOf($count) as $breach) {
            if ($mode === GuardMode::Throw) {
                $events->dispatch(new RequestBlocked($count->requestId(), $step->invocationId, $breach->limit, $breach->limitValue, $breach->actualValue, $count, CarbonImmutable::now()));

                throw new TokenLimitExceeded($count, $breach->limit, $breach->limitValue, $breach->actualValue);
            }

            $events->dispatch(new HeadroomLow($count->requestId(), $step->invocationId, $breach->limit, $breach->limitValue, $breach->actualValue, $breach->reason(), CarbonImmutable::now()));
        }

        $tracker = $container->make(RequestTracker::class);

        if ($step->invocationId !== null) {
            $tracker->track($step->invocationId, $step->number, $count);
        }

        $result = $next($step);

        return $result->then(function (StepResponse $response) use ($manager, $events, $tracker, $step, $count, $result): void {
            if ($step->invocationId !== null) {
                $tracker->pull($step->invocationId, $step->number);
            }

            $events->dispatch($manager->usage($count, $response->usage, $response->meta, $step->invocationId, $result->streamed()));
        });
    }

    private function limits(Repository $config): Limits
    {
        $percent = $this->maxPercentOfContext ?? $config->get('ai-headroom.middleware.max_percent_of_context');
        $cost = $this->maxCost ?? $config->get('ai-headroom.middleware.max_cost');

        return new Limits(
            maxPercentOfContext: is_numeric($percent) ? (float) $percent : null,
            maxCost: is_numeric($cost) ? (float) $cost : null,
        );
    }
}
