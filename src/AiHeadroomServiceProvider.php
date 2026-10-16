<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Phoenix1331\AiHeadroom\Models\ModelRegistry;

class AiHeadroomServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-headroom.php', 'ai-headroom');

        $this->app->singleton(ModelRegistry::class, function (Application $app): ModelRegistry {
            $config = $app->make('config');

            return ModelRegistry::withShippedModels(
                overrides: $config->array('ai-headroom.models', []),
                fallback: $config->array('ai-headroom.fallback_model', []),
                currency: $config->string('ai-headroom.currency', 'USD'),
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
