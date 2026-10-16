<?php

declare(strict_types=1);

use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;
use Phoenix1331\AiHeadroom\Counting\Contracts\Counter;
use Phoenix1331\AiHeadroom\Counting\EscalatingCounter;
use Phoenix1331\AiHeadroom\Counting\EstimatingCounter;
use Phoenix1331\AiHeadroom\Currency\ConfiguredExchangeRates;
use Phoenix1331\AiHeadroom\Data\Limits;
use Phoenix1331\AiHeadroom\Data\Tally;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroom\Enums\Currency;
use Phoenix1331\AiHeadroom\Models\ModelRegistry;
use Phoenix1331\AiHeadroom\Tests\Fixtures\WordTokeniser;

/**
 * A native counter that records whether it was asked.
 */
class RecordingNativeCounter implements Counter
{
    public int $calls = 0;

    public function count(PendingStep $step, ?Limits $limits = null): Tally
    {
        $this->calls++;

        return new Tally(999, ['instructions' => 0, 'history' => 0, 'tools' => 0, 'attachments' => 0, 'prompt' => 999], CountMethod::Native, 0.0);
    }
}

/**
 * Window 1,000 tokens; $1 per 1,000 input tokens and free output, so cost = total / 1,000.
 */
function escalationModels(): ModelRegistry
{
    return new ModelRegistry(
        defaults: ['gpt-test' => ['provider' => 'openai', 'context_window' => 1_000, 'max_output_tokens' => 100, 'input_price' => 1_000.0, 'output_price' => 0.0]],
        overrides: [],
        fallback: ['context_window' => 1_000, 'max_output_tokens' => 100, 'input_price' => 1, 'output_price' => 1],
        currency: Currency::Usd,
        exchangeRates: new ConfiguredExchangeRates([]),
    );
}

function escalating(RecordingNativeCounter $native, float $band = 0.15): EscalatingCounter
{
    $models = escalationModels();

    return new EscalatingCounter(
        estimator: new EstimatingCounter(new WordTokeniser, $models, ['openai' => 1.0], [], 0.10),
        native: $native,
        models: $models,
        band: $band,
    );
}

/**
 * A step whose estimate is exactly $total tokens: one user message of ($total - 6) words plus 6 tokens of overhead.
 */
function stepEstimatedAt(int $total): PendingStep
{
    return new PendingStep(0, true, 'openai', 'gpt-test', null, [new UserMessage(str_repeat('word ', $total - 6))], [], null, null);
}

it('estimates the step as expected for these tests', function (): void {
    expect(escalating(new RecordingNativeCounter)->count(stepEstimatedAt(400))->total)->toBe(400);
});

it('never calls the provider for clear passes or clear fails against the context window', function (int $estimate): void {
    $native = new RecordingNativeCounter;

    $tally = escalating($native)->count(stepEstimatedAt($estimate));

    expect($native->calls)->toBe(0)
        ->and($tally->method)->toBe(CountMethod::Estimated)
        ->and($tally->total)->toBe($estimate);
})->with([
    'well inside' => [100],
    'just below the band' => [849],
    'just above the band' => [1_151],
    'far over' => [5_000],
]);

it('fetches a native count when the estimate is within the band of the context window', function (int $estimate): void {
    $native = new RecordingNativeCounter;

    $tally = escalating($native)->count(stepEstimatedAt($estimate));

    expect($native->calls)->toBe(1)
        ->and($tally->method)->toBe(CountMethod::Native)
        ->and($tally->total)->toBe(999);
})->with([
    'bottom of the band' => [850],
    'just under the window' => [990],
    'exactly the window' => [1_000],
    'just over the window' => [1_050],
    'top of the band' => [1_150],
]);

it('escalates around the percent of context limit', function (int $estimate, int $calls): void {
    $native = new RecordingNativeCounter;

    escalating($native)->count(stepEstimatedAt($estimate), new Limits(maxPercentOfContext: 50));

    expect($native->calls)->toBe($calls);
})->with([
    'clear pass' => [300, 0],
    'borderline below' => [480, 1],
    'borderline above' => [560, 1],
    'clear fail' => [700, 0],
]);

it('escalates around the cost limit', function (int $estimate, int $calls): void {
    $native = new RecordingNativeCounter;

    escalating($native)->count(stepEstimatedAt($estimate), new Limits(maxCost: 0.20));

    expect($native->calls)->toBe($calls);
})->with([
    'clear pass' => [100, 0],
    'borderline' => [190, 1],
    'clear fail' => [400, 0],
]);

it('does not pay for a native count when another limit is already clearly exceeded', function (): void {
    $native = new RecordingNativeCounter;

    escalating($native)->count(stepEstimatedAt(500), new Limits(maxPercentOfContext: 50, maxCost: 0.10));

    expect($native->calls)->toBe(0);
});

it('only escalates at the exact limit when the band is zero', function (int $estimate, int $calls): void {
    $native = new RecordingNativeCounter;

    escalating($native, band: 0.0)->count(stepEstimatedAt($estimate));

    expect($native->calls)->toBe($calls);
})->with([
    'below' => [999, 0],
    'at' => [1_000, 1],
    'above' => [1_001, 0],
]);

it('rejects a band outside 0 to 1', function (float $band): void {
    escalating(new RecordingNativeCounter, band: $band);
})->with([-0.1, 1.0])->throws(InvalidArgumentException::class, 'escalation band must be at least 0 and less than 1');
