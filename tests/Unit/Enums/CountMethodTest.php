<?php

declare(strict_types=1);

use Phoenix1331\AiHeadroom\Enums\CountMethod;

it('treats only native counts as exact', function (CountMethod $method, bool $exact): void {
    expect($method->isExact())->toBe($exact);
})->with([
    'native' => [CountMethod::Native, true],
    'estimated' => [CountMethod::Estimated, false],
    'calibrated' => [CountMethod::Calibrated, false],
]);

it('has stable string values for serialisation', function (): void {
    expect(array_map(fn (CountMethod $method): string => $method->value, CountMethod::cases()))
        ->toBe(['native', 'estimated', 'calibrated']);
});
