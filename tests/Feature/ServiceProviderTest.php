<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Phoenix1331\AiHeadroom\AiHeadroomServiceProvider;

it('registers the package config', function (): void {
    expect(config('ai-headroom'))->toBeArray();
});

it('publishes the config file under its own tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(AiHeadroomServiceProvider::class, 'ai-headroom-config');

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths)[0])->toEndWith('ai-headroom.php');
});
