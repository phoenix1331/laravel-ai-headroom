<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Tracking;

use Phoenix1331\AiHeadroom\Data\TokenCount;

/**
 * Remembers the count for each in-flight agent step, keyed by the SDK's
 * invocation ID and step number, so a later StepFailed can be reported against
 * the right request ID. Entries leave on completion or failure; the oldest are
 * dropped beyond a cap, since an abandoned stream never does either.
 */
class RequestTracker
{
    private const int CAPACITY = 1_000;

    /** @var array<string, TokenCount> */
    private array $inFlight = [];

    public function track(string $invocationId, int $stepNumber, TokenCount $count): void
    {
        $this->inFlight[$this->key($invocationId, $stepNumber)] = $count;

        if (count($this->inFlight) > self::CAPACITY) {
            array_shift($this->inFlight);
        }
    }

    public function pull(string $invocationId, int $stepNumber): ?TokenCount
    {
        $key = $this->key($invocationId, $stepNumber);
        $count = $this->inFlight[$key] ?? null;

        unset($this->inFlight[$key]);

        return $count;
    }

    public function count(): int
    {
        return count($this->inFlight);
    }

    private function key(string $invocationId, int $stepNumber): string
    {
        return $invocationId.'#'.$stepNumber;
    }
}
