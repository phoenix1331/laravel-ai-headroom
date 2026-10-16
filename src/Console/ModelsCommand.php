<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Phoenix1331\AiHeadroom\Data\ModelSpec;
use Phoenix1331\AiHeadroom\Models\ModelRegistry;

class ModelsCommand extends Command
{
    /** Shipped prices older than this are flagged, since outdated prices are the quickest way to lose trust. */
    private const int STALE_AFTER_DAYS = 60;

    protected $signature = 'headroom:models
        {--provider= : Only show models from this provider}
        {--json : Output the registry as JSON}';

    protected $description = 'List the models Headroom knows: context windows, output limits and prices';

    public function handle(ModelRegistry $registry): int
    {
        $provider = $this->option('provider');
        $provider = is_string($provider) && $provider !== '' ? $provider : null;

        $models = array_filter($registry->all(), fn (ModelSpec $spec): bool => $provider === null || $spec->provider === $provider);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'currency' => $registry->currency()->value,
                'last_checked' => $registry->lastChecked(),
                'models' => array_values(array_map(fn (ModelSpec $spec): array => $this->toArray($registry, $spec), $models)),
                'fallback' => $this->toArray($registry, $registry->fallback()),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $currency = $registry->currency();

        $this->table(
            ['Model', 'Provider', 'Source', 'Context', 'Max output', "Input /M ({$currency->value})", "Output /M ({$currency->value})", 'Cache read /M', 'Cache write /M', 'Long prompts'],
            array_values(array_map(fn (ModelSpec $spec): array => $this->row($registry, $spec), $models)),
        );

        $fallback = $registry->fallback();
        $symbol = $currency->symbol();

        $this->components->twoColumnDetail('<fg=gray>Unknown models</>', sprintf(
            '%s context, %s max output, %s%s / %s%s per million',
            number_format($fallback->contextWindow),
            number_format($fallback->maxOutputTokens),
            $symbol,
            $this->price($registry->priceInCurrency($fallback->inputPrice)),
            $symbol,
            $this->price($registry->priceInCurrency($fallback->outputPrice)),
        ));

        $this->lastChecked($registry->lastChecked());

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function row(ModelRegistry $registry, ModelSpec $spec): array
    {
        $symbol = $registry->currency()->symbol();
        $money = fn (?float $usd): string => $usd === null ? '-' : $symbol.$this->price($registry->priceInCurrency($usd));

        return [
            $spec->name,
            (string) $spec->provider,
            (string) $registry->source($spec->name),
            number_format($spec->contextWindow),
            number_format($spec->maxOutputTokens),
            $money($spec->inputPrice),
            $money($spec->outputPrice),
            $money($spec->cacheReadPrice),
            $money($spec->cacheWritePrice),
            implode(', ', array_map(
                fn (array $tier): string => sprintf('over %s: %s / %s', number_format($tier['above']), $money($tier['input_price']), $money($tier['output_price'])),
                $spec->tiers,
            )) ?: '-',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(ModelRegistry $registry, ModelSpec $spec): array
    {
        $price = fn (?float $usd): ?float => $usd === null ? null : round($registry->priceInCurrency($usd), 8);

        return [
            'model' => $spec->name,
            'provider' => $spec->provider,
            'source' => $spec->isFallback ? 'fallback' : $registry->source($spec->name),
            'context_window' => $spec->contextWindow,
            'max_output_tokens' => $spec->maxOutputTokens,
            'input_price' => $price($spec->inputPrice),
            'output_price' => $price($spec->outputPrice),
            'cache_read_price' => $price($spec->cacheReadPrice),
            'cache_write_price' => $price($spec->cacheWritePrice),
            'tiers' => array_map(fn (array $tier): array => [
                'above' => $tier['above'],
                'input_price' => $price($tier['input_price']),
                'output_price' => $price($tier['output_price']),
                'cache_read_price' => $price($tier['cache_read_price'] ?? null),
                'cache_write_price' => $price($tier['cache_write_price'] ?? null),
            ], $spec->tiers),
        ];
    }

    private function lastChecked(?string $date): void
    {
        if ($date === null) {
            return;
        }

        $days = (int) CarbonImmutable::parse($date)->diffInDays(CarbonImmutable::now());

        $this->components->twoColumnDetail('<fg=gray>Shipped prices checked</>', "{$date} ({$days} days ago)");

        if ($days > self::STALE_AFTER_DAYS) {
            $this->components->warn('Shipped prices were checked over '.self::STALE_AFTER_DAYS.' days ago. Update Headroom, or override prices in config/ai-headroom.php.');
        }
    }

    private function price(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') ?: '0';
    }
}
