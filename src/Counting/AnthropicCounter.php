<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Counting;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\AiManager;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Providers\Provider;
use LogicException;
use Phoenix1331\AiHeadroom\Counting\Gateways\AnthropicCountingGateway;

/**
 * Exact counts from Anthropic's POST /v1/messages/count_tokens, using the
 * API key and base URL configured for the step's laravel/ai provider.
 */
class AnthropicCounter extends NativeCounter
{
    private const int TIMEOUT_SECONDS = 15;

    private readonly AnthropicCountingGateway $gateway;

    public function __construct(
        EstimatingCounter $estimator,
        private readonly AiManager $ai,
        Dispatcher $events,
    ) {
        parent::__construct($estimator);

        $this->gateway = new AnthropicCountingGateway($events);
    }

    protected function countTotal(PendingStep $step): int
    {
        $provider = $this->ai->textProvider($step->provider);

        if (! $provider instanceof Provider) {
            throw new LogicException("The laravel/ai provider [{$step->provider}] cannot be used for native token counting.");
        }

        return $this->gateway->countTokens($provider, $step, self::TIMEOUT_SECONDS);
    }
}
