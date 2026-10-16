<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Enums;

use InvalidArgumentException;

/**
 * Currencies Headroom can report costs in. Model prices are stored in USD,
 * the currency providers bill in, and converted using the configured rates.
 */
enum Currency: string
{
    case Usd = 'USD';
    case Gbp = 'GBP';

    public static function fromCode(string $code): self
    {
        $currency = self::tryFrom(strtoupper(trim($code)));

        if ($currency === null) {
            $supported = implode(', ', array_map(fn (self $case): string => $case->value, self::cases()));

            throw new InvalidArgumentException("Headroom does not support the currency [{$code}]. Supported: {$supported}.");
        }

        return $currency;
    }

    public function symbol(): string
    {
        return match ($this) {
            self::Usd => '$',
            self::Gbp => '£',
        };
    }
}
