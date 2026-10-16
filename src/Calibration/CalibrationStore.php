<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Calibration;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Phoenix1331\AiHeadroom\Data\Calibration;

/**
 * Keeps calibration per model in the cache, forever, with an index of models
 * so they can be listed and reset. Holds numbers only, never prompt content.
 */
class CalibrationStore
{
    private const string PREFIX = 'headroom:calibration:';

    private const string INDEX = 'headroom:calibration-models';

    public function __construct(
        private readonly Factory $cache,
        private readonly ?string $store = null,
    ) {}

    public function get(string $model): ?Calibration
    {
        $data = $this->repository()->get(self::PREFIX.$model);

        return is_array($data) ? Calibration::fromArray($data) : null;
    }

    public function save(Calibration $calibration): void
    {
        $this->repository()->forever(self::PREFIX.$calibration->model, $calibration->toArray());

        $models = $this->models();

        if (! in_array($calibration->model, $models, true)) {
            $models[] = $calibration->model;
            $this->repository()->forever(self::INDEX, $models);
        }
    }

    /**
     * @return list<string>
     */
    public function models(): array
    {
        $models = $this->repository()->get(self::INDEX);

        return is_array($models) ? array_values(array_filter($models, is_string(...))) : [];
    }

    /**
     * @return list<Calibration>
     */
    public function all(): array
    {
        return array_values(array_filter(array_map($this->get(...), $this->models())));
    }

    public function forget(string $model): void
    {
        $this->repository()->forget(self::PREFIX.$model);
        $this->repository()->forever(self::INDEX, array_values(array_diff($this->models(), [$model])));
    }

    public function reset(): void
    {
        foreach ($this->models() as $model) {
            $this->repository()->forget(self::PREFIX.$model);
        }

        $this->repository()->forget(self::INDEX);
    }

    private function repository(): Repository
    {
        return $this->cache->store($this->store);
    }
}
