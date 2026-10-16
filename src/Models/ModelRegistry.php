<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Models;

use InvalidArgumentException;
use Phoenix1331\AiHeadroom\Currency\Contracts\ExchangeRates;
use Phoenix1331\AiHeadroom\Data\Cost;
use Phoenix1331\AiHeadroom\Data\ModelSpec;
use Phoenix1331\AiHeadroom\Enums\Currency;

/**
 * Context windows and prices per model: the defaults shipped in
 * resources/models.php with the app's config overrides merged on top.
 */
class ModelRegistry
{
    /** @var array<string, ModelSpec> */
    private array $models = [];

    private ModelSpec $fallback;

    /** @var array<string, 'shipped'|'overridden'|'app'> */
    private array $sources = [];

    /**
     * @param  array<array-key, mixed>  $defaults  Shipped models, keyed by model name.
     * @param  array<array-key, mixed>  $overrides  App models; each field given replaces the shipped field whole (including `tiers`).
     * @param  array<array-key, mixed>  $fallback  Values for unknown models.
     */
    public function __construct(
        array $defaults,
        array $overrides,
        array $fallback,
        private readonly Currency $currency,
        private readonly ExchangeRates $exchangeRates,
        private readonly ?string $lastChecked = null,
    ) {
        foreach (array_keys($defaults) as $name) {
            $this->sources[(string) $name] = 'shipped';
        }

        foreach ($overrides as $name => $attributes) {
            $this->sources[(string) $name] = isset($defaults[$name]) ? 'overridden' : 'app';

            $defaults[$name] = is_array($attributes) && is_array($defaults[$name] ?? null)
                ? array_replace($defaults[$name], $attributes)
                : $attributes;
        }

        foreach ($defaults as $name => $attributes) {
            if (! is_array($attributes)) {
                throw new InvalidArgumentException("Model [{$name}] in the Headroom model registry must be an array.");
            }

            $this->models[(string) $name] = $this->makeSpec((string) $name, $attributes);
        }

        $this->fallback = $this->makeSpec('fallback', $fallback, isFallback: true);
    }

    /**
     * Build the registry from the models shipped in resources/models.php plus the app's overrides.
     *
     * @param  array<array-key, mixed>  $overrides
     * @param  array<array-key, mixed>  $fallback
     */
    public static function withShippedModels(array $overrides, array $fallback, Currency $currency, ExchangeRates $exchangeRates, ?string $path = null): self
    {
        $shipped = require $path ?? __DIR__.'/../../resources/models.php';

        if (! is_array($shipped) || ! is_array($shipped['models'] ?? null) || ! is_string($shipped['last_checked'] ?? null)) {
            throw new InvalidArgumentException('The shipped model registry must return [last_checked => string, models => array].');
        }

        return new self($shipped['models'], $overrides, $fallback, $currency, $exchangeRates, $shipped['last_checked']);
    }

    public function has(string $model): bool
    {
        return isset($this->models[$model]);
    }

    /**
     * The model's spec, or the fallback (named after the requested model) when it is unknown.
     */
    public function get(string $model): ModelSpec
    {
        if (isset($this->models[$model])) {
            return $this->models[$model];
        }

        return new ModelSpec(
            name: $model,
            provider: null,
            contextWindow: $this->fallback->contextWindow,
            maxOutputTokens: $this->fallback->maxOutputTokens,
            inputPrice: $this->fallback->inputPrice,
            outputPrice: $this->fallback->outputPrice,
            tiers: $this->fallback->tiers,
            isFallback: true,
            cacheReadPrice: $this->fallback->cacheReadPrice,
            cacheWritePrice: $this->fallback->cacheWritePrice,
        );
    }

    /**
     * @return array<string, ModelSpec>
     */
    public function all(): array
    {
        return $this->models;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    /**
     * The worst-case cost of a prompt for the model, in the configured currency.
     *
     * @param  int|null  $maxOutputTokens  The request's maxTokens; null uses the model's maximum.
     */
    public function costFor(string $model, int $inputTokens, ?int $maxOutputTokens): Cost
    {
        return $this->inConfiguredCurrency($this->get($model)->costFor($inputTokens, $maxOutputTokens));
    }

    /**
     * What a completed request actually cost, in the configured currency.
     */
    public function actualCostFor(string $model, int $inputTokens, int $outputTokens, ?int $cacheReadTokens = null, ?int $cacheWriteTokens = null): Cost
    {
        return $this->inConfiguredCurrency($this->get($model)->actualCostFor($inputTokens, $outputTokens, $cacheReadTokens, $cacheWriteTokens));
    }

    /**
     * Where a model's figures come from: shipped with Headroom, shipped but overridden by the app, or added by the app.
     *
     * @return 'shipped'|'overridden'|'app'|null
     */
    public function source(string $model): ?string
    {
        return $this->sources[$model] ?? null;
    }

    /**
     * The values used for unknown models.
     */
    public function fallback(): ModelSpec
    {
        return $this->fallback;
    }

    /**
     * A USD price in the configured currency.
     */
    public function priceInCurrency(float $usd): float
    {
        if ($this->currency === Currency::Usd) {
            return $usd;
        }

        return $usd * $this->exchangeRates->rate(Currency::Usd, $this->currency);
    }

    private function inConfiguredCurrency(Cost $cost): Cost
    {
        if ($this->currency === $cost->currency) {
            return $cost;
        }

        return $cost->convertTo($this->currency, $this->exchangeRates->rate($cost->currency, $this->currency));
    }

    /**
     * When the shipped prices and windows were last checked against the providers, as Y-m-d.
     */
    public function lastChecked(): ?string
    {
        return $this->lastChecked;
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    private function makeSpec(string $name, array $attributes, bool $isFallback = false): ModelSpec
    {
        foreach (['context_window', 'max_output_tokens', 'input_price', 'output_price'] as $key) {
            if (! isset($attributes[$key]) || ! is_numeric($attributes[$key])) {
                throw new InvalidArgumentException("Model [{$name}] is missing a numeric [{$key}] in the Headroom model registry.");
            }
        }

        $provider = $attributes['provider'] ?? null;

        return new ModelSpec(
            name: $name,
            provider: is_string($provider) ? $provider : null,
            contextWindow: (int) $attributes['context_window'],
            maxOutputTokens: (int) $attributes['max_output_tokens'],
            inputPrice: (float) $attributes['input_price'],
            outputPrice: (float) $attributes['output_price'],
            tiers: $this->makeTiers($name, $attributes['tiers'] ?? []),
            isFallback: $isFallback,
            cacheReadPrice: $this->optionalPrice($name, $attributes, 'cache_read_price'),
            cacheWritePrice: $this->optionalPrice($name, $attributes, 'cache_write_price'),
        );
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    private function optionalPrice(string $name, array $attributes, string $key): ?float
    {
        if (! isset($attributes[$key])) {
            return null;
        }

        if (! is_numeric($attributes[$key])) {
            throw new InvalidArgumentException("Model [{$name}] has a non-numeric [{$key}] in the Headroom model registry.");
        }

        return (float) $attributes[$key];
    }

    /**
     * @return list<array{above: int, input_price: float, output_price: float, cache_read_price: float|null, cache_write_price: float|null}>
     */
    private function makeTiers(string $name, mixed $tiers): array
    {
        if (! is_array($tiers)) {
            throw new InvalidArgumentException("Model [{$name}] tiers must be an array.");
        }

        $made = [];

        foreach ($tiers as $tier) {
            if (! is_array($tier) || ! is_numeric($tier['above'] ?? null) || ! is_numeric($tier['input_price'] ?? null) || ! is_numeric($tier['output_price'] ?? null)) {
                throw new InvalidArgumentException("Model [{$name}] has a tier without numeric [above], [input_price] and [output_price].");
            }

            $made[] = [
                'above' => (int) $tier['above'],
                'input_price' => (float) $tier['input_price'],
                'output_price' => (float) $tier['output_price'],
                'cache_read_price' => $this->optionalPrice($name, $tier, 'cache_read_price'),
                'cache_write_price' => $this->optionalPrice($name, $tier, 'cache_write_price'),
            ];
        }

        return $made;
    }
}
