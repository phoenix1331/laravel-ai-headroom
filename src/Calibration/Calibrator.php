<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Calibration;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Phoenix1331\AiHeadroom\Data\Calibration;
use Phoenix1331\AiHeadroom\Data\Correction;

/**
 * Learns, per model, how far Headroom's estimates are from what providers
 * actually bill, and how to correct them.
 *
 * The base estimate is the estimating counter's output before calibration
 * (ratio and safety margin included). For each observation:
 *
 *  - ratio = actual / base, clamped to 1 +/- maxCorrection
 *  - factor moves towards ratio by `smoothing` (the first observation sets it)
 *  - error moves towards |actual - base x previous factor| / actual the same way
 *
 * Once a model has minSamples, base estimates are corrected to
 * base x factor x (1 + max(floor, error)).
 */
class Calibrator
{
    public function __construct(
        private readonly CalibrationStore $store,
        private readonly bool $enabled,
        private readonly int $minSamples,
        private readonly float $maxCorrection,
        private readonly float $smoothing,
        private readonly float $marginFloor,
    ) {
        if ($minSamples < 1) {
            throw new InvalidArgumentException('Headroom calibration min_samples must be at least 1.');
        }

        if ($maxCorrection <= 0 || $maxCorrection >= 1) {
            throw new InvalidArgumentException('Headroom calibration max_correction must be greater than 0 and less than 1.');
        }

        if ($smoothing <= 0 || $smoothing > 1) {
            throw new InvalidArgumentException('Headroom calibration smoothing must be greater than 0 and at most 1.');
        }

        if ($marginFloor < 0) {
            throw new InvalidArgumentException('Headroom safety_margin_floor cannot be negative.');
        }
    }

    /**
     * Record one estimate against the input tokens the provider reported.
     */
    public function observe(string $model, int $baseEstimate, int $actual): ?Calibration
    {
        if (! $this->enabled || $baseEstimate <= 0 || $actual <= 0) {
            return null;
        }

        $previous = $this->store->get($model);

        $ratio = $this->clamp($actual / $baseEstimate);
        $observedError = abs($actual - $baseEstimate * ($previous->factor ?? 1.0)) / $actual;

        $calibration = new Calibration(
            model: $model,
            samples: ($previous->samples ?? 0) + 1,
            factor: $this->clamp($previous === null ? $ratio : $previous->factor + $this->smoothing * ($ratio - $previous->factor)),
            error: $previous === null ? $observedError : $previous->error + $this->smoothing * ($observedError - $previous->error),
            updatedAt: CarbonImmutable::now(),
        );

        $this->store->save($calibration);

        return $calibration;
    }

    /**
     * The correction to apply to the model's estimates, or null until it has enough samples.
     */
    public function correctionFor(string $model): ?Correction
    {
        if (! $this->enabled) {
            return null;
        }

        $calibration = $this->store->get($model);

        if ($calibration === null || $calibration->samples < $this->minSamples) {
            return null;
        }

        return new Correction(
            factor: $calibration->factor,
            margin: max($this->marginFloor, $calibration->error),
            error: $calibration->error,
            samples: $calibration->samples,
        );
    }

    public function store(): CalibrationStore
    {
        return $this->store;
    }

    private function clamp(float $value): float
    {
        return max(1 - $this->maxCorrection, min(1 + $this->maxCorrection, $value));
    }
}
