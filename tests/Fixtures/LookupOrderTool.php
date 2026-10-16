<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Tests\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class LookupOrderTool implements Tool
{
    public function description(): string
    {
        return 'Look up an order by reference';
    }

    public function handle(Request $request): string
    {
        return 'shipped';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['reference' => $schema->string()->required()];
    }
}
