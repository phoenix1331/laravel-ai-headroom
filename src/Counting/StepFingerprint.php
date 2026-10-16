<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Counting;

use Illuminate\Contracts\Support\Arrayable;
use Laravel\Ai\Files\LocalAudio;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\LocalVideo;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

/**
 * A SHA-256 hash of everything in a step that changes its input token count.
 * Only the hash is ever stored, never the content.
 */
final class StepFingerprint
{
    public static function of(PendingStep $step): string
    {
        return hash('sha256', (string) json_encode([
            'provider' => $step->provider,
            'model' => $step->model,
            'instructions' => $step->instructions,
            'messages' => array_map(self::message(...), $step->messages),
            'tools' => array_map(ToolDefinition::of(...), $step->tools),
            'schema' => $step->schema === null ? null : (new ObjectSchema($step->schema))->toSchema(),
            'tool_choice' => $step->options?->toolChoice === null ? null : [$step->options->toolChoice->mode, $step->options->toolChoice->toolName],
            'provider_options' => $step->options?->providerOptions($step->provider),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private static function message(Message $message): array
    {
        $fingerprint = ['role' => $message->role->value, 'content' => $message->content];

        if ($message instanceof UserMessage) {
            $fingerprint['attachments'] = $message->attachments->map(self::attachment(...))->all();
        }

        if ($message instanceof AssistantMessage) {
            $fingerprint['tool_calls'] = $message->toolCalls->map(fn (ToolCall $call): array => $call->toArray())->all();
            $fingerprint['replay_blocks'] = $message->replayBlocks;
        }

        if ($message instanceof ToolResultMessage) {
            $fingerprint['tool_results'] = $message->toolResults->map(fn (ToolResult $result): array => $result->toArray())->all();
        }

        return $fingerprint;
    }

    /**
     * Files are identified by their own array form (base64 content, path, URL
     * or provider id). Local files also include size and modification time,
     * so editing a file in place changes the fingerprint.
     *
     * @return array<array-key, mixed>
     */
    private static function attachment(mixed $file): array
    {
        $fingerprint = $file instanceof Arrayable ? $file->toArray() : ['class' => get_debug_type($file)];

        $isLocal = $file instanceof LocalImage || $file instanceof LocalDocument || $file instanceof LocalAudio || $file instanceof LocalVideo;

        if ($isLocal && is_file($file->path)) {
            $fingerprint['size'] = filesize($file->path);
            $fingerprint['modified'] = filemtime($file->path);
        }

        return $fingerprint;
    }
}
