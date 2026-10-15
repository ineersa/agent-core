<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\History;

use Ineersa\CodingAgent\Session\History\HistoryAssistantTextExtractor;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\MessageItem;

final class HistoryAssistantTextExtractorTest extends TestCase
{
    public function testPlainCompletedAnswerExcludesReasoning(): void
    {
        $payload = ['assistant_message' => ['role' => 'assistant', 'content' => [
            ['type' => 'thinking', 'text' => 'Private summary'],
            ['type' => 'text', 'text' => 'Final answer'],
        ]]];
        $this->assertSame('Final answer', HistoryAssistantTextExtractor::extract($payload));
        $payload['assistant_message']['tool_calls'] = [['id' => 'read', 'name' => 'read', 'arguments' => []]];
        $this->assertSame('', HistoryAssistantTextExtractor::extract($payload), 'Tool-step text is not a final answer.');
    }

    public function testNativePhasesExcludeCommentaryWithoutReadingLabels(): void
    {
        $parts = [];
        foreach (['commentary' => 'The final answer is being checked', 'final_answer' => 'I will investigate'] as $phase => $text) {
            $signed = MessageItem::toText(['id' => 'msg_'.$phase, 'type' => 'message', 'role' => 'assistant', 'phase' => $phase, 'content' => [['type' => 'output_text', 'text' => $text]]]);
            $parts[] = ['type' => 'text', 'text' => $text, 'text_signature' => $signed->getSignature()];
        }
        $this->assertSame('I will investigate', HistoryAssistantTextExtractor::extract(['assistant_message' => ['role' => 'assistant', 'content' => $parts]]));
        $this->assertSame('', HistoryAssistantTextExtractor::extract(['assistant_message' => ['role' => 'assistant', 'content' => [$parts[0]]]]));
        $this->assertSame('', HistoryAssistantTextExtractor::extract(['assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'thinking', 'text' => 'No answer']]]]));
    }
}
