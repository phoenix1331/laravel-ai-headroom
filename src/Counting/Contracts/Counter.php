<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Counting\Contracts;

use Laravel\Ai\PendingStep;
use Phoenix1331\AiHeadroom\Data\Tally;

interface Counter
{
    /**
     * Count the input tokens of the request the step would send.
     */
    public function count(PendingStep $step): Tally;
}
