<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ConversationHistoryConversion;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\CodexContract;
use Symfony\AI\Platform\Message\AssistantMessage;

final class AgentMessageConverterTest extends TestCase
{
    private AgentMessageConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new AgentMessageConverter();
    }

    /**
     * Subject: thinking-only assistant messages must NOT enter the
     * provider request MessageBag. These were erroneously persisted
     * before ExecuteLlmStepWorker started converting reasoning-only
     * responses to errors, and replaying them causes provider 400
     * "content or tool_calls must be set".
     */
    public function testThinkingOnlyAssistantFilteredFromMessageBag(): void
    {
        $thinkingOnlyMessage = new AgentMessage(
            role: 'assistant',
            content: [],
            details: [
                'thinking' => 'The user wants me to do something...',
            ],
            metadata: [],
        );

        $bag = $this->converter->toMessageBag([$thinkingOnlyMessage]);

        $this->assertCount(
            0,
            $bag->getMessages(),
            'Thinking-only assistant message must be filtered from provider request.',
        );
    }

    /**
     * Subject: text-bearing assistant messages must still be converted.
     */
    public function testTextAssistantMessageStillConverted(): void
    {
        $textMessage = new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => 'Hello, how can I help?']],
            metadata: [],
        );

        $bag = $this->converter->toMessageBag([$textMessage]);

        $this->assertCount(
            1,
            $bag->getMessages(),
            'Text-bearing assistant message must be converted.',
        );

        $this->assertSame(
            'Hello, how can I help?',
            $bag->getMessages()[0]->asText(),
        );
    }

    /**
     * Subject: tool-call-only assistant messages (no text, but tool_calls
     * in metadata) must still be converted. These are valid assistant
     * responses that instruct the system to run tools.
     */
    public function testToolCallOnlyAssistantMessageStillConverted(): void
    {
        $toolCallMessage = new AgentMessage(
            role: 'assistant',
            content: [],
            metadata: [
                'tool_calls' => [
                    [
                        'id' => 'call-1',
                        'name' => 'search',
                        'arguments' => ['query' => 'test'],
                    ],
                ],
            ],
        );

        $bag = $this->converter->toMessageBag([$toolCallMessage]);

        $this->assertCount(
            1,
            $bag->getMessages(),
            'Tool-call-only assistant message must be converted.',
        );

        $this->assertTrue(
            $bag->getMessages()[0]->hasToolCalls(),
            'Converted message must carry tool calls.',
        );
    }

    /**
     * Subject: thinking-only messages target the assistant role only.
     * User, system, and tool messages with thinking in details must
     * still be converted (thinking is ignored for non-assistant roles).
     */
    public function testOnlyAssistantRoleIsFiltered(): void
    {
        $userWithThinking = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => 'Regular user message']],
            details: ['thinking' => 'should not matter for user role'],
            metadata: [],
        );

        $bag = $this->converter->toMessageBag([$userWithThinking]);

        $this->assertCount(
            1,
            $bag->getMessages(),
            'User messages with thinking in details must still be converted.',
        );
    }

    /**
     * Subject: mixed message bags. A thinking-only assistant message
     * is filtered but surrounding valid messages pass through.
     */
    public function testMixedBagFiltersOnlyInvalidAssistant(): void
    {
        $messages = [
            new AgentMessage(
                role: 'system',
                content: [['type' => 'text', 'text' => 'System prompt']],
                metadata: [],
            ),
            new AgentMessage(
                role: 'user',
                content: [['type' => 'text', 'text' => 'Hello']],
                metadata: [],
            ),
            new AgentMessage(
                role: 'assistant',
                content: [],
                details: ['thinking' => 'I should respond...'],
                metadata: [],
            ),
            new AgentMessage(
                role: 'assistant',
                content: [['type' => 'text', 'text' => 'Actual response']],
                metadata: [],
            ),
        ];

        $bag = $this->converter->toMessageBag($messages);

        $this->assertCount(
            3,
            $bag->getMessages(),
            'Thinking-only assistant must be filtered; system, user, and text assistant pass through.',
        );
    }

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
        $this->assertTrue($assistant->getMetadata()->get(ConversationHistoryConversion::METADATA_PRESERVE_NATIVE_ITEM_IDS));
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
        $this->assertSame($original->metadata['tool_calls'], $converted[0]->metadata['tool_calls']);
        $this->assertFalse($converted[0]->metadata['preserve_native_item_ids']);

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
        $remapped = $assistant->getToolCalls()[0]->getId();
        $this->assertDoesNotMatchRegularExpression('/[|]/', $remapped);
        $this->assertLessThanOrEqual(40, \strlen($remapped));
        $this->assertSame($remapped, $bag->getMessages()[1]->getToolCall()->getId());
    }

    public function testQualifiedModelIdentityControlsNativeReplayWithBareCatalogModel(): void
    {
        $converter = new AgentMessageConverter();
        $messages = [
            new AgentMessage(
                role: 'assistant',
                content: [['type' => 'text', 'text' => 'Calling']],
                details: ['thinking' => 'plan', 'thinking_signature' => '{"type":"reasoning","id":"rs_native","encrypted_content":"opaque"}'],
                metadata: [
                    'source_model' => 'custom-codex/model-a',
                    'tool_calls' => [['id' => 'call_native|fc_native', 'name' => 'bash', 'arguments' => ['command' => 'pwd']]],
                ],
            ),
            new AgentMessage(role: 'tool', content: [['type' => 'text', 'text' => 'ok']], toolCallId: 'call_native|fc_native', toolName: 'bash'),
        ];

        $same = CodexContract::create()->createRequestPayload(new CodexModel('model-a'), $converter->toMessageBagForTarget($messages, 'custom-codex/model-a'), []);
        $this->assertSame('reasoning', $same['input'][0]['type']);
        $this->assertSame('fc_native', $same['input'][2]['id']);
        $this->assertSame($same['input'][2]['call_id'], $same['input'][3]['call_id']);

        foreach (['custom-codex/model-b', 'other-codex/model-a'] as $target) {
            $changed = CodexContract::create()->createRequestPayload(new CodexModel('model-a'), $converter->toMessageBagForTarget($messages, $target), []);
            $this->assertSame('message', $changed['input'][0]['type']);
            $this->assertStringContainsString('plan', $changed['input'][0]['content'][0]['text']);
            $this->assertArrayNotHasKey('id', $changed['input'][1]);
            $this->assertSame($changed['input'][1]['call_id'], $changed['input'][2]['call_id']);
        }
    }

    public function testConversionResolvesSanitizedIdCollisionsForCompletions(): void
    {
        $ids = ['call/a', 'call_a', 'call|a', str_repeat('x', 90)];
        $calls = array_map(static fn (string $id): array => ['id' => $id, 'name' => 'bash', 'arguments' => []], $ids);
        $messages = [new AgentMessage(role: 'assistant', content: [], metadata: ['source_model' => 'codex/a', 'tool_calls' => $calls])];
        foreach ($ids as $id) {
            $messages[] = new AgentMessage(role: 'tool', content: [['type' => 'text', 'text' => 'ok']], toolCallId: $id, toolName: 'bash');
        }
        $bag = (new AgentMessageConverter())->toMessageBagForTarget($messages, 'openai/b');
        $requestIds = [];
        foreach ($bag->getMessages()[0]->getToolCalls() as $index => $call) {
            $requestIds[] = $call->getId();
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]{1,40}$/', $call->getId());
            $this->assertSame($call->getId(), $bag->getMessages()[$index + 1]->getToolCall()->getId());
        }
        $this->assertCount(4, array_unique($requestIds));
    }
}
