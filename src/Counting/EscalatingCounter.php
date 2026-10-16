<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Counting;

use InvalidArgumentException;
use Laravel\Ai\PendingStep;
use Phoenix1331\AiHeadroom\Counting\Contracts\Counter;
use Phoenix1331\AiHeadroom\Data\Limits;
use Phoenix1331\AiHeadroom\Data\Tally;
use Phoenix1331\AiHeadroom\Models\ModelRegistry;

/**
 * The auto mode counter: estimate locally, and pay for a native count only
 * when the estimate is too close to a limit to decide on. Clear passes and
 * clear fails never call the provider.
 */
class EscalatingCounter implements Counter
{
    /**
     * @param  float  $band  How close to a limit counts as borderline, as a fraction either side (0.15 = within 15%).
     */
    public function __construct(
        private readonly EstimatingCounter $estimator,
        private readonly Counter $native,
        private readonly ModelRegistry $models,
        private readonly float $band,
    ) {
        if ($band < 0 || $band >= 1) {
            throw new InvalidArgumentException('The Headroom escalation band must be at least 0 and less than 1.');
        }
    }

    public function count(PendingStep $step, ?Limits $limits = null): Tally
    {
        $estimate = $this->estimator->count($step, $limits);

        if (! $this->isBorderline($estimate, $step, $limits ?? new Limits)) {
            return $estimate;
        }

        return $this->native->count($step, $limits);
    }

    public function native(): Counter
    {
        return $this->native;
    }

    /**
     * Borderline when no limit is clearly exceeded and at least one is within the band.
     */
    private function isBorderline(Tally $estimate, PendingStep $step, Limits $limits): bool
    {
        $borderline = false;

        foreach ($this->checks($estimate, $step, $limits) as [$value, $ceiling]) {
            if ($value > $ceiling * (1 + $this->band)) {
                return false;
            }

            if ($value >= $ceiling * (1 - $this->band)) {
                $borderline = true;
            }
        }

        return $borderline;
    }

    /**
     * @return list<array{float, float}> Pairs of [estimated value, ceiling].
     */
    private function checks(Tally $estimate, PendingStep $step, Limits $limits): array
    {
        $window = (float) $this->models->get($step->model)->contextWindow;

        $checks = [[(float) $estimate->total, $window]];

        if ($limits->maxPercentOfContext !== null) {
            $checks[] = [(float) $estimate->total, $window * $limits->maxPercentOfContext / 100];
        }

        if ($limits->maxCost !== null) {
            $cost = $this->models->costFor($step->model, $estimate->total, $step->options?->maxTokens)->total();
            $checks[] = [$cost, $limits->maxCost];
        }

        return $checks;
    }
}
