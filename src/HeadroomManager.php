<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Laravel\Ai\Ai;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;
use Phoenix1331\AiHeadroom\Counting\Contracts\Counter;
use Phoenix1331\AiHeadroom\Data\Tally;
use Phoenix1331\AiHeadroom\Data\TokenCount;
use Phoenix1331\AiHeadroom\Models\ModelRegistry;

class HeadroomManager
{
    public function __construct(
        private readonly Counter $counter,
        private readonly ModelRegistry $models,
        private readonly Repository $config,
    ) {}

    /**
     * Count plain text sent as a single user message to the model.
     *
     * @param  string|null  $model  Null uses the configured default model.
     */
    public function count(string $text, ?string $model = null): TokenCount
    {
        $model ??= $this->defaultModel();

        $step = new PendingStep(
            number: 0,
            isFinalStep: true,
            provider: $this->models->get($model)->provider ?? $this->defaultProvider(),
            model: $model,
            instructions: null,
            messages: [new UserMessage($text)],
            tools: [],
            schema: null,
            options: null,
        );

        return $this->toTokenCount($this->counter->count($step), $model, null);
    }

    /**
     * The `default_model` config value, or laravel/ai's default text model for its default provider.
     */
    public function defaultModel(): string
    {
        $configured = $this->config->get('ai-headroom.default_model');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return Ai::textProvider($this->defaultProvider())->defaultTextModel();
    }

    /**
     * Combine a counter's figures with the model's context window and worst-case cost.
     *
     * @param  int|null  $maxOutputTokens  The request's maxTokens; null prices output at the model maximum.
     */
    public function toTokenCount(Tally $tally, string $model, ?int $maxOutputTokens): TokenCount
    {
        return new TokenCount(
            total: $tally->total,
            breakdown: $tally->breakdown,
            method: $tally->method,
            confidence: $tally->confidence,
            model: $model,
            contextWindow: $this->models->get($model)->contextWindow,
            estimatedCost: $this->models->costFor($model, $tally->total, $maxOutputTokens),
        );
    }

    private function defaultProvider(): string
    {
        $provider = $this->config->get('ai.default');

        if (! is_string($provider) || $provider === '') {
            throw new InvalidArgumentException('Headroom could not work out a default model: set ai-headroom.default_model, or a string ai.default provider for laravel/ai.');
        }

        return $provider;
    }
}
