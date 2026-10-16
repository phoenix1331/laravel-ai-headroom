<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Counting\Gateways;

use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Providers\Provider;
use UnexpectedValueException;

/**
 * Reuses the SDK's own Responses API request body and HTTP client, so the
 * counted request is serialised exactly as the real one would be.
 */
class OpenAiCountingGateway extends OpenAiGateway
{
    /** Body fields the input_tokens endpoint accepts; generation-only fields such as max_output_tokens and store are dropped. */
    private const array COUNT_FIELDS = [
        'model',
        'input',
        'instructions',
        'tools',
        'tool_choice',
        'parallel_tool_calls',
        'text',
        'reasoning',
        'truncation',
        'conversation',
        'previous_response_id',
        'personality',
    ];

    public function countTokens(Provider $provider, PendingStep $step, int $timeout): int
    {
        $body = array_intersect_key(
            $this->buildTextRequestBody($provider, $step->model, $step->instructions, $step->messages, $step->tools, $step->schema, $step->options),
            array_flip(self::COUNT_FIELDS),
        );

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('responses/input_tokens', $body),
        );

        $tokens = $response->json('input_tokens');

        if (! is_int($tokens)) {
            throw new UnexpectedValueException('OpenAI responses/input_tokens did not return an integer input_tokens.');
        }

        return $tokens;
    }
}
