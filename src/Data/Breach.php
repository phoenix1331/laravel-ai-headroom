<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Data;

use Phoenix1331\AiHeadroom\Enums\LimitType;

/**
 * One limit a counted request went over.
 */
final readonly class Breach
{
    public function __construct(
        public LimitType $limit,
        public float $limitValue,
        public float $actualValue,
        public string $currency = '',
    ) {}

    /**
     * A description built from the figures only.
     */
    public function reason(): string
    {
        return match ($this->limit) {
            LimitType::ContextWindow => sprintf('%s tokens exceed the %s token context window', number_format($this->actualValue), number_format($this->limitValue)),
            LimitType::PercentOfContext => sprintf('%s%% of context used, limit %s%%', self::figure($this->actualValue), self::figure($this->limitValue)),
            LimitType::Cost => trim(sprintf('estimated cost %s exceeds the limit of %s %s', self::figure($this->actualValue), self::figure($this->limitValue), $this->currency)),
        };
    }

    private static function figure(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
