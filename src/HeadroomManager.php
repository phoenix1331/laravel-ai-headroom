<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Phoenix1331\AiHeadroom\Context\ContextResolver;
use Phoenix1331\AiHeadroom\Counting\Contracts\Counter;
use Phoenix1331\AiHeadroom\Data\Tally;
use Phoenix1331\AiHeadroom\Data\TokenCount;
use Phoenix1331\AiHeadroom\Events\TokensCounted;
use Phoenix1331\AiHeadroom\Events\TokensUsed;
use Phoenix1331\AiHeadroom\Models\ModelRegistry;
use Phoenix1331\AiHeadroom\Resolving\RequestResolver;

class HeadroomManager
{
    public function __construct(
        private readonly Counter $counter,
        private readonly ModelRegistry $models,
        private readonly Repository $config,
        private readonly RequestResolver $resolver,
        private readonly ContextResolver $context,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Count the whole request the agent would send for the prompt: instructions,
     * history, tools, attachments and the prompt itself, without calling the model.
     * Fires TokensCounted.
     *
     * Arguments mirror $agent->prompt(). History added with withMessages() and tools
     * set with withTools() are private to the agent, so pass them as $messages and $tools.
     *
     * @param  array<int, mixed>  $attachments
     * @param  Lab|array<array-key, mixed>|string|null  $provider
     * @param  iterable<int, mixed>|null  $messages
     * @param  iterable<int, mixed>|null  $tools
     */
    public function for(
        Agent $agent,
        AgentInput|UserMessage|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?iterable $messages = null,
        ?iterable $tools = null,
    ): TokenCount {
        $step = $this->resolver->resolve($agent, $prompt, $attachments, $provider, $model, $messages, $tools);

        $count = $this->toTokenCount(
            $this->counter->count($step),
            $step->model,
            $step->options?->maxTokens,
            provider: $step->provider,
            agent: $agent::class,
            context: $this->context->resolve($agent),
        );

        $this->events->dispatch(TokensCounted::from($count));

        return $count;
    }

    /**
     * Count plain text sent as a single user message to the model. Fires no events:
     * a loose string is not a request that gets sent.
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

        return $this->toTokenCount($this->counter->count($step), $model, null, provider: $step->provider);
    }

    /**
     * Report what a request counted with Headroom::for() actually used, as TokensUsed.
     * For a streamable response, the event fires once the stream has completed; a
     * stream abandoned part-way never reports usage.
     *
     * Usage is the first provider call of the run, the one that was counted. Use the
     * EnsureHeadroom middleware to report every step of a multi-step run.
     */
    public function recordUsage(TokenCount $count, AgentResponse|StreamableAgentResponse $response): void
    {
        if ($response instanceof StreamableAgentResponse) {
            $response->then(fn (StreamedAgentResponse $streamed) => $this->dispatchUsage($count, $streamed, streamed: true));

            return;
        }

        $this->dispatchUsage($count, $response, streamed: false);
    }

    /**
     * Tag every counted request with app context, e.g. fn (?Agent $agent) => ['tenant_id' => tenant()?->id].
     * Values must be scalars; anything else is dropped with a warning. If the
     * callback throws, the request carries no tags and a warning is logged.
     *
     * @param  callable(?Agent): array<string, scalar>  $resolver
     */
    public function resolveContextUsing(callable $resolver): static
    {
        $this->context->using($resolver);

        return $this;
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
     * @param  class-string|null  $agent
     * @param  array<string, scalar>  $context
     */
    public function toTokenCount(
        Tally $tally,
        string $model,
        ?int $maxOutputTokens,
        ?string $provider = null,
        ?string $agent = null,
        ?string $invocationId = null,
        array $context = [],
        ?string $requestId = null,
    ): TokenCount {
        return new TokenCount(
            total: $tally->total,
            breakdown: $tally->breakdown,
            method: $tally->method,
            confidence: $tally->confidence,
            model: $model,
            contextWindow: $this->models->get($model)->contextWindow,
            estimatedCost: $this->models->costFor($model, $tally->total, $maxOutputTokens),
            requestId: $requestId,
            provider: $provider,
            agent: $agent,
            invocationId: $invocationId,
            context: $context,
        );
    }

    private function dispatchUsage(TokenCount $count, AgentResponse $response, bool $streamed): void
    {
        /** @var Step|null $step */
        $step = $response->steps->first();

        $usage = $step === null ? $response->usage : $step->usage;
        $meta = $step === null ? $response->meta : $step->meta;
        $model = $meta->model ?? $count->model();

        // A provider can report a dated snapshot of the model; price it as the counted model if the registry does not know it.
        $pricedAs = $this->models->has($model) ? $model : $count->model();

        $this->events->dispatch(new TokensUsed(
            requestId: $count->requestId(),
            invocationId: $count->invocationId() ?? $response->invocationId,
            agent: $count->agent(),
            provider: $meta->provider ?? $count->provider(),
            model: $model,
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            cacheReadTokens: $usage->cacheReadInputTokens,
            cacheWriteTokens: $usage->cacheWriteInputTokens,
            cost: $this->models->actualCostFor($pricedAs, $usage->inputTokens, $usage->outputTokens, $usage->cacheReadInputTokens, $usage->cacheWriteInputTokens),
            streamed: $streamed,
            countMethod: $count->method(),
            estimatedInputTokens: $count->total(),
            context: $count->context(),
            occurredAt: CarbonImmutable::now(),
        ));
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
