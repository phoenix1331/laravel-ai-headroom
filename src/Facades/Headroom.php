<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Facades;

use Illuminate\Support\Facades\Facade;
use Phoenix1331\AiHeadroom\HeadroomManager;

/**
 * @method static \Phoenix1331\AiHeadroom\Data\TokenCount count(string $text, ?string $model = null)
 * @method static string defaultModel()
 *
 * @see HeadroomManager
 */
class Headroom extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return HeadroomManager::class;
    }
}
