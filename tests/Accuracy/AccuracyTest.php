<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Phoenix1331\AiHeadroom\Counting\AnthropicCounter;
use Phoenix1331\AiHeadroom\Counting\EstimatingCounter;
use Phoenix1331\AiHeadroom\Counting\GeminiCounter;
use Phoenix1331\AiHeadroom\Counting\OpenAiCounter;
use Phoenix1331\AiHeadroom\Counting\TiktokenTokeniser;
use Phoenix1331\AiHeadroom\Models\ModelRegistry;
use Phoenix1331\AiHeadroom\Tests\Accuracy\Support\AccuracyReport;
use Phoenix1331\AiHeadroom\Tests\Accuracy\Support\AccuracyRunner;
use Phoenix1331\AiHeadroom\Tests\Accuracy\Support\Corpus;

/*
 * Calls the real counting endpoints with your API keys and writes docs/accuracy.md.
 * Run with: ANTHROPIC_API_KEY=... GEMINI_API_KEY=... OPENAI_API_KEY=... composer test:accuracy
 * Providers without a key are skipped. Override the model with HEADROOM_ACCURACY_{PROVIDER}_MODEL.
 */

$providers = [
    'anthropic' => ['key' => 'ANTHROPIC_API_KEY', 'counter' => AnthropicCounter::class, 'model' => 'claude-sonnet-5-5'],
    'gemini' => ['key' => 'GEMINI_API_KEY', 'counter' => GeminiCounter::class, 'model' => 'gemini-3.8-flash'],
    'openai' => ['key' => 'OPENAI_API_KEY', 'counter' => OpenAiCounter::class, 'model' => 'gpt-6.1-sol'],
];

/** @var array<string, array{model: string, rows: list<array<string, mixed>>}> $results */
$results = [];

afterAll(function () use (&$results): void {
    if ($results === []) {
        return;
    }

    ksort($results);

    file_put_contents(
        dirname(__DIR__, 2).'/docs/accuracy.md',
        AccuracyReport::markdown($results, (string) InstalledVersions::getPrettyVersion('laravel/ai'), date('Y-m-d')),
    );
});

foreach ($providers as $name => $provider) {
    it("measures estimate accuracy against {$name}'s counting endpoint", function () use ($name, $provider, &$results): void {
        $model = getenv('HEADROOM_ACCURACY_'.strtoupper($name).'_MODEL') ?: $provider['model'];

        config(["ai.providers.{$name}" => ['driver' => $name, 'key' => getenv($provider['key'])]]);

        $estimator = new EstimatingCounter(
            tokeniser: new TiktokenTokeniser(dirname(__DIR__, 2).'/build/tiktoken'),
            models: app(ModelRegistry::class),
            ratios: config('ai-headroom.estimate_ratios'),
            attachmentTokens: config('ai-headroom.attachment_tokens'),
            safetyMargin: config('ai-headroom.estimate_safety_margin'),
        );

        $rows = (new AccuracyRunner($estimator, app('cache')))->run(app($provider['counter']), Corpus::steps($name, $model));

        $results[$name] = ['model' => $model, 'rows' => $rows];

        foreach ($rows as $row) {
            expect($row['native'])->toBeGreaterThan(0, "{$name} returned no tokens for the {$row['sample']} sample.");
        }
    })->skip(fn (): bool => ! getenv($provider['key']), "{$provider['key']} is not set");
}
