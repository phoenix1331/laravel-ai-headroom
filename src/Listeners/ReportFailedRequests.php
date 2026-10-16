<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Events\StepFailed;
use Phoenix1331\AiHeadroom\Events\RequestFailed;
use Phoenix1331\AiHeadroom\Tracking\RequestTracker;

/**
 * Turns the SDK's StepFailed into RequestFailed for steps EnsureHeadroom counted.
 * The SDK fires StepFailed for normal and streamed calls alike, but not for
 * steps middleware blocked, so blocked requests are not reported as failures.
 */
class ReportFailedRequests
{
    public function __construct(
        private readonly RequestTracker $tracker,
        private readonly Dispatcher $events,
    ) {}

    public function handle(StepFailed $event): void
    {
        $count = $this->tracker->pull($event->invocationId, $event->stepNumber);

        if ($count === null) {
            return;
        }

        $this->events->dispatch(RequestFailed::from(
            $count->requestId(),
            $event->invocationId,
            $event->provider->name(),
            $event->model,
            $event->exception,
        ));
    }
}
