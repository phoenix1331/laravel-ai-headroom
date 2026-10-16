<?php

declare(strict_types=1);

use Phoenix1331\AiHeadroom\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Feature', 'Accuracy');

/**
 * A recorded provider response from tests/Fixtures/Responses.
 *
 * @return array<string, mixed>
 */
function responseFixture(string $path): array
{
    return json_decode((string) file_get_contents(__DIR__.'/Fixtures/Responses/'.$path), true);
}
