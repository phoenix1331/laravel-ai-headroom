<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Enums;

use InvalidArgumentException;

/**
 * The `method` config: how Headroom chooses between local estimates and the
 * providers' counting endpoints.
 */
enum CountingMode: string
{
    /** Native where the provider has a counter and an API key, otherwise estimate. */
    case Auto = 'auto';

    /** Always the provider's counting endpoint where Headroom has a counter for it. */
    case Native = 'native';

    /** Never call a provider; prompt content stays in the app. */
    case Estimate = 'estimate';

    public static function fromConfig(string $value): self
    {
        return self::tryFrom(strtolower(trim($value)))
            ?? throw new InvalidArgumentException("Headroom does not recognise the counting method [{$value}]. Use auto, native or estimate.");
    }
}
