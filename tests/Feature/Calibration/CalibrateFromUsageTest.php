<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\TextResponse;
use Phoenix1331\AiHeadroom\Calibration\CalibrationStore;
use Phoenix1331\AiHeadroom\Calibration\Calibrator;
use Phoenix1331\AiHeadroom\Counting\Contracts\Tokeniser;
use Phoenix1331\AiHeadroom\Counting\CounterResolver;
use Phoenix1331\AiHeadroom\Counting\EstimatingCounter;
use Phoenix1331\AiHeadroom\Data\Cost;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroom\Enums\Currency;
use Phoenix1331\AiHeadroom\Events\TokensCounted;
use Phoenix1331\AiHeadroom\Events\TokensUsed;
use Phoenix1331\AiHeadroom\Facades\Headroom;
use Phoenix1331\AiHeadroom\HeadroomManager;
use Phoenix1331\AiHeadroom\Tests\Fixtures\Agents\GuardedAgent;
use Phoenix1331\AiHeadroom\Tests\Fixtures\Agents\InstructionsOnlyAgent;
use Phoenix1331\AiHeadroom\Tests\Fixtures\WordTokeniser;

beforeEach(function (): void {
    app()->instance(Tokeniser::class, new WordTokeniser);
    config([
        'ai-headroom.method' => 'estimate',
        'ai-headroom.calibration.min_samples' => 1,
        'ai.default' => 'anthropic',
        'ai.providers.anthropic' => ['driver' => 'anthropic', 'key' => 'sk-ant-test'],
    ]);
    GuardedAgent::$guard = null;
    GuardedAgent::$providers = 'anthropic';
});

function used(CountMethod $method, int $estimated, int $actual, string $model = 'claude-sonnet-5-5', string $countedModel = 'claude-sonnet-5-5'): TokensUsed
{
    return new TokensUsed('req', 'inv', null, 'anthropic', $model, $countedModel, $actual, 0, null, null, new Cost(0, 0, Currency::Usd), false, $method, $estimated, [], CarbonImmutable::now());
}

it('learns from estimated counts', function (): void {
    event(used(CountMethod::Estimated, estimated: 1_000, actual: 800));

    expect(app(CalibrationStore::class)->get('claude-sonnet-5-5')->factor)->toBe(0.8);
});

it('never learns from native counts', function (): void {
    event(used(CountMethod::Native, estimated: 1_000, actual: 800));

    expect(app(CalibrationStore::class)->get('claude-sonnet-5-5'))->toBeNull();
});

it('skips usage from a different model after failover', function (): void {
    event(used(CountMethod::Estimated, 1_000, 800, model: 'gpt-6.1-sol'));

    expect(app(CalibrationStore::class)->models())->toBe([]);
});

it('learns from a dated snapshot of the counted model, under the counted model', function (): void {
    event(used(CountMethod::Estimated, 1_000, 800, model: 'claude-sonnet-5-5-20261001'));

    expect(app(CalibrationStore::class)->models())->toBe(['claude-sonnet-5-5']);
});

it('divides a calibrated estimate back to its base before learning', function (): void {
    event(used(CountMethod::Estimated, 1_000, 800));

    // factor 0.8, error 0.25 -> margin 0.25: a base of 1,000 was reported as 1,000 x 0.8 x 1.25 = 1,000
    event(used(CountMethod::Calibrated, estimated: 1_000, actual: 800));

    $calibration = app(CalibrationStore::class)->get('claude-sonnet-5-5');

    expect($calibration->samples)->toBe(2)
        ->and($calibration->factor)->toEqualWithDelta(0.8, 1e-12);
});

it('treats a calibrated estimate as the base once calibration has been reset', function (): void {
    event(used(CountMethod::Calibrated, estimated: 1_000, actual: 800));

    expect(app(CalibrationStore::class)->get('claude-sonnet-5-5')->factor)->toBe(0.8);
});

it('marks counts calibrated once a model has enough real usage, with the measured confidence', function (): void {
    config(['ai-headroom.calibration.min_samples' => 3]);

    $estimate = Headroom::for(new GuardedAgent, 'Where is my refund?')->total();
    $actual = (int) round($estimate * 0.8);

    GuardedAgent::fake(array_fill(0, 4, new TextResponse('ok', new TextUsage($actual, 10), new Meta('anthropic', 'claude-sonnet-5-5'))));

    $counted = [];
    Event::listen(TokensCounted::class, function (TokensCounted $event) use (&$counted): void {
        $counted[] = $event->countMethod;
    });

    foreach (range(1, 4) as $ignored) {
        (new GuardedAgent)->prompt('Where is my refund?');
    }

    $count = Headroom::for(new GuardedAgent, 'Where is my refund?');
    $correction = app(Calibrator::class)->correctionFor('claude-sonnet-5-5');

    // Three estimated middleware steps, then the fourth step and the Headroom::for() call are both calibrated
    expect($counted)->toBe([CountMethod::Estimated, CountMethod::Estimated, CountMethod::Estimated, CountMethod::Calibrated, CountMethod::Calibrated])
        ->and($count->method())->toBe(CountMethod::Calibrated)
        ->and($count->confidence())->toBe($correction->error)
        ->and($count->total())->toBe($correction->apply($estimate))
        ->and($count->total())->toBeLessThan($estimate);
});

it('leaves native counts untouched by calibration', function (): void {
    event(used(CountMethod::Estimated, 1_000, 500));
    config(['ai-headroom.method' => 'native']);
    Http::fake(['api.anthropic.com/*' => Http::response(responseFixture('anthropic/count_tokens.json'))]);

    $count = Headroom::for(new InstructionsOnlyAgent, 'Hello');

    expect($count->method())->toBe(CountMethod::Native)
        ->and($count->total())->toBe(2095);
});

it('stops correcting when calibration is disabled', function (): void {
    event(used(CountMethod::Estimated, 1_000, 500));
    config(['ai-headroom.calibration.enabled' => false]);

    // Services are singletons built from config, so rebuild them as a fresh request would.
    foreach ([Calibrator::class, EstimatingCounter::class, CounterResolver::class, HeadroomManager::class] as $service) {
        app()->forgetInstance($service);
    }
    Headroom::clearResolvedInstances();

    expect(Headroom::for(new InstructionsOnlyAgent, 'Hello')->method())->toBe(CountMethod::Estimated);
});
