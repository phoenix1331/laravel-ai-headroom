<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom;

use Illuminate\Support\ServiceProvider;

class AiHeadroomServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-headroom.php', 'ai-headroom');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/ai-headroom.php' => config_path('ai-headroom.php'),
        ], 'ai-headroom-config');
    }
}
