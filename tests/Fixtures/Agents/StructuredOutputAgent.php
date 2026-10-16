<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Tests\Fixtures\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

#[MaxTokens(2_000)]
#[Temperature(0.2)]
class StructuredOutputAgent implements Agent, HasMiddleware, HasStructuredOutput
{
    use CapturesSteps;
    use Promptable;

    public function instructions(): string
    {
        return 'Classify the customer message.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string()->enum(['refund', 'delivery', 'other'])->required(),
            'urgent' => $schema->boolean()->required(),
        ];
    }
}
