<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Events;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * A counted provider call threw. Carries the exception class and HTTP status
 * only, never the message: provider errors can echo prompt content. Public API
 * covered by SemVer.
 */
final readonly class RequestFailed
{
    /**
     * @param  class-string<Throwable>  $exception
     */
    public function __construct(
        public string $requestId,
        public ?string $invocationId,
        public ?string $provider,
        public string $model,
        public string $exception,
        public ?int $httpStatus,
        public CarbonImmutable $occurredAt,
    ) {}

    public static function from(string $requestId, ?string $invocationId, ?string $provider, string $model, Throwable $exception): self
    {
        return new self(
            requestId: $requestId,
            invocationId: $invocationId,
            provider: $provider,
            model: $model,
            exception: $exception::class,
            httpStatus: self::httpStatus($exception),
            occurredAt: CarbonImmutable::now(),
        );
    }

    /**
     * The HTTP status from the exception or anything it wraps.
     */
    private static function httpStatus(Throwable $exception): ?int
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RequestException) {
                return $current->response->status();
            }
        }

        return null;
    }
}
