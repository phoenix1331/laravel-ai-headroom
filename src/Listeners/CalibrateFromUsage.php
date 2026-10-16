<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Listeners;

use Phoenix1331\AiHeadroom\Calibration\Calibrator;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroom\Events\TokensUsed;

/**
 * Feeds the calibrator from TokensUsed, the same data a companion store records.
 *
 * Only local estimates are learned from; native counts are already exact. An
 * observation is skipped when a different model answered (failover), since the
 * estimate was made for the counted model. A calibrated estimate is divided back
 * to its base using the model's current correction.
 */
class CalibrateFromUsage
{
    public function __construct(private readonly Calibrator $calibrator) {}

    public function handle(TokensUsed $event): void
    {
        if ($event->countMethod === CountMethod::Native || ! $this->sameModel($event)) {
            return;
        }

        $this->calibrator->observe($event->countedModel, $this->baseEstimate($event), $event->inputTokens);
    }

    /**
     * True when the counted model answered, including as a dated snapshot of itself.
     */
    private function sameModel(TokensUsed $event): bool
    {
        return $event->model === $event->countedModel || str_starts_with($event->model, $event->countedModel.'-');
    }

    private function baseEstimate(TokensUsed $event): int
    {
        if ($event->countMethod !== CountMethod::Calibrated) {
            return $event->estimatedInputTokens;
        }

        $correction = $this->calibrator->correctionFor($event->countedModel);

        if ($correction === null) {
            return $event->estimatedInputTokens;
        }

        return (int) round($event->estimatedInputTokens / ($correction->factor * (1 + $correction->margin)));
    }
}
