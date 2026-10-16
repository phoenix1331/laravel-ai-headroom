<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Phoenix1331\AiHeadroom\Calibration\CalibrationStore;
use Phoenix1331\AiHeadroom\Data\Calibration;

class CalibrationCommand extends Command
{
    protected $signature = 'headroom:calibration
        {--model= : Show or reset a single model}
        {--reset : Clear calibration so estimates start learning again}
        {--force : Reset without asking for confirmation}';

    protected $description = 'Show what Headroom has learned about each model\'s estimates, or reset it';

    public function handle(CalibrationStore $store, Repository $config): int
    {
        $model = $this->option('model');
        $model = is_string($model) && $model !== '' ? $model : null;

        if ($this->option('reset')) {
            return $this->reset($store, $model);
        }

        $calibrations = $model === null ? $store->all() : array_filter([$store->get($model)]);

        if ($calibrations === []) {
            $this->components->info($model === null
                ? 'No calibration data yet. Headroom learns from TokensUsed: use the EnsureHeadroom middleware or Headroom::recordUsage().'
                : "No calibration data for [{$model}] yet.");

            return self::SUCCESS;
        }

        $minSamples = $config->integer('ai-headroom.calibration.min_samples');
        $floor = $config->float('ai-headroom.safety_margin_floor');

        $this->table(
            ['Model', 'Samples', 'Factor', 'Error', 'Margin', 'Status', 'Updated'],
            array_map(fn (Calibration $calibration): array => [
                $calibration->model,
                $calibration->samples,
                number_format($calibration->factor, 3),
                $this->percent($calibration->error),
                $this->percent(max($floor, $calibration->error)),
                $calibration->samples >= $minSamples ? 'active' : "learning {$calibration->samples}/{$minSamples}",
                $calibration->updatedAt->diffForHumans(),
            ], $calibrations),
        );

        if (! $config->boolean('ai-headroom.calibration.enabled')) {
            $this->components->warn('Calibration is disabled (HEADROOM_CALIBRATION=false): this data is not being used or updated.');
        }

        return self::SUCCESS;
    }

    private function reset(CalibrationStore $store, ?string $model): int
    {
        $target = $model === null ? 'all models' : "[{$model}]";

        if (! $this->option('force') && ! $this->confirm("Clear calibration for {$target}? Estimates will learn again from scratch.")) {
            $this->components->info('Nothing was reset.');

            return self::SUCCESS;
        }

        $model === null ? $store->reset() : $store->forget($model);

        $this->components->info("Calibration cleared for {$target}.");

        return self::SUCCESS;
    }

    private function percent(float $fraction): string
    {
        return number_format($fraction * 100, 1).'%';
    }
}
