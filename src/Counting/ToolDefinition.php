<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Counting;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Tools\ToolNameResolver;

/**
 * A provider-neutral description of a tool: what estimates tokenise and what
 * the native count cache fingerprints.
 */
final class ToolDefinition
{
    /**
     * @return array<string, mixed>
     */
    public static function of(Tool|ProviderTool $tool): array
    {
        if ($tool instanceof ProviderTool) {
            return ['name' => ToolNameResolver::resolve($tool)];
        }

        return [
            'name' => ToolNameResolver::resolve($tool),
            'description' => (string) $tool->description(),
            'parameters' => (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory)))->toSchema(),
        ];
    }
}
