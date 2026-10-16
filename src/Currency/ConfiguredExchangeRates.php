<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Currency;

use InvalidArgumentException;
use Phoenix1331\AiHeadroom\Currency\Contracts\ExchangeRates;
use Phoenix1331\AiHeadroom\Enums\Currency;

/**
 * Fixed exchange rates from config, each expressed as units per 1 USD.
 */
class ConfiguredExchangeRates implements ExchangeRates
{
    /** @var array<string, float> */
    private array $perUsd = [];

    /**
     * @param  array<array-key, mixed>  $perUsd  Currency code => units per 1 USD, e.g. ['GBP' => 0.756].
     */
    public function __construct(array $perUsd)
    {
        foreach ($perUsd as $code => $rate) {
            if (! is_numeric($rate) || (float) $rate <= 0) {
                throw new InvalidArgumentException("The Headroom exchange rate for [{$code}] must be a number greater than zero.");
            }

            $this->perUsd[Currency::fromCode((string) $code)->value] = (float) $rate;
        }

        $this->perUsd[Currency::Usd->value] = 1.0;
    }

    public function rate(Currency $from, Currency $to): float
    {
        return $this->perUsd($to) / $this->perUsd($from);
    }

    private function perUsd(Currency $currency): float
    {
        return $this->perUsd[$currency->value]
            ?? throw new InvalidArgumentException("No exchange rate is configured for [{$currency->value}]. Set ai-headroom.exchange_rates.{$currency->value}.");
    }
}
