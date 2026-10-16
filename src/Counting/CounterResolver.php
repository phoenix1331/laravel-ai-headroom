<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Counting;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Laravel\Ai\AiManager;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Providers\Provider;
use LogicException;
use Phoenix1331\AiHeadroom\Counting\Contracts\Counter;
use Phoenix1331\AiHeadroom\Data\Tally;
use Phoenix1331\AiHeadroom\Enums\CountingMode;

/**
 * Picks the counter for each step: a native counter registered for the step's
 * laravel/ai driver, or the local estimate, depending on the counting mode.
 */
class CounterResolver implements Counter
{
    /** @var array<string, string> */
    private array $counters = [];

    /**
     * @param  array<array-key, mixed>  $counters  laravel/ai driver => Counter class, e.g. ['anthropic' => AnthropicCounter::class].
     */
    public function __construct(
        private readonly Container $container,
        private readonly AiManager $ai,
        private readonly EstimatingCounter $estimator,
        private readonly CountingMode $mode,
        array $counters,
    ) {
        foreach ($counters as $driver => $class) {
            if (! is_string($class) || ! is_subclass_of($class, Counter::class)) {
                throw new InvalidArgumentException("The Headroom counter for [{$driver}] must be a class implementing ".Counter::class.'.');
            }

            $this->counters[(string) $driver] = $class;
        }
    }

    public function count(PendingStep $step): Tally
    {
        return $this->counterFor($step)->count($step);
    }

    public function counterFor(PendingStep $step): Counter
    {
        if ($this->mode === CountingMode::Estimate) {
            return $this->estimator;
        }

        $provider = $this->provider($step);

        if ($provider === null) {
            return $this->estimator;
        }

        $class = $this->counters[$provider->driver()] ?? null;

        if ($class === null) {
            return $this->estimator;
        }

        if ($this->mode === CountingMode::Auto && blank($provider->providerCredentials()['key'] ?? null)) {
            return $this->estimator;
        }

        $counter = $this->container->make($class);

        if (! $counter instanceof Counter) {
            throw new LogicException("The Headroom counter [{$class}] did not resolve to a ".Counter::class.'.');
        }

        return $counter;
    }

    /**
     * The step's laravel/ai provider. In auto mode an unknown provider means
     * "estimate"; in native mode it is a configuration error and is thrown.
     */
    private function provider(PendingStep $step): ?Provider
    {
        try {
            $provider = $this->ai->textProvider($step->provider);
        } catch (InvalidArgumentException $exception) {
            if ($this->mode === CountingMode::Native) {
                throw $exception;
            }

            return null;
        }

        return $provider instanceof Provider ? $provider : null;
    }
}
