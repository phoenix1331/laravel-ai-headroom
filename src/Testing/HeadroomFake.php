<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Testing;

use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\PendingStep;
use Phoenix1331\AiHeadroom\Counting\Contracts\Counter;
use Phoenix1331\AiHeadroom\Counting\EstimatingCounter;
use Phoenix1331\AiHeadroom\Data\Limits;
use Phoenix1331\AiHeadroom\Data\Tally;
use Phoenix1331\AiHeadroom\Data\TokenCount;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use PHPUnit\Framework\Assert;

/**
 * Replaces Headroom's counter in tests. Everything else (Headroom::for(), the
 * middleware, limits, events, costs) runs for real against the faked totals.
 *
 * Patterns match the agent class or the model name, with * wildcards. Exact
 * matches win, then patterns in the order given. A value is a total (counted
 * as prompt) or a breakdown. Requests nothing matches are estimated locally,
 * so a faked suite never calls a provider.
 */
class HeadroomFake implements Counter
{
    /** @var array<string, array{instructions: int, history: int, tools: int, attachments: int, prompt: int}> */
    private array $patterns = [];

    /** @var list<array{agent: class-string|null, model: string, step: PendingStep, tally: Tally}> */
    private array $counted = [];

    /**
     * @param  array<string, int|array<string, int>>  $totals
     */
    public function __construct(private readonly EstimatingCounter $estimator, array $totals = [])
    {
        foreach ($totals as $pattern => $value) {
            $this->patterns[$pattern] = $this->breakdown($pattern, $value);
        }
    }

    public function count(PendingStep $step, ?Limits $limits = null): Tally
    {
        $agent = $step->options?->agent === null ? null : $step->options->agent::class;
        $breakdown = $this->match($agent, $step->model);

        $tally = $breakdown === null
            ? $this->estimator->count($step, $limits)
            : new Tally(array_sum($breakdown), $breakdown, CountMethod::Native, 0.0);

        $this->counted[] = ['agent' => $agent, 'model' => $step->model, 'step' => $step, 'tally' => $tally];

        return $tally;
    }

    /**
     * Assert a request was counted for the agent class or model.
     *
     * @param  (Closure(PendingStep, Tally): bool)|null  $callback
     */
    public function assertCounted(string $agentOrModel, ?Closure $callback = null): void
    {
        Assert::assertNotEmpty(
            $this->matching($agentOrModel, $callback),
            "No request was counted for [{$agentOrModel}]".($callback === null ? '.' : ' matching the callback.'),
        );
    }

    public function assertNotCounted(string $agentOrModel): void
    {
        Assert::assertEmpty($this->matching($agentOrModel), "A request was counted for [{$agentOrModel}] unexpectedly.");
    }

    public function assertCountedTimes(string $agentOrModel, int $times): void
    {
        $actual = count($this->matching($agentOrModel));

        Assert::assertSame($times, $actual, "Expected {$times} requests counted for [{$agentOrModel}], counted {$actual}.");
    }

    public function assertNothingCounted(): void
    {
        Assert::assertEmpty($this->counted, 'Requests were counted unexpectedly.');
    }

    /**
     * @return list<array{agent: class-string|null, model: string, step: PendingStep, tally: Tally}>
     */
    public function counted(): array
    {
        return $this->counted;
    }

    /**
     * @return array{instructions: int, history: int, tools: int, attachments: int, prompt: int}|null
     */
    private function match(?string $agent, string $model): ?array
    {
        foreach ([$agent, $model] as $subject) {
            if ($subject !== null && isset($this->patterns[$subject])) {
                return $this->patterns[$subject];
            }
        }

        foreach ($this->patterns as $pattern => $breakdown) {
            if (($agent !== null && Str::is($pattern, $agent)) || Str::is($pattern, $model)) {
                return $breakdown;
            }
        }

        return null;
    }

    /**
     * @param  (Closure(PendingStep, Tally): bool)|null  $callback
     * @return list<array{agent: class-string|null, model: string, step: PendingStep, tally: Tally}>
     */
    private function matching(string $agentOrModel, ?Closure $callback = null): array
    {
        return array_values(array_filter(
            $this->counted,
            fn (array $entry): bool => ($entry['agent'] === $agentOrModel || $entry['model'] === $agentOrModel)
                && ($callback === null || $callback($entry['step'], $entry['tally'])),
        ));
    }

    /**
     * @param  int|array<string, int>  $value
     * @return array{instructions: int, history: int, tools: int, attachments: int, prompt: int}
     */
    private function breakdown(string $pattern, int|array $value): array
    {
        $parts = is_int($value) ? ['prompt' => $value] : $value;

        $unknown = array_diff(array_keys($parts), TokenCount::PARTS);

        if ($unknown !== []) {
            throw new InvalidArgumentException("Headroom::fake() for [{$pattern}] has unknown parts: ".implode(', ', $unknown).'.');
        }

        foreach ($parts as $tokens) {
            if ($tokens < 0) {
                throw new InvalidArgumentException("Headroom::fake() for [{$pattern}] cannot have negative tokens.");
            }
        }

        return [
            'instructions' => $parts['instructions'] ?? 0,
            'history' => $parts['history'] ?? 0,
            'tools' => $parts['tools'] ?? 0,
            'attachments' => $parts['attachments'] ?? 0,
            'prompt' => $parts['prompt'] ?? 0,
        ];
    }
}
