<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\CodexContract;
use Symfony\AI\Platform\Message\AssistantMessage;

final class HistoryConversionTest extends TestCase
{
    public function testSameModelPreservesThinkingSignature(): void
    {
        $converter = new AgentMessageConverter();
        $messages = [
            new AgentMessage(
                role: 'assistant',
                content: [['type' => 'text', 'text' => 'Done']],
                details: [
                    'thinking' => 'secret plan',
                    'thinking_signature' => '{"type":"reasoning","id":"rs_1"}',
                ],
                metadata: ['source_model' => 'openai-codex/gpt-6-astra'],
            ),
        ];

        $bag = $converter->toMessageBagForTarget($messages, 'openai-codex/gpt-6-astra');
        $assistant = $bag->getMessages()[0];
        $this->assertInstanceOf(AssistantMessage::class, $assistant);
        $this->assertTrue($assistant->hasThinking());
        $this->assertSame('secret plan', $assistant->getThinking()[0]->getContent());
        $this->assertSame('{"type":"reasoning","id":"rs_1"}', $assistant->getThinking()[0]->getSignature());
    }

    public function testCrossModelConvertsThinkingToTextAndDropsSignature(): void
    {
        $converter = new AgentMessageConverter();
        $original = new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => 'Done']],
            details: [
                'thinking' => 'secret plan',
                'thinking_signature' => '{"type":"reasoning","id":"rs_1"}',
            ],
            metadata: [
                'source_model' => 'zai/glm-5.3-flash',
                'tool_calls' => [[
                    'id' => 'call_845adac454c64712b769d15b',
                    'name' => 'bash',
                    'arguments' => ['command' => 'pwd'],
                ]],
            ],
        );

        $converted = $converter->convertHistoryForTarget([$original], 'openai-codex/gpt-6-astra');
        $this->assertNull($converted[0]->details);
        $this->assertSame('Done', $converted[0]->content[0]['text']);
        $this->assertSame('secret plan', $converted[0]->content[1]['text']);
        $this->assertSame($original->metadata, $converted[0]->metadata);

        $payload = CodexContract::create()->createRequestPayload(
            new CodexModel('openai-codex/gpt-6-astra'),
            $converter->toMessageBagForTarget([$original], 'openai-codex/gpt-6-astra'),
            [],
        );

        $this->assertSame('message', $payload['input'][0]['type']);
        $this->assertSame("Done\nsecret plan", $payload['input'][0]['content'][0]['text']);
        $this->assertSame('function_call', $payload['input'][1]['type']);
        $this->assertSame('call_845adac454c64712b769d15b', $payload['input'][1]['call_id']);
        $this->assertArrayNotHasKey('id', $payload['input'][1]);
    }

    public function testCanonicalMessagesRemainUnchangedAfterConversion(): void
    {
        $converter = new AgentMessageConverter();
        $original = new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => 'hello']],
            details: [
                'thinking' => 'think',
                'thinking_signature' => '{"type":"reasoning","id":"rs_x"}',
            ],
            metadata: ['source_model' => 'zai/glm-5.3-flash'],
        );

        $snapshot = [
            'details' => $original->details,
            'content' => $original->content,
            'metadata' => $original->metadata,
        ];

        $converter->convertHistoryForTarget([$original], 'openai-codex/gpt-6-astra');

        $this->assertSame($snapshot['details'], $original->details);
        $this->assertSame($snapshot['content'], $original->content);
        $this->assertSame($snapshot['metadata'], $original->metadata);
    }

    public function testReverseCodexToCompletionsKeepsCallAssociation(): void
    {
        $converter = new AgentMessageConverter();
        $messages = [
            new AgentMessage(
                role: 'assistant',
                content: [],
                details: [
                    'thinking' => 'why',
                    'thinking_signature' => '{"type":"reasoning","id":"rs_keep"}',
                ],
                metadata: [
                    'source_model' => 'openai-codex/gpt-6-astra',
                    'tool_calls' => [[
                        'id' => 'call_native|fc_native',
                        'name' => 'bash',
                        'arguments' => ['command' => 'ls'],
                    ]],
                ],
            ),
            new AgentMessage(
                role: 'tool',
                content: [['type' => 'text', 'text' => 'ok']],
                toolCallId: 'call_native|fc_native',
                toolName: 'bash',
            ),
        ];

        $bag = $converter->toMessageBagForTarget($messages, 'zai/glm-5.3-flash');
        $assistant = $bag->getMessages()[0];
        $this->assertInstanceOf(AssistantMessage::class, $assistant);
        $this->assertFalse($assistant->hasThinking());
        $this->assertSame('why', $assistant->asText());
        $this->assertSame('call_native|fc_native', $assistant->getToolCalls()[0]->getId());
        $this->assertSame('call_native|fc_native', $bag->getMessages()[1]->getToolCall()->getId());
    }
}
