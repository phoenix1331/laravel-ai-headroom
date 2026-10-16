<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Context;

use Closure;
use Laravel\Ai\Contracts\Agent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the app's context tags (e.g. user_id, tenant_id) for a request.
 * Tagging never fails a request: if the callback throws, or returns anything
 * other than scalars keyed by name, Headroom logs a warning and drops the bad
 * tags. Warnings name the exception class only, never its message.
 */
class ContextResolver
{
    /** @var (Closure(?Agent): mixed)|null */
    private ?Closure $resolver = null;

    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * @param  callable(?Agent): array<string, scalar>  $resolver
     */
    public function using(callable $resolver): void
    {
        $this->resolver = $resolver(...);
    }

    /**
     * @return array<string, scalar>
     */
    public function resolve(?Agent $agent): array
    {
        if ($this->resolver === null) {
            return [];
        }

        try {
            $tags = ($this->resolver)($agent);
        } catch (Throwable $exception) {
            $this->logger->warning('Headroom context resolver threw; continuing without context tags.', ['exception' => $exception::class]);

            return [];
        }

        if (! is_array($tags)) {
            $this->logger->warning('Headroom context resolver must return an array; continuing without context tags.', ['returned' => get_debug_type($tags)]);

            return [];
        }

        $valid = [];
        $rejected = [];

        foreach ($tags as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $valid[$key] = $value;

                continue;
            }

            $rejected[] = (string) $key;
        }

        if ($rejected !== []) {
            $this->logger->warning('Headroom context tags must be scalars keyed by name; dropped the others.', ['dropped' => $rejected]);
        }

        return $valid;
    }
}
