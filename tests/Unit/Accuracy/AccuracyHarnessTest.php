<?php

declare(strict_types=1);

use Laravel\Ai\PendingStep;
use Phoenix1331\AiHeadroom\Counting\Contracts\Counter;
use Phoenix1331\AiHeadroom\Counting\EstimatingCounter;
use Phoenix1331\AiHeadroom\Data\Limits;
use Phoenix1331\AiHeadroom\Data\Tally;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroom\Models\ModelRegistry;
use Phoenix1331\AiHeadroom\Tests\Accuracy\Support\AccuracyReport;
use Phoenix1331\AiHeadroom\Tests\Accuracy\Support\AccuracyRunner;
use Phoenix1331\AiHeadroom\Tests\Accuracy\Support\Corpus;
use Phoenix1331\AiHeadroom\Tests\Fixtures\WordTokeniser;

/**
 * A native counter that reports a fixed fraction of the local estimate, so the
 * harness can be checked without API keys.
 */
function proportionalNative(EstimatingCounter $estimator, float $fraction): Counter
{
    return new class($estimator, $fraction) implements Counter
    {
        public function __construct(private readonly EstimatingCounter $estimator, private readonly float $fraction) {}

        public function count(PendingStep $step, ?Limits $limits = null): Tally
        {
            $estimate = $this->estimator->count($step)->total;

            return new Tally((int) round($estimate * $this->fraction), ['instructions' => 0, 'history' => 0, 'tools' => 0, 'attachments' => 0, 'prompt' => 0], CountMethod::Native, 0.0);
        }
    };
}

function harnessEstimator(): EstimatingCounter
{
    return new EstimatingCounter(new WordTokeniser, app(ModelRegistry::class), ['openai' => 1.0], [], 0.10);
}

it('builds every corpus sample, including the tool-heavy agent', function (): void {
    $steps = Corpus::steps('openai', 'gpt-6.1-sol');

    expect(array_keys($steps))->toBe(['prose', 'code', 'json', 'french', 'japanese', 'tool-heavy'])
        ->and($steps['tool-heavy']->tools)->toHaveCount(6)
        ->and($steps['tool-heavy']->tools[0]->name())->toBe('lookup_order')
        ->and($steps['code']->messages[0]->content)->toContain('final class RefundController');
});

it('measures the error of each estimate against the native count', function (): void {
    $estimator = harnessEstimator();

    $rows = (new AccuracyRunner($estimator, app('cache')))->run(proportionalNative($estimator, 0.8), Corpus::steps('openai', 'gpt-6.1-sol'));

    expect(array_column($rows, 'sample'))->toBe(['prose', 'code', 'json', 'french', 'japanese', 'tool-heavy']);

    // An estimate of 1.25x native is a +25% error; tiny samples (Japanese has no spaces for the word tokeniser) round further.
    foreach ($rows as $row) {
        expect($row['error'])->toBeGreaterThanOrEqual(0.24)->toBeLessThanOrEqual(0.3);
    }
});

it('corrects each sample using only the other samples, closing a steady gap', function (): void {
    $estimator = harnessEstimator();

    $rows = (new AccuracyRunner($estimator, app('cache')))->run(proportionalNative($estimator, 0.8), Corpus::steps('openai', 'gpt-6.1-sol'));

    $mean = fn (string $column): float => array_sum(array_map(abs(...), array_column($rows, $column))) / count($rows);

    expect($mean('calibrated_error'))->toBeLessThan($mean('error') / 2);

    foreach ($rows as $row) {
        expect(abs($row['calibrated_error']))->toBeLessThanOrEqual(abs($row['error']));
    }
});

it('renders a summary and per-provider tables', function (): void {
    $markdown = AccuracyReport::markdown([
        'anthropic' => ['model' => 'claude-sonnet-5-5', 'rows' => [
            ['sample' => 'prose', 'native' => 1_000, 'estimate' => 1_100, 'error' => 0.1, 'calibrated' => 1_010, 'calibrated_error' => 0.01],
            ['sample' => 'japanese', 'native' => 400, 'estimate' => 300, 'error' => -0.25, 'calibrated' => 380, 'calibrated_error' => -0.05],
        ]],
    ], 'v1.2.3', '2026-10-16');

    expect($markdown)
        ->toContain('on 2026-10-16 against laravel/ai v1.2.3')
        ->toContain('| anthropic | `claude-sonnet-5-5` | 17.5% | 3.0% | japanese (-25.0%) |')
        ->toContain('## anthropic (`claude-sonnet-5-5`)')
        ->toContain('| prose | 1,000 | 1,100 | +10.0% | 1,010 | +1.0% |')
        ->not->toContain('—');
});
