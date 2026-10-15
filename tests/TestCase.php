<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Phoenix1331\AiHeadroom\AiHeadroomServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            AiHeadroomServiceProvider::class,
        ];
    }
}
