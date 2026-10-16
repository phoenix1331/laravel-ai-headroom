<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Tests\Accuracy\Support;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * A tool defined inline, for the tool-heavy accuracy sample.
 */
final class CorpusTool implements Tool
{
    /**
     * @param  Closure(JsonSchema): array<string, mixed>  $schema
     */
    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly Closure $schema,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function handle(Request $request): string
    {
        return '';
    }

    public function schema(JsonSchema $schema): array
    {
        return ($this->schema)($schema);
    }
}
