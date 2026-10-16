<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Data;

use InvalidArgumentException;
use Phoenix1331\AiHeadroom\Enums\Currency;

/**
 * A model's context window, output limit and prices per million tokens in USD.
 */
final readonly class ModelSpec
{
    /**
     * @param  list<array{above: int, input_price: float, output_price: float, cache_read_price?: float|null, cache_write_price?: float|null}>  $tiers  Prices that replace the base prices for the whole request once the prompt is above `above` tokens.
     * @param  bool  $isFallback  True when the model is unknown and these are the cautious fallback values.
     * @param  float|null  $cacheReadPrice  Price per million input tokens read from a prompt cache; null when unknown.
     * @param  float|null  $cacheWritePrice  Price per million input tokens written to a prompt cache; null when unknown.
     */
    public function __construct(
        public string $name,
        public ?string $provider,
        public int $contextWindow,
        public int $maxOutputTokens,
        public float $inputPrice,
        public float $outputPrice,
        public array $tiers = [],
        public bool $isFallback = false,
        public ?float $cacheReadPrice = null,
        public ?float $cacheWritePrice = null,
    ) {
        if ($contextWindow <= 0 || $maxOutputTokens <= 0) {
            throw new InvalidArgumentException("Model [{$name}] needs a positive context_window and max_output_tokens.");
        }

        if ($inputPrice < 0 || $outputPrice < 0 || $cacheReadPrice < 0 || $cacheWritePrice < 0) {
            throw new InvalidArgumentException("Model [{$name}] prices cannot be negative.");
        }

        foreach ($tiers as $tier) {
            if ($tier['above'] < 0 || $tier['input_price'] < 0 || $tier['output_price'] < 0 || ($tier['cache_read_price'] ?? 0) < 0 || ($tier['cache_write_price'] ?? 0) < 0) {
                throw new InvalidArgumentException("Model [{$name}] has a tier with a negative value.");
            }
        }
    }

    /**
     * The prices that apply to a prompt of the given size. A tier's cache prices are
     * null unless the tier sets them, since base cache prices do not carry over.
     *
     * @return array{input_price: float, output_price: float, cache_read_price: float|null, cache_write_price: float|null}
     */
    public function pricesFor(int $inputTokens): array
    {
        $prices = [
            'input_price' => $this->inputPrice,
            'output_price' => $this->outputPrice,
            'cache_read_price' => $this->cacheReadPrice,
            'cache_write_price' => $this->cacheWritePrice,
        ];

        $threshold = -1;

        foreach ($this->tiers as $tier) {
            if ($inputTokens > $tier['above'] && $tier['above'] > $threshold) {
                $threshold = $tier['above'];
                $prices = [
                    'input_price' => $tier['input_price'],
                    'output_price' => $tier['output_price'],
                    'cache_read_price' => $tier['cache_read_price'] ?? null,
                    'cache_write_price' => $tier['cache_write_price'] ?? null,
                ];
            }
        }

        return $prices;
    }

    /**
     * What a completed request actually cost in USD. Input tokens include any cache
     * reads and writes, which are priced separately where the registry knows the
     * price. When cache tokens are reported without a matching price they are
     * priced as normal input and the cost is flagged approximate.
     */
    public function actualCostFor(int $inputTokens, int $outputTokens, ?int $cacheReadTokens = null, ?int $cacheWriteTokens = null): Cost
    {
        $prices = $this->pricesFor($inputTokens);
        $cacheRead = max(0, $cacheReadTokens ?? 0);
        $cacheWrite = max(0, $cacheWriteTokens ?? 0);
        $approximate = false;

        $input = 0.0;
        $uncached = $inputTokens;

        if ($cacheRead > 0 && $prices['cache_read_price'] !== null) {
            $input += $cacheRead * $prices['cache_read_price'];
            $uncached -= $cacheRead;
        }

        if ($cacheRead > 0 && $prices['cache_read_price'] === null) {
            $approximate = true;
        }

        if ($cacheWrite > 0 && $prices['cache_write_price'] !== null) {
            $input += $cacheWrite * $prices['cache_write_price'];
            $uncached -= $cacheWrite;
        }

        if ($cacheWrite > 0 && $prices['cache_write_price'] === null) {
            $approximate = true;
        }

        $input += max(0, $uncached) * $prices['input_price'];

        return new Cost(
            input: $input / 1_000_000,
            output: max(0, $outputTokens) * $prices['output_price'] / 1_000_000,
            currency: Currency::Usd,
            approximate: $approximate,
        );
    }

    /**
     * The cost of a prompt in USD, with output priced as if every allowed output token were used.
     *
     * @param  int|null  $maxOutputTokens  The request's maxTokens; null uses the model's maximum.
     */
    public function costFor(int $inputTokens, ?int $maxOutputTokens): Cost
    {
        $prices = $this->pricesFor($inputTokens);

        return Cost::forTokens(
            inputTokens: $inputTokens,
            maxOutputTokens: min($maxOutputTokens ?? $this->maxOutputTokens, $this->maxOutputTokens),
            inputPricePerMillion: $prices['input_price'],
            outputPricePerMillion: $prices['output_price'],
            currency: Currency::Usd,
        );
    }
}
