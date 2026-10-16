<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Data;

/**
 * How to correct a model's base estimate once calibration is active.
 */
final readonly class Correction
{
    /**
     * @param  float  $factor  Multiply the base estimate by this.
     * @param  float  $margin  Then pad by this fraction: the measured error, never below the configured floor.
     * @param  float  $error  The measured error, used as the corrected count's confidence.
     */
    public function __construct(
        public float $factor,
        public float $margin,
        public float $error,
        public int $samples,
    ) {}

    public function apply(int $baseEstimate): int
    {
        return (int) ceil($baseEstimate * $this->factor * (1 + $this->margin));
    }
}
