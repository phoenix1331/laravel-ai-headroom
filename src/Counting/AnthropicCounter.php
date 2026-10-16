<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Counting;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\AiManager;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Providers\Provider;
use Phoenix1331\AiHeadroom\Counting\Gateways\AnthropicCountingGateway;

/**
 * Exact counts from Anthropic's POST /v1/messages/count_tokens.
 */
class AnthropicCounter extends NativeCounter
{
    private readonly AnthropicCountingGateway $gateway;

    public function __construct(EstimatingCounter $estimator, AiManager $ai, Dispatcher $events)
    {
        parent::__construct($estimator, $ai);

        $this->gateway = new AnthropicCountingGateway($events);
    }

    protected function countTotal(Provider $provider, PendingStep $step): int
    {
        return $this->gateway->countTokens($provider, $step, self::TIMEOUT_SECONDS);
    }
}
