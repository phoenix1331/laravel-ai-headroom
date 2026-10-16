<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

/**
 * History with a partly answered tool turn, a tool call that was never
 * answered, and replay blocks from another provider: the cases the SDK rewrites.
 */
class ConversationAgent implements Agent, Conversational, HasMiddleware
{
    use CapturesSteps;
    use Promptable;

    public function instructions(): string
    {
        return 'You are a support agent.';
    }

    public function messages(): iterable
    {
        return [
            new UserMessage('Has order AB-1 shipped?'),
            new AssistantMessage('Let me check.', collect([
                new ToolCall('call_1', 'lookup_order', ['reference' => 'AB-1']),
                new ToolCall('call_1b', 'lookup_order', ['reference' => 'AB-1B']),
            ])),
            new ToolResultMessage(collect([new ToolResult('call_1', 'lookup_order', ['reference' => 'AB-1'], 'shipped')])),
            new AssistantMessage('Yes, it shipped on Monday.', null, [['type' => 'thought', 'signature' => 'abc']], 'gemini'),
            new UserMessage('And AB-2?'),
            new AssistantMessage('Checking that too.', collect([new ToolCall('call_2', 'lookup_order', ['reference' => 'AB-2'])])),
        ];
    }
}
