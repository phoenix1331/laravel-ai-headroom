<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Currency\Contracts;

use Phoenix1331\AiHeadroom\Enums\Currency;

interface ExchangeRates
{
    /**
     * How many units of $to one unit of $from is worth.
     */
    public function rate(Currency $from, Currency $to): float;
}
