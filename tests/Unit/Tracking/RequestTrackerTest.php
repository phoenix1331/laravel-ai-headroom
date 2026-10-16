<?php

declare(strict_types=1);

use Phoenix1331\AiHeadroom\Data\Cost;
use Phoenix1331\AiHeadroom\Data\TokenCount;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroom\Enums\Currency;
use Phoenix1331\AiHeadroom\Tracking\RequestTracker;

function trackedCount(): TokenCount
{
    return new TokenCount(10, [], CountMethod::Estimated, 0.1, 'm', 100, new Cost(0, 0, Currency::Usd));
}

it('returns a tracked count once, by invocation and step', function (): void {
    $tracker = new RequestTracker;
    $count = trackedCount();

    $tracker->track('inv-1', 0, $count);

    expect($tracker->pull('inv-1', 1))->toBeNull()
        ->and($tracker->pull('inv-1', 0))->toBe($count)
        ->and($tracker->pull('inv-1', 0))->toBeNull();
});

it('drops the oldest entries beyond its capacity', function (): void {
    $tracker = new RequestTracker;

    foreach (range(0, 1_000) as $step) {
        $tracker->track('inv-1', $step, trackedCount());
    }

    expect($tracker->count())->toBe(1_000)
        ->and($tracker->pull('inv-1', 0))->toBeNull()
        ->and($tracker->pull('inv-1', 1_000))->not->toBeNull();
});
