<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What Headroom has learned about one model's estimates from real usage.
 */
final readonly class Calibration
{
    /**
     * @param  int  $samples  Observations recorded.
     * @param  float  $factor  Weighted average of actual / base estimate; multiply a base estimate by it.
     * @param  float  $error  Weighted average relative error of the corrected estimate, e.g. 0.03 for 3%.
     */
    public function __construct(
        public string $model,
        public int $samples,
        public float $factor,
        public float $error,
        public CarbonImmutable $updatedAt,
    ) {
        if ($samples < 0 || $factor <= 0 || $error < 0) {
            throw new InvalidArgumentException("Calibration for [{$model}] needs samples >= 0, factor > 0 and error >= 0.");
        }
    }

    /**
     * @return array{model: string, samples: int, factor: float, error: float, updated_at: string}
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'samples' => $this->samples,
            'factor' => $this->factor,
            'error' => $this->error,
            'updated_at' => $this->updatedAt->toIso8601String(),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        if (! is_string($data['model'] ?? null) || ! is_int($data['samples'] ?? null) || ! is_numeric($data['factor'] ?? null) || ! is_numeric($data['error'] ?? null) || ! is_string($data['updated_at'] ?? null)) {
            return null;
        }

        return new self($data['model'], $data['samples'], (float) $data['factor'], (float) $data['error'], CarbonImmutable::parse($data['updated_at']));
    }
}
