<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Enums;

enum CountMethod: string
{
    /** Counted by the provider's own token counting endpoint. */
    case Native = 'native';

    /** Counted locally with a tokeniser or approximation, plus the safety margin. */
    case Estimated = 'estimated';

    /** A local estimate corrected by the factor learned from this app's real usage. */
    case Calibrated = 'calibrated';

    public function isExact(): bool
    {
        return $this === self::Native;
    }
}
