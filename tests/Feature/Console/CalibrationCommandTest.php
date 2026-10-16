<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Phoenix1331\AiHeadroom\Calibration\CalibrationStore;
use Phoenix1331\AiHeadroom\Data\Calibration;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00'));
    config(['ai-headroom.calibration.min_samples' => 20, 'ai-headroom.safety_margin_floor' => 0.02]);

    $store = app(CalibrationStore::class);
    $store->save(new Calibration('claude-sonnet-5-5', 120, 0.8125, 0.031, CarbonImmutable::now()->subMinutes(5)));
    $store->save(new Calibration('gemini-3.8-flash', 7, 1.04, 0.012, CarbonImmutable::now()->subHours(2)));
});

it('shows samples, factor, error, margin and status per model', function (): void {
    $this->artisan('headroom:calibration')
        ->expectsTable(
            ['Model', 'Samples', 'Factor', 'Error', 'Margin', 'Status', 'Updated'],
            [
                ['claude-sonnet-5-5', 120, '0.813', '3.1%', '3.1%', 'active', '5 minutes ago'],
                ['gemini-3.8-flash', 7, '1.040', '1.2%', '2.0%', 'learning 7/20', '2 hours ago'],
            ],
        )
        ->assertSuccessful();
});

it('shows a single model', function (): void {
    $this->artisan('headroom:calibration', ['--model' => 'gemini-3.8-flash'])
        ->expectsTable(
            ['Model', 'Samples', 'Factor', 'Error', 'Margin', 'Status', 'Updated'],
            [['gemini-3.8-flash', 7, '1.040', '1.2%', '2.0%', 'learning 7/20', '2 hours ago']],
        )
        ->assertSuccessful();
});

it('explains when there is no data', function (): void {
    app(CalibrationStore::class)->reset();

    $this->artisan('headroom:calibration')
        ->expectsOutputToContain('No calibration data yet')
        ->assertSuccessful();

    $this->artisan('headroom:calibration', ['--model' => 'gpt-6.1-sol'])
        ->expectsOutputToContain('No calibration data for [gpt-6.1-sol] yet.')
        ->assertSuccessful();
});

it('warns when calibration is disabled', function (): void {
    config(['ai-headroom.calibration.enabled' => false]);

    $this->artisan('headroom:calibration')
        ->expectsOutputToContain('Calibration is disabled')
        ->assertSuccessful();
});

it('resets all models after confirmation', function (): void {
    $this->artisan('headroom:calibration', ['--reset' => true])
        ->expectsConfirmation('Clear calibration for all models? Estimates will learn again from scratch.', 'yes')
        ->expectsOutputToContain('Calibration cleared for all models.')
        ->assertSuccessful();

    expect(app(CalibrationStore::class)->models())->toBe([]);
});

it('keeps everything when the reset is not confirmed', function (): void {
    $this->artisan('headroom:calibration', ['--reset' => true])
        ->expectsConfirmation('Clear calibration for all models? Estimates will learn again from scratch.', 'no')
        ->expectsOutputToContain('Nothing was reset.')
        ->assertSuccessful();

    expect(app(CalibrationStore::class)->models())->toHaveCount(2);
});

it('resets one model with --force and no question', function (): void {
    $this->artisan('headroom:calibration', ['--reset' => true, '--model' => 'gemini-3.8-flash', '--force' => true])
        ->expectsOutputToContain('Calibration cleared for [gemini-3.8-flash].')
        ->assertSuccessful();

    expect(app(CalibrationStore::class)->models())->toBe(['claude-sonnet-5-5']);
});
