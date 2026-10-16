<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Phoenix1331\AiHeadroom\Currency\ConfiguredExchangeRates;
use Phoenix1331\AiHeadroom\Currency\Contracts\ExchangeRates;
use Phoenix1331\AiHeadroom\Enums\Currency;
use Phoenix1331\AiHeadroom\Models\ModelRegistry;

class AiHeadroomServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-headroom.php', 'ai-headroom');

        $this->app->singleton(ExchangeRates::class, fn (Application $app): ExchangeRates => new ConfiguredExchangeRates(
            $app->make('config')->array('ai-headroom.exchange_rates', []),
        ));

        $this->app->singleton(ModelRegistry::class, function (Application $app): ModelRegistry {
            $config = $app->make('config');

            return ModelRegistry::withShippedModels(
                overrides: $config->array('ai-headroom.models', []),
                fallback: $config->array('ai-headroom.fallback_model', []),
                currency: Currency::fromCode($config->string('ai-headroom.currency', 'USD')),
                exchangeRates: $app->make(ExchangeRates::class),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/ai-headroom.php' => config_path('ai-headroom.php'),
        ], 'ai-headroom-config');
    }
}
