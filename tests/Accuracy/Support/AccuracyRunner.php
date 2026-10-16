<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Tests\Accuracy\Support;

use Illuminate\Contracts\Cache\Factory;
use Laravel\Ai\PendingStep;
use Phoenix1331\AiHeadroom\Calibration\CalibrationStore;
use Phoenix1331\AiHeadroom\Calibration\Calibrator;
use Phoenix1331\AiHeadroom\Counting\Contracts\Counter;
use Phoenix1331\AiHeadroom\Counting\EstimatingCounter;

/**
 * Compares local estimates with native counts, before and after calibration.
 *
 * Calibration is measured leave-one-out: each sample is corrected by a
 * calibrator trained only on the provider's other samples, so the "after"
 * figure is not flattered by testing on what it learned from. It reports the
 * corrected estimate (base x learned factor); in use the safety margin, which
 * shrinks towards its floor as samples accumulate, is added on top.
 */
final class AccuracyRunner
{
    /**
     * @param  EstimatingCounter  $estimator  An uncalibrated estimator, so its output is the base estimate.
     */
    public function __construct(
        private readonly EstimatingCounter $estimator,
        private readonly Factory $cache,
    ) {}

    /**
     * @param  array<string, PendingStep>  $steps
     * @return list<array{sample: string, native: int, estimate: int, error: float, calibrated: int, calibrated_error: float}>
     */
    public function run(Counter $native, array $steps): array
    {
        $measured = [];

        foreach ($steps as $name => $step) {
            $measured[$name] = [
                'native' => $native->count($step)->total,
                'estimate' => $this->estimator->count($step)->total,
            ];
        }

        $rows = [];

        foreach ($measured as $name => $counts) {
            $calibrated = $this->leaveOneOut($name, $steps[$name]->model, $measured);

            $rows[] = [
                'sample' => $name,
                'native' => $counts['native'],
                'estimate' => $counts['estimate'],
                'error' => self::error($counts['estimate'], $counts['native']),
                'calibrated' => $calibrated,
                'calibrated_error' => self::error($calibrated, $counts['native']),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, array{native: int, estimate: int}>  $measured
     */
    private function leaveOneOut(string $held, string $model, array $measured): int
    {
        $store = new CalibrationStore($this->cache, 'array');
        $store->reset();

        $calibrator = new Calibrator($store, true, 1, 0.5, 0.1, 0.02);

        foreach ($measured as $name => $counts) {
            if ($name !== $held) {
                $calibrator->observe($model, $counts['estimate'], $counts['native']);
            }
        }

        $correction = $calibrator->correctionFor($model);

        // The learned factor only: the safety margin added on top in use is deliberate padding, not inaccuracy.
        return $correction === null ? $measured[$held]['estimate'] : (int) round($measured[$held]['estimate'] * $correction->factor);
    }

    private static function error(int $estimate, int $native): float
    {
        return $native === 0 ? 0.0 : ($estimate - $native) / $native;
    }
}
