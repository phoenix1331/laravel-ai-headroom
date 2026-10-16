<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Resolving;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use InvalidArgumentException;
use Laravel\Ai\AiManager;
use Laravel\Ai\Attributes\Model as ModelAttribute;
use Laravel\Ai\Attributes\Provider as ProviderAttribute;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Attributes\UseSmartestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasSkills;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Providers\SupportsCodeExecution;
use Laravel\Ai\Contracts\Providers\SupportsFileSearch;
use Laravel\Ai\Contracts\Providers\SupportsToolSearch;
use Laravel\Ai\Contracts\Providers\SupportsWebFetch;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\CodeExecution;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\LoadSkill;
use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\McpTool;
use ReflectionClass;

/**
 * Builds the first PendingStep laravel/ai would hand to agent middleware for a
 * prompt, without calling the model. Mirrors Promptable::prompt(),
 * GeneratesText::prompt() and the generation loop using public APIs only; a
 * parity test runs the real SDK against the same agents to keep them in step.
 */
class RequestResolver
{
    public function __construct(private readonly AiManager $ai) {}

    /**
     * @param  array<int, mixed>  $attachments  Extra attachments, as for $agent->prompt().
     * @param  Lab|array<array-key, mixed>|string|null  $provider  As for $agent->prompt(); a failover list resolves to its first provider.
     * @param  iterable<int, mixed>|null  $messages  History added with Promptable::withMessages(), which Headroom cannot read from the agent.
     * @param  iterable<int, mixed>|null  $tools  Tools set with Promptable::withTools(), which replace the declared tools.
     */
    public function resolve(
        Agent $agent,
        AgentInput|UserMessage|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?iterable $messages = null,
        ?iterable $tools = null,
    ): PendingStep {
        [$providerName, $model] = $this->providerAndModel($agent, $provider, $model);

        $textProvider = $this->ai->textProviderFor($agent, $providerName);
        $model ??= $this->defaultModel($agent, $textProvider);

        [$text, $attachments] = $this->promptInput($prompt, $attachments);

        $history = $this->withoutForeignReplayBlocks($textProvider, [
            ...array_map(Message::tryFrom(...), $messages === null ? [] : [...$messages]),
            ...($agent instanceof Conversational ? $agent->messages() : []),
        ]);

        $history[] = new UserMessage($text, $attachments);

        return new PendingStep(
            number: 0,
            isFinalStep: false,
            provider: $textProvider->name(),
            model: $model,
            instructions: (string) $agent->instructions(),
            messages: $this->settleAbandonedToolCalls($history),
            tools: $this->toolsSupportedBy($textProvider, array_map($this->resolveTool(...), $tools === null ? $this->declaredTools($agent) : [...$tools])),
            schema: $agent instanceof HasStructuredOutput ? $agent->schema(new JsonSchemaTypeFactory) : null,
            options: TextGenerationOptions::forAgent($agent)->forStep(0),
        );
    }

    /**
     * Promptable::getProvidersAndModels(): explicit arguments, then the agent's
     * provider()/model() methods or #[Provider]/#[Model] attributes, then ai.default.
     *
     * @param  Lab|array<array-key, mixed>|string|null  $provider
     * @return array{string, string|null}
     */
    private function providerAndModel(Agent $agent, Lab|array|string|null $provider, ?string $model): array
    {
        $reflection = new ReflectionClass($agent);

        if ($provider === null) {
            $provider = method_exists($agent, 'provider')
                ? $agent->provider()
                : ($reflection->getAttributes(ProviderAttribute::class)[0] ?? null)?->newInstance()->value;
        }

        if (! is_array($provider) && $model === null) {
            $model = method_exists($agent, 'model')
                ? $agent->model()
                : ($reflection->getAttributes(ModelAttribute::class)[0] ?? null)?->newInstance()->value;
        }

        $resolved = $provider ?? $this->ai->getDefaultInstance();

        if (! $resolved instanceof Lab && ! is_array($resolved) && ! is_string($resolved) && ! $resolved instanceof Provider) {
            throw new InvalidArgumentException('Headroom could not work out the provider for ['.$agent::class.'].');
        }

        $providers = Provider::formatProviderAndModelList($resolved, is_string($model) ? $model : null);

        $name = array_key_first($providers);

        if ($name === null) {
            throw new InvalidArgumentException('No AI providers were configured for ['.$agent::class.'].');
        }

        $first = $providers[$name];

        return [(string) $name, is_string($first) ? $first : null];
    }

    /**
     * Promptable::getDefaultModelFor().
     */
    private function defaultModel(Agent $agent, TextProvider $provider): string
    {
        $reflection = new ReflectionClass($agent);

        return match (true) {
            $reflection->getAttributes(UseSmartestModel::class) !== [] => $provider->smartestTextModel(),
            $reflection->getAttributes(UseCheapestModel::class) !== [] => $provider->cheapestTextModel(),
            default => $provider->defaultTextModel(),
        };
    }

    /**
     * Promptable::extractPromptInput(), without approval decisions (which resume a run rather than start one).
     *
     * @param  array<array-key, mixed>  $attachments
     * @return array{string, array<array-key, mixed>}
     */
    private function promptInput(AgentInput|UserMessage|string $prompt, array $attachments): array
    {
        if ($prompt instanceof AgentInput) {
            $prompt = $prompt->message()
                ?? throw new InvalidArgumentException('Headroom can only count agent input that contains a user message.');
        }

        if ($prompt instanceof UserMessage) {
            return [$prompt->content ?? '', [...$prompt->attachments->all(), ...$attachments]];
        }

        return [$prompt, $attachments];
    }

    /**
     * Promptable::declaredTools().
     *
     * @return array<int, mixed>
     */
    private function declaredTools(Agent $agent): array
    {
        $tools = $agent instanceof HasTools ? [...$agent->tools()] : [];

        return $agent instanceof HasSkills ? LoadSkill::mergeInto($tools, $agent) : $tools;
    }

    /**
     * GeneratesText::resolveTool().
     */
    private function resolveTool(mixed $tool): mixed
    {
        return match (true) {
            $tool instanceof Agent => new AgentTool($tool),
            $tool instanceof Tool => $tool,
            $tool instanceof ToolSearch => $tool->withTools(array_values(array_filter(
                array_map($this->resolveTool(...), $tool->tools),
                fn (mixed $nested): bool => $nested instanceof Tool,
            ))),
            is_object($tool) && McpTool::supports($tool) => new McpTool($tool),
            is_object($tool) && McpServerTool::supports($tool) => new McpServerTool($tool),
            default => $tool,
        };
    }

    /**
     * TextGenerationLoop::toolsSupportedBy().
     *
     * @param  array<int, mixed>  $tools
     * @return array<int, Tool|ProviderTool>
     */
    private function toolsSupportedBy(TextProvider $provider, array $tools): array
    {
        $supported = [];

        foreach ($tools as $tool) {
            array_push($supported, ...match (true) {
                $tool instanceof CodeExecution => $provider instanceof SupportsCodeExecution ? [$tool] : [],
                $tool instanceof FileSearch => $provider instanceof SupportsFileSearch ? [$tool] : [],
                $tool instanceof ToolSearch => $provider instanceof SupportsToolSearch ? [$tool] : $tool->tools,
                $tool instanceof WebFetch => $provider instanceof SupportsWebFetch ? [$tool] : [],
                $tool instanceof WebSearch => $provider instanceof SupportsWebSearch ? [$tool] : [],
                $tool instanceof ProviderTool => [],
                $tool instanceof Tool => [$tool],
                default => [],
            });
        }

        return $supported;
    }

    /**
     * ResumesToolApprovals::withoutForeignReplayBlocks(): replay blocks only go back to the provider that produced them.
     *
     * @param  array<array-key, Message>  $messages
     * @return array<int, Message>
     */
    private function withoutForeignReplayBlocks(TextProvider $provider, array $messages): array
    {
        return array_values(array_map(function (Message $message) use ($provider): Message {
            if ($message instanceof AssistantMessage
                && filled($message->replayBlocks)
                && $message->replayBlocksProvider !== null
                && $message->replayBlocksProvider !== $provider->name()) {
                return new AssistantMessage((string) $message->content, $message->toolCalls);
            }

            return $message;
        }, $messages));
    }

    /**
     * HandlesToolApprovals::settleAbandonedToolCalls(): tool calls with no result get a "not executed" result.
     *
     * @param  array<int, Message>  $messages
     * @return array<int, Message>
     */
    private function settleAbandonedToolCalls(array $messages): array
    {
        $resolved = [];

        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($message->toolResults as $result) {
                    /** @var ToolResult $result */
                    $resolved[$result->id] = true;
                }
            }
        }

        $output = [];

        for ($index = 0, $count = count($messages); $index < $count; $index++) {
            $message = $messages[$index];
            $output[] = $message;

            if (! $message instanceof AssistantMessage) {
                continue;
            }

            $dangling = $message->toolCalls->reject(fn (ToolCall $call): bool => isset($resolved[$call->id]))->values();

            if ($dangling->isEmpty()) {
                continue;
            }

            $placeholders = $dangling->map(fn (ToolCall $call): ToolResult => new ToolResult(
                $call->id,
                $call->name,
                $call->arguments,
                'This tool call was not executed because it was not approved before the conversation continued.',
                $call->resultId,
                denied: true,
            ));

            $next = $messages[$index + 1] ?? null;

            if ($next instanceof ToolResultMessage) {
                $output[] = new ToolResultMessage($next->toolResults->concat($placeholders)->values());
                $index++;

                continue;
            }

            $output[] = new ToolResultMessage($placeholders->values());
        }

        return $output;
    }
}
