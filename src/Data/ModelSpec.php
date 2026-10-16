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
     * @param  list<array{above: int, input_price: float, output_price: float}>  $tiers  Prices that replace the base prices for the whole request once the prompt is above `above` tokens.
     * @param  bool  $isFallback  True when the model is unknown and these are the cautious fallback values.
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
    ) {
        if ($contextWindow <= 0 || $maxOutputTokens <= 0) {
            throw new InvalidArgumentException("Model [{$name}] needs a positive context_window and max_output_tokens.");
        }

        if ($inputPrice < 0 || $outputPrice < 0) {
            throw new InvalidArgumentException("Model [{$name}] prices cannot be negative.");
        }

        foreach ($tiers as $tier) {
            if ($tier['above'] < 0 || $tier['input_price'] < 0 || $tier['output_price'] < 0) {
                throw new InvalidArgumentException("Model [{$name}] has a tier with a negative value.");
            }
        }
    }

    /**
     * The input and output prices that apply to a prompt of the given size.
     *
     * @return array{input_price: float, output_price: float}
     */
    public function pricesFor(int $inputTokens): array
    {
        $prices = ['input_price' => $this->inputPrice, 'output_price' => $this->outputPrice];

        $threshold = -1;

        foreach ($this->tiers as $tier) {
            if ($inputTokens > $tier['above'] && $tier['above'] > $threshold) {
                $threshold = $tier['above'];
                $prices = ['input_price' => $tier['input_price'], 'output_price' => $tier['output_price']];
            }
        }

        return $prices;
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
