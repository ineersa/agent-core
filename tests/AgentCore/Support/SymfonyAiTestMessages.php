<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Result\ToolCall;

final class SymfonyAiTestMessages
{
    public static function assistantText(string $text): AssistantMessage
    {
        return new AssistantMessage(new Text($text));
    }

    /**
     * @param list<array{id: string, name: string, arguments?: array<string, mixed>}> $toolCalls
     */
    public static function assistantWithToolCalls(array $toolCalls, ?string $content = null): AssistantMessage
    {
        $parts = [];

        if (null !== $content && '' !== $content) {
            $parts[] = new Text($content);
        }

        foreach (array_map(
            static fn (array $toolCall): ToolCall => new ToolCall(
                $toolCall['id'],
                $toolCall['name'],
                $toolCall['arguments'] ?? [],
            ),
            $toolCalls,
        ) as $toolCall) {
            $parts[] = $toolCall;
        }

        return new AssistantMessage(...$parts);
    }
}
