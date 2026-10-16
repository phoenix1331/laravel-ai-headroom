<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Counting\Gateways;

use Laravel\Ai\Gateway\Anthropic\AnthropicGateway;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Providers\Provider;
use UnexpectedValueException;

/**
 * Reuses the SDK's own Anthropic request body and HTTP client, so the counted
 * request is serialised exactly as the real one would be.
 */
class AnthropicCountingGateway extends AnthropicGateway
{
    /** Body fields the count_tokens endpoint accepts; generation-only fields such as max_tokens are dropped. */
    private const array COUNT_FIELDS = [
        'model',
        'messages',
        'system',
        'tools',
        'tool_choice',
        'thinking',
        'output_config',
        'cache_control',
    ];

    public function countTokens(Provider $provider, PendingStep $step, int $timeout): int
    {
        $body = array_intersect_key(
            $this->buildTextRequestBody($provider, $step->model, $step->instructions, $step->messages, $step->tools, $step->schema, $step->options),
            array_flip(self::COUNT_FIELDS),
        );

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('messages/count_tokens', $body),
        );

        $tokens = $response->json('input_tokens');

        if (! is_int($tokens)) {
            throw new UnexpectedValueException('Anthropic count_tokens did not return an integer input_tokens.');
        }

        return $tokens;
    }
}
