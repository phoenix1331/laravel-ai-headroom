<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Counting;

use InvalidArgumentException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\Video;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Phoenix1331\AiHeadroom\Counting\Contracts\Counter;
use Phoenix1331\AiHeadroom\Counting\Contracts\Tokeniser;
use Phoenix1331\AiHeadroom\Data\Limits;
use Phoenix1331\AiHeadroom\Data\Tally;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroom\Models\ModelRegistry;

/**
 * Counts locally with OpenAI's o200k tokeniser. OpenAI models are counted
 * directly; other providers tokenise differently, so their counts are scaled
 * by a per-provider ratio and padded with the safety margin.
 */
class EstimatingCounter implements Counter
{
    /** Provider whose models use the local tokeniser's encoding. */
    private const string EXACT_PROVIDER = 'openai';

    /** Role and boundary tokens added per message, plus once per request to prime the reply. */
    private const int MESSAGE_OVERHEAD = 3;

    /** Expected error for OpenAI estimates: message and tool serialisation drift only. */
    private const float EXACT_CONFIDENCE = 0.02;

    /** Expected error once images or files are involved, since providers count them by their own rules. */
    private const float ATTACHMENT_CONFIDENCE = 0.5;

    /** @var array<string, float> */
    private array $ratios = [];

    /** @var array<string, int> */
    private array $attachmentTokens = [];

    /**
     * @param  array<array-key, mixed>  $ratios  Provider => provider tokens per o200k token; 'default' for unlisted providers.
     * @param  array<array-key, mixed>  $attachmentTokens  Tokens assumed per attachment by kind: image, document, audio, video, other.
     * @param  float  $safetyMargin  Padding for providers without an exact local tokeniser, e.g. 0.10.
     */
    public function __construct(
        private readonly Tokeniser $tokeniser,
        private readonly ModelRegistry $models,
        array $ratios,
        array $attachmentTokens,
        private readonly float $safetyMargin,
    ) {
        foreach ($ratios as $provider => $ratio) {
            if (! is_numeric($ratio) || (float) $ratio <= 0) {
                throw new InvalidArgumentException("The Headroom estimate ratio for [{$provider}] must be a number greater than zero.");
            }

            $this->ratios[(string) $provider] = (float) $ratio;
        }

        foreach ($attachmentTokens as $kind => $tokens) {
            if (! is_numeric($tokens) || (int) $tokens < 0) {
                throw new InvalidArgumentException("The Headroom attachment tokens for [{$kind}] must be a whole number of zero or more.");
            }

            $this->attachmentTokens[(string) $kind] = (int) $tokens;
        }

        if ($safetyMargin < 0) {
            throw new InvalidArgumentException('The Headroom estimate safety margin cannot be negative.');
        }
    }

    public function count(PendingStep $step, ?Limits $limits = null): Tally
    {
        $provider = $this->models->get($step->model)->provider ?? $step->provider;
        $exact = $provider === self::EXACT_PROVIDER;
        $scale = ($this->ratios[$provider] ?? $this->ratios['default'] ?? 1.0) * ($exact ? 1.0 : 1.0 + $this->safetyMargin);

        $messages = $step->messages;
        $last = end($messages);
        $prompt = $last instanceof UserMessage ? array_pop($messages) : null;

        $attachments = $this->attachmentTokens($step->messages);

        $breakdown = [
            'instructions' => $this->scaled($this->tokens((string) $step->instructions), $scale),
            'history' => $this->scaled(array_sum(array_map($this->messageTokens(...), $messages)), $scale),
            'tools' => $this->scaled($this->toolTokens($step->tools, $step->schema), $scale),
            'attachments' => $attachments,
            'prompt' => $this->scaled($prompt === null ? 0 : $this->tokens((string) $prompt->content), $scale),
        ];

        $overhead = $this->scaled(self::MESSAGE_OVERHEAD * (count($step->messages) + 1), $scale);

        $confidence = $exact ? self::EXACT_CONFIDENCE : $this->safetyMargin;

        return new Tally(
            total: array_sum($breakdown) + $overhead,
            breakdown: $breakdown,
            method: CountMethod::Estimated,
            confidence: $attachments > 0 ? max($confidence, self::ATTACHMENT_CONFIDENCE) : $confidence,
        );
    }

    private function tokens(string $text): int
    {
        return $this->tokeniser->count($text);
    }

    private function scaled(int $tokens, float $scale): int
    {
        return (int) ceil($tokens * $scale);
    }

    private function messageTokens(Message $message): int
    {
        $tokens = $this->tokens((string) $message->content);

        if ($message instanceof AssistantMessage) {
            foreach ($message->toolCalls as $call) {
                /** @var ToolCall $call */
                $tokens += $this->tokens($this->json(['name' => $call->name, 'arguments' => $call->arguments]));
            }
        }

        if ($message instanceof ToolResultMessage) {
            foreach ($message->toolResults as $result) {
                /** @var ToolResult $result */
                $tokens += $this->tokens($result->name.$result->text());
            }
        }

        return $tokens;
    }

    /**
     * @param  array<array-key, Tool|ProviderTool>  $tools
     * @param  array<string, mixed>|null  $schema
     */
    private function toolTokens(array $tools, ?array $schema): int
    {
        $tokens = 0;

        foreach ($tools as $tool) {
            $tokens += $this->tokens($this->json(ToolDefinition::of($tool)));
        }

        if ($schema !== null && $schema !== []) {
            $tokens += $this->tokens($this->json((new ObjectSchema($schema))->toSchema()));
        }

        return $tokens;
    }

    /**
     * Attachments are not tokenised locally; each is assumed to cost a fixed, configurable amount.
     *
     * @param  array<array-key, Message>  $messages
     */
    private function attachmentTokens(array $messages): int
    {
        $tokens = 0;

        foreach ($messages as $message) {
            if (! $message instanceof UserMessage) {
                continue;
            }

            foreach ($message->attachments as $attachment) {
                $tokens += $this->attachmentTokens[$this->attachmentKind($attachment)] ?? 0;
            }
        }

        return $tokens;
    }

    private function attachmentKind(mixed $attachment): string
    {
        return match (true) {
            $attachment instanceof Image => 'image',
            $attachment instanceof Document => 'document',
            $attachment instanceof Audio => 'audio',
            $attachment instanceof Video => 'video',
            default => 'other',
        };
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
