<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Counting;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\AiManager;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Providers\Provider;
use Phoenix1331\AiHeadroom\Counting\Gateways\OpenAiCountingGateway;

/**
 * Exact counts from OpenAI's POST /v1/responses/input_tokens.
 */
class OpenAiCounter extends NativeCounter
{
    private readonly OpenAiCountingGateway $gateway;

    public function __construct(EstimatingCounter $estimator, AiManager $ai, NativeCountCache $cache, Dispatcher $events)
    {
        parent::__construct($estimator, $ai, $cache);

        $this->gateway = new OpenAiCountingGateway($events);
    }

    protected function countTotal(Provider $provider, PendingStep $step): int
    {
        return $this->gateway->countTokens($provider, $step, self::TIMEOUT_SECONDS);
    }
}
