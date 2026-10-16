<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Enums;

/**
 * Which limit a request was checked against.
 */
enum LimitType: string
{
    case ContextWindow = 'context_window';
    case PercentOfContext = 'max_percent_of_context';
    case Cost = 'max_cost';
}
