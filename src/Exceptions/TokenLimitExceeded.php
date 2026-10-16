<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Exceptions;

use Phoenix1331\AiHeadroom\Data\TokenCount;
use Phoenix1331\AiHeadroom\Enums\LimitType;
use RuntimeException;

/**
 * A request breached a limit. Carries the full TokenCount so the app can trim
 * history, summarise or refuse. The message holds figures only.
 */
class TokenLimitExceeded extends RuntimeException
{
    public function __construct(
        public readonly TokenCount $count,
        public readonly LimitType $limit,
        public readonly float $limitValue,
        public readonly float $actualValue,
    ) {
        parent::__construct(sprintf(
            'Request %s exceeds %s: %s against a limit of %s (model %s).',
            $count->requestId(),
            $limit->value,
            self::format($actualValue),
            self::format($limitValue),
            $count->model(),
        ));
    }

    private static function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
