<?php

declare(strict_types=1);

use Phoenix1331\AiHeadroom\Data\Breach;
use Phoenix1331\AiHeadroom\Enums\LimitType;

it('describes each breach from its figures', function (Breach $breach, string $reason): void {
    expect($breach->reason())->toBe($reason);
})->with([
    'context window' => [new Breach(LimitType::ContextWindow, 1_000_000, 1_050_000), '1,050,000 tokens exceed the 1,000,000 token context window'],
    'percent of context' => [new Breach(LimitType::PercentOfContext, 80, 85.2), '85.2% of context used, limit 80%'],
    'cost' => [new Breach(LimitType::Cost, 0.2, 0.3125, 'GBP'), 'estimated cost 0.3125 exceeds the limit of 0.2 GBP'],
]);
