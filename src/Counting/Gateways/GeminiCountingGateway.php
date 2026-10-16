<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Counting\Gateways;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\Gemini\GeminiGateway;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Tools\ToolNameResolver;
use UnexpectedValueException;

/**
 * The SDK talks to Gemini's Interactions API, whose request body countTokens
 * does not accept, so the step is mapped to a generateContentRequest here.
 * Attachments, the HTTP client and error handling are reused from the SDK.
 *
 * Provider tools (search, code execution) are not sent; only function
 * declarations are counted.
 */
class GeminiCountingGateway extends GeminiGateway
{
    public function countTokens(Provider $provider, PendingStep $step, int $timeout): int
    {
        $model = str_starts_with($step->model, 'models/') ? substr($step->model, 7) : $step->model;

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post("models/{$model}:countTokens", [
                'generateContentRequest' => $this->generateContentRequest($model, $step),
            ]),
        );

        $tokens = $response->json('totalTokens');

        if (! is_int($tokens)) {
            throw new UnexpectedValueException('Gemini countTokens did not return an integer totalTokens.');
        }

        return $tokens;
    }

    /**
     * @return array<string, mixed>
     */
    private function generateContentRequest(string $model, PendingStep $step): array
    {
        $request = [
            'model' => "models/{$model}",
            'contents' => array_values(array_filter(array_map($this->content(...), $step->messages))),
        ];

        if (filled($step->instructions)) {
            $request['systemInstruction'] = ['parts' => [['text' => $step->instructions]]];
        }

        $declarations = array_values(array_map(fn (Tool $tool): array => [
            'name' => ToolNameResolver::resolve($tool),
            'description' => (string) $tool->description(),
            'parametersJsonSchema' => (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory)))->toSchema(),
        ], array_filter($step->tools, fn (mixed $tool): bool => $tool instanceof Tool)));

        if ($declarations !== []) {
            $request['tools'] = [['functionDeclarations' => $declarations]];
        }

        if ($step->schema !== null && $step->schema !== []) {
            $request['generationConfig'] = [
                'responseMimeType' => 'application/json',
                'responseJsonSchema' => (new ObjectSchema($step->schema))->toSchema(),
            ];
        }

        return $request;
    }

    /**
     * @return array{role: string, parts: list<array<string, mixed>>}|null
     */
    private function content(Message $message): ?array
    {
        $parts = filled($message->content) ? [['text' => $message->content]] : [];

        if ($message instanceof UserMessage) {
            foreach ($message->attachments as $attachment) {
                $parts[] = $this->attachmentPart($this->mapAttachment($attachment));
            }
        }

        if ($message instanceof AssistantMessage) {
            foreach ($message->toolCalls as $call) {
                /** @var ToolCall $call */
                $parts[] = ['functionCall' => ['id' => $call->id, 'name' => $call->name, 'args' => (object) $call->arguments]];
            }
        }

        if ($message instanceof ToolResultMessage) {
            foreach ($message->toolResults as $result) {
                /** @var ToolResult $result */
                $parts[] = ['functionResponse' => ['id' => $result->id, 'name' => $result->name, 'response' => ['result' => $result->text()]]];
            }
        }

        if ($parts === []) {
            return null;
        }

        return ['role' => $message instanceof AssistantMessage ? 'model' : 'user', 'parts' => $parts];
    }

    /**
     * Convert the SDK's Interactions attachment shape to a generateContent part.
     *
     * @param  array<array-key, mixed>  $mapped
     * @return array<string, mixed>
     */
    private function attachmentPart(array $mapped): array
    {
        if (isset($mapped['uri'])) {
            return ['fileData' => array_filter(['mimeType' => $mapped['mime_type'] ?? null, 'fileUri' => $mapped['uri']])];
        }

        return ['inlineData' => ['mimeType' => $mapped['mime_type'] ?? 'application/octet-stream', 'data' => $mapped['data'] ?? '']];
    }
}
