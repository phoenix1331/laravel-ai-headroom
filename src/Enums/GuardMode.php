<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Enums;

use InvalidArgumentException;

/**
 * What EnsureHeadroom does when a request breaches a limit.
 */
enum GuardMode: string
{
    /** Fire RequestBlocked, then throw TokenLimitExceeded before the request is sent. */
    case Throw = 'throw';

    /** Fire HeadroomLow for each breach and let the request through. */
    case Report = 'report';

    public static function fromConfig(string $value): self
    {
        return self::tryFrom(strtolower(trim($value)))
            ?? throw new InvalidArgumentException("Headroom does not recognise the middleware mode [{$value}]. Use throw or report.");
    }
}
