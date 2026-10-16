<?php

declare(strict_types=1);

use Phoenix1331\AiHeadroom\Calibration\CalibrationStore;
use Phoenix1331\AiHeadroom\Calibration\Calibrator;

function calibrator(bool $enabled = true, int $minSamples = 20, float $maxCorrection = 0.5, float $smoothing = 0.1, float $floor = 0.02): Calibrator
{
    return new Calibrator(app(CalibrationStore::class), $enabled, $minSamples, $maxCorrection, $smoothing, $floor);
}

it('sets the factor and error from the first observation', function (): void {
    $calibration = calibrator()->observe('claude-sonnet-5-5', baseEstimate: 1_000, actual: 900);

    expect($calibration->samples)->toBe(1)
        ->and($calibration->factor)->toBe(0.9)
        ->and($calibration->error)->toEqualWithDelta(100 / 900, 1e-12);
});

it('moves the factor and error towards each new observation by the smoothing amount', function (): void {
    $calibrator = calibrator();

    $calibrator->observe('m', 1_000, 900);
    $second = $calibrator->observe('m', 1_000, 800);

    // ratio 0.8: factor 0.9 + 0.1 x (0.8 - 0.9); error of the corrected estimate |800 - 1000 x 0.9| / 800 = 0.125
    expect($second->samples)->toBe(2)
        ->and($second->factor)->toEqualWithDelta(0.89, 1e-12)
        ->and($second->error)->toEqualWithDelta(100 / 900 + 0.1 * (0.125 - 100 / 900), 1e-12);
});

it('clamps each observation so one odd request cannot skew the factor', function (int $actual, float $factor): void {
    expect(calibrator(maxCorrection: 0.5)->observe('m', 1_000, $actual)->factor)->toBe($factor);
})->with([
    'ten times over' => [10_000, 1.5],
    'a tenth' => [100, 0.5],
]);

it('limits how far a single outlier moves an established factor', function (): void {
    $calibrator = calibrator();

    foreach (range(1, 30) as $ignored) {
        $calibrator->observe('m', 1_000, 1_000);
    }

    $after = $calibrator->observe('m', 1_000, 50_000);

    expect($after->factor)->toEqualWithDelta(1.05, 1e-12);
});

it('only corrects once a model has the minimum samples', function (): void {
    $calibrator = calibrator(minSamples: 20);

    foreach (range(1, 19) as $ignored) {
        $calibrator->observe('m', 1_000, 800);
    }

    expect($calibrator->correctionFor('m'))->toBeNull();

    $calibrator->observe('m', 1_000, 800);

    expect($calibrator->correctionFor('m'))->not->toBeNull()
        ->and($calibrator->correctionFor('m')->samples)->toBe(20);
});

it('converges on a steady ratio and shrinks the margin to the floor', function (): void {
    $calibrator = calibrator(floor: 0.02);

    foreach (range(1, 200) as $ignored) {
        $calibrator->observe('m', 1_000, 800);
    }

    $correction = $calibrator->correctionFor('m');

    expect($correction->factor)->toEqualWithDelta(0.8, 1e-9)
        ->and($correction->error)->toBeLessThan(0.001)
        ->and($correction->margin)->toBe(0.02)
        ->and($correction->apply(1_000))->toBe(816);
});

it('keeps a wider margin while the measured error is high', function (): void {
    $calibrator = calibrator(minSamples: 2, floor: 0.02);

    $calibrator->observe('m', 1_000, 600);
    $calibrator->observe('m', 1_000, 1_400);

    $correction = $calibrator->correctionFor('m');

    expect($correction->margin)->toBe($correction->error)
        ->and($correction->margin)->toBeGreaterThan(0.3);
});

it('adapts when a provider changes its tokeniser', function (): void {
    $calibrator = calibrator();

    foreach (range(1, 100) as $ignored) {
        $calibrator->observe('m', 1_000, 800);
    }

    foreach (range(1, 50) as $ignored) {
        $calibrator->observe('m', 1_000, 1_000);
    }

    expect($calibrator->correctionFor('m')->factor)->toBeGreaterThan(0.99);
});

it('keeps each model separate', function (): void {
    $calibrator = calibrator(minSamples: 1);

    $calibrator->observe('claude-sonnet-5-5', 1_000, 800);
    $calibrator->observe('gemini-3.8-flash', 1_000, 1_200);

    expect($calibrator->correctionFor('claude-sonnet-5-5')->factor)->toBe(0.8)
        ->and($calibrator->correctionFor('gemini-3.8-flash')->factor)->toBe(1.2)
        ->and($calibrator->correctionFor('gpt-6.1-sol'))->toBeNull();
});

it('ignores observations without a positive estimate and actual', function (int $base, int $actual): void {
    expect(calibrator()->observe('m', $base, $actual))->toBeNull()
        ->and(app(CalibrationStore::class)->get('m'))->toBeNull();
})->with([[0, 100], [100, 0], [-1, 100]]);

it('does nothing when disabled', function (): void {
    $calibrator = calibrator(enabled: false, minSamples: 1);

    expect($calibrator->observe('m', 1_000, 800))->toBeNull()
        ->and($calibrator->correctionFor('m'))->toBeNull();
});

it('stops correcting when disabled, even with stored calibration', function (): void {
    calibrator(minSamples: 1)->observe('m', 1_000, 800);

    expect(calibrator(enabled: false, minSamples: 1)->correctionFor('m'))->toBeNull();
});

it('is configured from the package config', function (): void {
    config(['ai-headroom.calibration.min_samples' => 1]);

    $calibrator = app(Calibrator::class);
    $calibrator->observe('m', 1_000, 900);

    expect($calibrator->correctionFor('m')->factor)->toBe(0.9)
        ->and($calibrator->store())->toBe(app(CalibrationStore::class));
});

it('rejects invalid settings', function (Closure $make, string $message): void {
    expect($make)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'min samples' => [fn () => calibrator(minSamples: 0), 'min_samples must be at least 1'],
    'max correction' => [fn () => calibrator(maxCorrection: 1.0), 'max_correction must be greater than 0 and less than 1'],
    'smoothing' => [fn () => calibrator(smoothing: 0.0), 'smoothing must be greater than 0 and at most 1'],
    'floor' => [fn () => calibrator(floor: -0.01), 'safety_margin_floor cannot be negative'],
]);
