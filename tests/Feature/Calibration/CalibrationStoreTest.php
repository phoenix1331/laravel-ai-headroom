<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Phoenix1331\AiHeadroom\Calibration\CalibrationStore;
use Phoenix1331\AiHeadroom\Data\Calibration;

function calibrationFor(string $model, float $factor = 0.9): Calibration
{
    return new Calibration($model, 5, $factor, 0.04, CarbonImmutable::parse('2026-10-16 12:00:00'));
}

it('saves and reads calibration per model', function (): void {
    $store = app(CalibrationStore::class);

    $store->save(calibrationFor('claude-sonnet-5-5'));

    expect($store->get('claude-sonnet-5-5')?->toArray())->toBe(calibrationFor('claude-sonnet-5-5')->toArray())
        ->and($store->get('gpt-6.1-sol'))->toBeNull();
});

it('lists, forgets and resets models', function (): void {
    $store = app(CalibrationStore::class);

    $store->save(calibrationFor('a'));
    $store->save(calibrationFor('b'));
    $store->save(calibrationFor('a', 0.8));

    expect($store->models())->toBe(['a', 'b'])
        ->and(array_map(fn (Calibration $c): float => $c->factor, $store->all()))->toBe([0.8, 0.9]);

    $store->forget('a');

    expect($store->models())->toBe(['b'])
        ->and($store->get('a'))->toBeNull();

    $store->reset();

    expect($store->models())->toBe([])
        ->and($store->get('b'))->toBeNull();
});

it('uses the configured cache store', function (): void {
    config(['cache.stores.calibration' => ['driver' => 'array'], 'ai-headroom.calibration.store' => 'calibration']);

    app(CalibrationStore::class)->save(calibrationFor('m'));

    expect(Cache::store('calibration')->get('headroom:calibration:m'))->toBeArray()
        ->and(Cache::store()->get('headroom:calibration:m'))->toBeNull();
});

it('ignores corrupt entries', function (): void {
    Cache::forever('headroom:calibration:m', ['model' => 'm', 'samples' => 'many']);
    Cache::forever('headroom:calibration-models', ['m', 42]);

    $store = app(CalibrationStore::class);

    expect($store->get('m'))->toBeNull()
        ->and($store->models())->toBe(['m'])
        ->and($store->all())->toBe([]);
});

it('rejects impossible calibration values', function (): void {
    new Calibration('m', 1, 0.0, 0.1, CarbonImmutable::now());
})->throws(InvalidArgumentException::class, 'Calibration for [m] needs samples >= 0, factor > 0 and error >= 0.');
