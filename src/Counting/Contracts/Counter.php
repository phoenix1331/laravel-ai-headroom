<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Counting\Contracts;

use Laravel\Ai\PendingStep;
use Phoenix1331\AiHeadroom\Data\Limits;
use Phoenix1331\AiHeadroom\Data\Tally;

interface Counter
{
    /**
     * Count the input tokens of the request the step would send.
     *
     * @param  Limits|null  $limits  The limits the result will be checked against; counters that trade accuracy for cost (escalation) use them, others ignore them.
     */
    public function count(PendingStep $step, ?Limits $limits = null): Tally;
}
