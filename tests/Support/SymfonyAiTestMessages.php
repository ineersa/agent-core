<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Result\ToolCall;

final class SymfonyAiTestMessages
{
    public static function assistantText(string $text): AssistantMessage
    {
        return new AssistantMessage(content: $text);
    }

    /**
     * @param list<array{id: string, name: string, arguments?: array<string, mixed>}> $toolCalls
     */
    public static function assistantWithToolCalls(array $toolCalls, ?string $content = null): AssistantMessage
    {
        return new AssistantMessage(
            content: $content,
            toolCalls: array_map(
                static fn (array $toolCall): ToolCall => new ToolCall(
                    $toolCall['id'],
                    $toolCall['name'],
                    $toolCall['arguments'] ?? [],
                ),
                $toolCalls,
            ),
        );
    }
}
