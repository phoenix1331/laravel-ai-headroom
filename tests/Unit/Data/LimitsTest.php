<?php

declare(strict_types=1);

use Phoenix1331\AiHeadroom\Data\Limits;

it('allows no limits, or sensible ones', function (): void {
    expect(new Limits)->maxPercentOfContext->toBeNull()
        ->and(new Limits(100, 0.01))->maxCost->toBe(0.01);
});

it('rejects a percent of context outside 0 to 100', function (float $percent): void {
    new Limits(maxPercentOfContext: $percent);
})->with([0.0, -5.0, 100.1])->throws(InvalidArgumentException::class, 'maxPercentOfContext must be greater than 0 and at most 100');

it('rejects a cost limit of zero or less', function (float $cost): void {
    new Limits(maxCost: $cost);
})->with([0.0, -1.0])->throws(InvalidArgumentException::class, 'maxCost must be greater than zero');
