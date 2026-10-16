<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Ai\AiManager;
use Phoenix1331\AiHeadroom\Counting\Contracts\Counter;
use Phoenix1331\AiHeadroom\Counting\Contracts\Tokeniser;
use Phoenix1331\AiHeadroom\Counting\CounterResolver;
use Phoenix1331\AiHeadroom\Counting\EstimatingCounter;
use Phoenix1331\AiHeadroom\Counting\TiktokenTokeniser;
use Phoenix1331\AiHeadroom\Currency\ConfiguredExchangeRates;
use Phoenix1331\AiHeadroom\Currency\Contracts\ExchangeRates;
use Phoenix1331\AiHeadroom\Enums\CountingMode;
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

        $this->app->singleton(Tokeniser::class, function (Application $app): Tokeniser {
            $cachePath = $app->make('config')->string('ai-headroom.tokeniser.cache_path');

            if ($cachePath === '') {
                throw new InvalidArgumentException('Set ai-headroom.tokeniser.cache_path to a writable directory.');
            }

            return new TiktokenTokeniser($cachePath);
        });

        $this->app->singleton(EstimatingCounter::class, function (Application $app): EstimatingCounter {
            $config = $app->make('config');

            return new EstimatingCounter(
                tokeniser: $app->make(Tokeniser::class),
                models: $app->make(ModelRegistry::class),
                ratios: $config->array('ai-headroom.estimate_ratios'),
                attachmentTokens: $config->array('ai-headroom.attachment_tokens'),
                safetyMargin: $config->float('ai-headroom.estimate_safety_margin'),
            );
        });

        $this->app->singleton(CounterResolver::class, fn (Application $app): CounterResolver => new CounterResolver(
            container: $app,
            ai: $app->make(AiManager::class),
            estimator: $app->make(EstimatingCounter::class),
            mode: CountingMode::fromConfig($app->make('config')->string('ai-headroom.method', 'auto')),
            counters: $app->make('config')->array('ai-headroom.counters', []),
        ));

        $this->app->bind(Counter::class, CounterResolver::class);

        $this->app->singleton(HeadroomManager::class, fn (Application $app): HeadroomManager => new HeadroomManager(
            counter: $app->make(Counter::class),
            models: $app->make(ModelRegistry::class),
            config: $app->make('config'),
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/ai-headroom.php' => config_path('ai-headroom.php'),
        ], 'ai-headroom-config');
    }
}
