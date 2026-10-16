<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Phoenix1331\AiHeadroom\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Feature');

// The accuracy suite compares against real provider counts, so it alone may make HTTP requests.
pest()->extend(TestCase::class)
    ->beforeEach(fn () => Http::allowStrayRequests())
    ->in('Accuracy');

/**
 * A recorded provider response from tests/Fixtures/Responses.
 *
 * @return array<string, mixed>
 */
function responseFixture(string $path): array
{
    return json_decode((string) file_get_contents(__DIR__.'/Fixtures/Responses/'.$path), true);
}
