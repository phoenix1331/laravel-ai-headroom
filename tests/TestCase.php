<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Tests;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Phoenix1331\AiHeadroom\AiHeadroomServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // The default suite must never reach a real provider; unfaked requests throw.
        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            AiHeadroomServiceProvider::class,
        ];
    }
}
