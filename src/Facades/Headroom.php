<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Facades;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Laravel\Ai\PendingStep;
use Phoenix1331\AiHeadroom\Counting\Contracts\Counter;
use Phoenix1331\AiHeadroom\Counting\EstimatingCounter;
use Phoenix1331\AiHeadroom\Data\Tally;
use Phoenix1331\AiHeadroom\HeadroomManager;
use Phoenix1331\AiHeadroom\Testing\HeadroomFake;
use RuntimeException;

/**
 * @method static \Phoenix1331\AiHeadroom\Data\TokenCount for(\Laravel\Ai\Contracts\Agent $agent, \Laravel\Ai\Contracts\AgentInput|\Laravel\Ai\Messages\UserMessage|string $prompt, array<int, mixed> $attachments = [], \Laravel\Ai\Enums\Lab|array<array-key, mixed>|string|null $provider = null, ?string $model = null, ?iterable<int, mixed> $messages = null, ?iterable<int, mixed> $tools = null)
 * @method static \Phoenix1331\AiHeadroom\Data\TokenCount count(string $text, ?string $model = null)
 * @method static void recordUsage(\Phoenix1331\AiHeadroom\Data\TokenCount $count, \Laravel\Ai\Responses\AgentResponse|\Laravel\Ai\Responses\StreamableAgentResponse $response)
 * @method static \Phoenix1331\AiHeadroom\HeadroomManager resolveContextUsing(callable $resolver)
 * @method static string defaultModel()
 *
 * @see HeadroomManager
 */
class Headroom extends Facade
{
    /**
     * Fake token counts for tests, e.g. ['*' => 1200], [SupportAgent::class => 900_000] or
     * ['claude-*' => ['history' => 5_000, 'prompt' => 200]]. Limits, events and the
     * middleware still run against the faked totals.
     *
     * @param  array<string, int|array<string, int>>  $totals
     */
    public static function fake(array $totals = []): HeadroomFake
    {
        $app = Container::getInstance();

        $fake = new HeadroomFake($app->make(EstimatingCounter::class), $totals);

        $app->instance(HeadroomFake::class, $fake);
        $app->instance(Counter::class, $fake);
        $app->forgetInstance(HeadroomManager::class);
        static::clearResolvedInstance(HeadroomManager::class);

        return $fake;
    }

    /**
     * @param  (Closure(PendingStep, Tally): bool)|null  $callback
     */
    public static function assertCounted(string $agentOrModel, ?Closure $callback = null): void
    {
        self::fakeInstance()->assertCounted($agentOrModel, $callback);
    }

    public static function assertNotCounted(string $agentOrModel): void
    {
        self::fakeInstance()->assertNotCounted($agentOrModel);
    }

    public static function assertCountedTimes(string $agentOrModel, int $times): void
    {
        self::fakeInstance()->assertCountedTimes($agentOrModel, $times);
    }

    public static function assertNothingCounted(): void
    {
        self::fakeInstance()->assertNothingCounted();
    }

    protected static function getFacadeAccessor(): string
    {
        return HeadroomManager::class;
    }

    private static function fakeInstance(): HeadroomFake
    {
        $app = Container::getInstance();
        $fake = $app->bound(HeadroomFake::class) ? $app->make(HeadroomFake::class) : null;

        if (! $fake instanceof HeadroomFake) {
            throw new RuntimeException('Call Headroom::fake() before using Headroom assertions.');
        }

        return $fake;
    }
}
