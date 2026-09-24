<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ConversationHistoryConversion;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\CodexContract;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Contracts\Service\ResetInterface;

final class AgentMessageConverterTest extends IsolatedKernelTestCase
{
    private AgentMessageConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
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

        $bag = $this->converter->toMessageBagForTarget($messages, 'openai-codex/gpt-6-astra');
        $assistant = $bag->getMessages()[0];
        $this->assertInstanceOf(AssistantMessage::class, $assistant);
        $this->assertTrue($assistant->getMetadata()->get(ConversationHistoryConversion::METADATA_PRESERVE_NATIVE_ITEM_IDS));
        $this->assertTrue($assistant->hasThinking());
        $this->assertSame('secret plan', $assistant->getThinking()[0]->getContent());
        $this->assertSame('{"type":"reasoning","id":"rs_1"}', $assistant->getThinking()[0]->getSignature());
    }

    public function testCrossModelConvertsThinkingToTextAndDropsSignature(): void
    {
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

        $payload = CodexContract::create()->createRequestPayload(
            new CodexModel('openai-codex/gpt-6-astra'),
            $this->converter->toMessageBagForTarget([$original], 'openai-codex/gpt-6-astra'),
            [],
        );

        $this->assertSame('message', $payload['input'][0]['type']);
        $this->assertSame("Done\nsecret plan", $payload['input'][0]['content'][0]['text']);
        $this->assertSame('function_call', $payload['input'][1]['type']);
        $this->assertSame('call_845adac454c64712b769d15b', $payload['input'][1]['call_id']);
        $this->assertArrayNotHasKey('id', $payload['input'][1]);
        $this->assertFalse(
            $this->converter->toMessageBagForTarget([$original], 'openai-codex/gpt-6-astra')
                ->getMessages()[0]
                ->getMetadata()
                ->get(ConversationHistoryConversion::METADATA_PRESERVE_NATIVE_ITEM_IDS),
        );
        $this->assertSame($original->metadata['tool_calls'], $original->metadata['tool_calls']);
        $this->assertSame('secret plan', $original->details['thinking']);
    }

    public function testCanonicalMessagesRemainUnchangedAfterConversion(): void
    {
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

        $this->converter->toMessageBagForTarget([$original], 'openai-codex/gpt-6-astra');

        $this->assertSame($snapshot['details'], $original->details);
        $this->assertSame($snapshot['content'], $original->content);
        $this->assertSame($snapshot['metadata'], $original->metadata);
    }

    public function testReverseCodexToCompletionsKeepsCallAssociation(): void
    {
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

        $bag = $this->converter->toMessageBagForTarget($messages, 'zai/glm-5.3-flash');
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

        $same = CodexContract::create()->createRequestPayload(
            new CodexModel('model-a'),
            $this->converter->toMessageBagForTarget($messages, 'custom-codex/model-a'),
            [],
        );
        $this->assertSame('reasoning', $same['input'][0]['type']);
        $this->assertSame('fc_native', $same['input'][2]['id']);
        $this->assertSame($same['input'][2]['call_id'], $same['input'][3]['call_id']);

        foreach (['custom-codex/model-b', 'other-codex/model-a'] as $target) {
            $changed = CodexContract::create()->createRequestPayload(
                new CodexModel('model-a'),
                $this->converter->toMessageBagForTarget($messages, $target),
                [],
            );
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
        $bag = $this->converter->toMessageBagForTarget($messages, 'openai/b');
        $requestIds = [];
        foreach ($bag->getMessages()[0]->getToolCalls() as $index => $call) {
            $requestIds[] = $call->getId();
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]{1,40}$/', $call->getId());
            $this->assertSame($call->getId(), $bag->getMessages()[$index + 1]->getToolCall()->getId());
        }
        $this->assertCount(4, array_unique($requestIds));
    }

    public function testMultipleReasoningSignaturesSurviveOnlySameModelReplay(): void
    {
        $assistant = new AgentMessage(
            role: 'assistant',
            content: [
                ['type' => 'thinking', 'thinking_signature' => '{"type":"reasoning","id":"rs_first","encrypted_content":"first"}'],
                ['type' => 'text', 'text' => 'answer'],
                ['type' => 'thinking', 'thinking_signature' => '{"type":"reasoning","id":"rs_second","encrypted_content":"second"}'],
            ],
            details: [
                'thinking' => 'plan',
            ],
            metadata: ['source_model' => 'openai-codex/model-a'],
        );

        $same = $this->converter->toMessageBagForTarget([$assistant], 'openai-codex/model-a');
        $this->assertCount(2, $same->getMessages()[0]->getThinking());
        $parts = $same->getMessages()[0]->getContent();
        $this->assertInstanceOf(\Symfony\AI\Platform\Message\Content\Thinking::class, $parts[0]);
        $this->assertInstanceOf(\Symfony\AI\Platform\Message\Content\Text::class, $parts[1]);
        $this->assertInstanceOf(\Symfony\AI\Platform\Message\Content\Thinking::class, $parts[2]);
        $changed = $this->converter->toMessageBagForTarget([$assistant], 'openai-codex/model-b');
        $this->assertFalse($changed->getMessages()[0]->hasThinking());
        $this->assertStringContainsString('plan', $changed->getMessages()[0]->asText());
        $this->assertStringContainsString('answer', $changed->getMessages()[0]->asText());
    }

    public function testInvalidLeadingReasoningSignatureDoesNotDiscardThinkingText(): void
    {
        $assistant = new AgentMessage(
            role: 'assistant',
            content: [
                ['type' => 'thinking'],
                ['type' => 'text', 'text' => 'answer'],
                ['type' => 'thinking', 'thinking_signature' => '{"type":"reasoning","id":"rs_valid"}'],
            ],
            details: ['thinking' => 'plan'],
            metadata: ['source_model' => 'openai-codex/model-a'],
        );

        $bag = $this->converter->toMessageBagForTarget([$assistant], 'openai-codex/model-a');
        $this->assertCount(1, $bag->getMessages()[0]->getThinking());
        $this->assertSame('plan', $bag->getMessages()[0]->getThinking()[0]->getContent());
    }

    public function testExactContextReuseWorksWithFreshlyDeserializedEqualMessages(): void
    {
        $original = [
            new AgentMessage(
                role: 'user',
                content: [['type' => 'text', 'text' => 'hello']],
                timestamp: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            ),
            new AgentMessage(
                role: 'assistant',
                content: [['type' => 'text', 'text' => 'world']],
                details: ['thinking' => 'plan', 'thinking_signature' => '{"type":"reasoning","id":"rs_1"}'],
                metadata: [
                    'source_model' => 'openai-codex/gpt-6-astra',
                    'tool_calls' => [['id' => 'call_1|fc_1', 'name' => 'bash', 'arguments' => ['command' => 'pwd']]],
                ],
            ),
            new AgentMessage(
                role: 'tool',
                content: [['type' => 'text', 'text' => 'ok']],
                toolCallId: 'call_1|fc_1',
                toolName: 'bash',
            ),
        ];

        $first = $this->converter->toMessageBagForTarget($original, 'openai-codex/gpt-6-astra');
        $fresh = array_map(
            static fn (AgentMessage $message): AgentMessage => AgentMessage::fromPayload($message->toArray()) ?? throw new \RuntimeException('payload round-trip failed'),
            $original,
        );
        $this->assertNotSame($original[0], $fresh[0]);
        $second = $this->converter->toMessageBagForTarget($fresh, 'openai-codex/gpt-6-astra');

        $this->assertSame($first, $second);
        $this->assertSame(
            CodexContract::create()->createRequestPayload(new CodexModel('gpt-6-astra'), $first, []),
            CodexContract::create()->createRequestPayload(new CodexModel('gpt-6-astra'), $second, []),
        );
    }

    public function testAppendConvertsOnlySuffixAndKeepsToolIdMap(): void
    {
        $assistant = new AgentMessage(
            role: 'assistant',
            content: [],
            metadata: [
                'source_model' => 'openai-codex/gpt-6-astra',
                'tool_calls' => [[
                    'id' => 'call_native|fc_native',
                    'name' => 'bash',
                    'arguments' => ['command' => 'ls'],
                ]],
            ],
        );
        $prefix = [$assistant];
        $first = $this->converter->toMessageBagForTarget($prefix, 'zai/glm-5.3-flash');
        $remapped = $first->getMessages()[0]->getToolCalls()[0]->getId();

        $withResult = [
            $assistant,
            new AgentMessage(
                role: 'tool',
                content: [['type' => 'text', 'text' => 'ok']],
                toolCallId: 'call_native|fc_native',
                toolName: 'bash',
            ),
            new AgentMessage(
                role: 'user',
                content: [['type' => 'text', 'text' => 'continue']],
            ),
        ];
        $second = $this->converter->toMessageBagForTarget($withResult, 'zai/glm-5.3-flash');

        $this->assertSame($remapped, $second->getMessages()[0]->getToolCalls()[0]->getId());
        $this->assertSame($remapped, $second->getMessages()[1]->getToolCall()->getId());
        $this->assertSame('continue', $second->getMessages()[2]->asText());
    }

    public function testAppendReusesUnchangedPrefixSymfonyMessageInstances(): void
    {
        $prefix = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'hello']]),
            new AgentMessage(
                role: 'assistant',
                content: [['type' => 'text', 'text' => 'hi']],
                metadata: ['source_model' => 'openai/gpt-test'],
            ),
        ];
        $first = $this->converter->toMessageBagForTarget($prefix, 'openai/gpt-test');
        $prefixUser = $first->getMessages()[0];
        $prefixAssistant = $first->getMessages()[1];

        $appended = [
            ...$prefix,
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'next']]),
        ];
        $second = $this->converter->toMessageBagForTarget($appended, 'openai/gpt-test');

        $this->assertSame($first, $second);
        $this->assertSame($prefixUser, $second->getMessages()[0]);
        $this->assertSame($prefixAssistant, $second->getMessages()[1]);
        $this->assertSame('next', $second->getMessages()[2]->asText());
        $this->assertCount(3, $second->getMessages());
    }

    public function testAppendRebuildsOnlyTrailingIncompleteToolBatch(): void
    {
        $tmp = TestDirectoryIsolation::createProjectTempDir('history-conversion');

        $imagePath = $tmp.'/pixel.png';
        file_put_contents(
            $imagePath,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true),
        );

        try {
            $stable = [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'start']]),
                new AgentMessage(
                    role: 'assistant',
                    content: [['type' => 'text', 'text' => 'stable']],
                    metadata: ['source_model' => 'vision/model'],
                ),
            ];
            $openBatch = [
                ...$stable,
                new AgentMessage(
                    role: 'assistant',
                    content: [],
                    metadata: [
                        'source_model' => 'vision/model',
                        'tool_calls' => [
                            ['id' => 'call_a', 'name' => 'view_image', 'arguments' => []],
                            ['id' => 'call_b', 'name' => 'view_image', 'arguments' => []],
                        ],
                    ],
                ),
                new AgentMessage(
                    role: 'tool',
                    content: [
                        ['type' => 'text', 'text' => 'a'],
                        ['type' => 'image_ref', 'path' => $imagePath, 'media_type' => 'image/png', 'bytes' => 68, 'width' => 1, 'height' => 1],
                    ],
                    toolCallId: 'call_a',
                    toolName: 'view_image',
                ),
            ];

            $first = $this->converter->toMessageBagForTarget($openBatch, 'vision/model');
            $stableAssistant = $first->getMessages()[1];
            $this->assertInstanceOf(ToolCallMessage::class, $first->getMessages()[3]);
            $this->assertTrue($first->getMessages()[4]->hasImageContent());

            $completed = [
                ...$openBatch,
                new AgentMessage(
                    role: 'tool',
                    content: [
                        ['type' => 'text', 'text' => 'b'],
                        ['type' => 'image_ref', 'path' => $imagePath, 'media_type' => 'image/png', 'bytes' => 68, 'width' => 1, 'height' => 1],
                    ],
                    toolCallId: 'call_b',
                    toolName: 'view_image',
                ),
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'done']]),
            ];
            $second = $this->converter->toMessageBagForTarget($completed, 'vision/model');

            $this->assertSame($stableAssistant, $second->getMessages()[1]);
            $this->assertNotSame($first->getMessages()[3], $second->getMessages()[3]);
            $this->assertInstanceOf(ToolCallMessage::class, $second->getMessages()[3]);
            $this->assertInstanceOf(ToolCallMessage::class, $second->getMessages()[4]);
            $this->assertTrue($second->getMessages()[5]->hasImageContent());
            $this->assertTrue($second->getMessages()[6]->hasImageContent());
            $this->assertSame('done', $second->getMessages()[7]->asText());
        } finally {
            TestDirectoryIsolation::removeDirectory($tmp);
        }
    }

    public function testModelChangeAndEditedPrefixInvalidateProjection(): void
    {
        $messages = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'prompt']]),
            new AgentMessage(
                role: 'assistant',
                content: [['type' => 'text', 'text' => 'answer']],
                details: ['thinking' => 'secret', 'thinking_signature' => '{"type":"reasoning","id":"rs_1"}'],
                metadata: ['source_model' => 'openai-codex/gpt-6-astra'],
            ),
        ];

        $sameModel = $this->converter->toMessageBagForTarget($messages, 'openai-codex/gpt-6-astra');
        $this->assertTrue($sameModel->getMessages()[1]->hasThinking());

        $crossModel = $this->converter->toMessageBagForTarget($messages, 'zai/glm-5.3-flash');
        $this->assertFalse($crossModel->getMessages()[1]->hasThinking());
        $this->assertSame("answer\nsecret", $crossModel->getMessages()[1]->asText());

        $edited = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'prompt changed']]),
            $messages[1],
        ];
        $rebuilt = $this->converter->toMessageBagForTarget($edited, 'zai/glm-5.3-flash');
        $this->assertSame('prompt changed', $rebuilt->getMessages()[0]->asText());
        $this->assertSame("answer\nsecret", $rebuilt->getMessages()[1]->asText());
    }

    public function testSameTargetExactContextReturnsSameActiveBagIdentity(): void
    {
        $messages = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'one']]),
        ];
        $first = $this->converter->toMessageBagForTarget($messages, 'openai/gpt-test');
        $second = $this->converter->toMessageBagForTarget($messages, 'openai/gpt-test');
        $this->assertSame($first, $second);

        $generic = $this->converter->toMessageBag($messages);
        $this->assertNotSame($first, $generic);
        $third = $this->converter->toMessageBagForTarget($messages, 'openai/gpt-test');
        $this->assertSame($first, $third);
        $this->assertCount(1, $third->getMessages());
    }

    public function testAppendPreservesSyntheticImageOrderingAcrossToolBatch(): void
    {
        $tmp = TestDirectoryIsolation::createProjectTempDir('history-conversion');

        $imagePath = $tmp.'/pixel.png';
        file_put_contents(
            $imagePath,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true),
        );

        try {
            $prefix = [
                new AgentMessage(
                    role: 'assistant',
                    content: [],
                    metadata: [
                        'source_model' => 'vision/model',
                        'tool_calls' => [
                            ['id' => 'call_a', 'name' => 'view_image', 'arguments' => []],
                            ['id' => 'call_b', 'name' => 'view_image', 'arguments' => []],
                        ],
                    ],
                ),
            ];
            $this->converter->toMessageBagForTarget($prefix, 'vision/model');

            $withTools = [
                $prefix[0],
                new AgentMessage(
                    role: 'tool',
                    content: [
                        ['type' => 'text', 'text' => 'a'],
                        ['type' => 'image_ref', 'path' => $imagePath, 'media_type' => 'image/png', 'bytes' => 68, 'width' => 1, 'height' => 1],
                    ],
                    toolCallId: 'call_a',
                    toolName: 'view_image',
                ),
                new AgentMessage(
                    role: 'tool',
                    content: [
                        ['type' => 'text', 'text' => 'b'],
                        ['type' => 'image_ref', 'path' => $imagePath, 'media_type' => 'image/png', 'bytes' => 68, 'width' => 1, 'height' => 1],
                    ],
                    toolCallId: 'call_b',
                    toolName: 'view_image',
                ),
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'done']]),
            ];

            $bag = $this->converter->toMessageBagForTarget($withTools, 'vision/model');
            $messages = $bag->getMessages();
            $this->assertInstanceOf(AssistantMessage::class, $messages[0]);
            $this->assertInstanceOf(ToolCallMessage::class, $messages[1]);
            $this->assertInstanceOf(ToolCallMessage::class, $messages[2]);
            $this->assertInstanceOf(UserMessage::class, $messages[3]);
            $this->assertTrue($messages[3]->hasImageContent());
            $this->assertContainsOnlyInstancesOf(
                Image::class,
                array_values(array_filter(
                    $messages[3]->getContent(),
                    static fn ($part): bool => $part instanceof Image,
                )),
            );
            $this->assertTrue(
                [] !== array_filter(
                    $messages[3]->getContent(),
                    static fn ($part): bool => $part instanceof Image,
                ),
            );
            $this->assertInstanceOf(UserMessage::class, $messages[4]);
            $this->assertTrue($messages[4]->hasImageContent());
            $this->assertInstanceOf(UserMessage::class, $messages[5]);
            $this->assertSame('done', $messages[5]->asText());
        } finally {
            TestDirectoryIsolation::removeDirectory($tmp);
        }
    }

    public function testDeletedImageRefRebuildsToPlaceholderAndRestoredImageRefRebuildsAttachment(): void
    {
        $tmp = TestDirectoryIsolation::createProjectTempDir('history-conversion-image');
        $imagePath = $tmp.'/pixel.png';
        file_put_contents(
            $imagePath,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true),
        );

        try {
            $messages = [
                new AgentMessage(
                    role: 'assistant',
                    content: [],
                    metadata: [
                        'source_model' => 'vision/model',
                        'tool_calls' => [['id' => 'call_img', 'name' => 'view_image', 'arguments' => []]],
                    ],
                ),
                new AgentMessage(
                    role: 'tool',
                    content: [
                        ['type' => 'text', 'text' => 'img'],
                        ['type' => 'image_ref', 'path' => $imagePath, 'media_type' => 'image/png', 'bytes' => 68, 'width' => 1, 'height' => 1],
                    ],
                    toolCallId: 'call_img',
                    toolName: 'view_image',
                ),
            ];

            $readable = $this->converter->toMessageBagForTarget($messages, 'vision/model');
            $this->assertTrue($readable->getMessages()[2]->hasImageContent());

            unlink($imagePath);
            $missing = $this->converter->toMessageBagForTarget($messages, 'vision/model');
            $this->assertFalse($missing->getMessages()[2]->hasImageContent());
            $this->assertStringContainsString('Tool result image for view_image', (string) $missing->getMessages()[2]->asText());

            file_put_contents(
                $imagePath,
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true),
            );
            $restored = $this->converter->toMessageBagForTarget($messages, 'vision/model');
            $this->assertTrue($restored->getMessages()[2]->hasImageContent());
        } finally {
            TestDirectoryIsolation::removeDirectory($tmp);
        }
    }

    public function testCompletingOpenToolBatchReusesStableSymfonyMessageInstances(): void
    {
        $tmp = TestDirectoryIsolation::createProjectTempDir('history-conversion-boundary');
        $imagePath = $tmp.'/pixel.png';
        file_put_contents(
            $imagePath,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true),
        );

        try {
            $stable = [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'start']]),
                new AgentMessage(
                    role: 'assistant',
                    content: [['type' => 'text', 'text' => 'stable']],
                    metadata: ['source_model' => 'vision/model'],
                ),
            ];
            $openBatch = [
                ...$stable,
                new AgentMessage(
                    role: 'assistant',
                    content: [],
                    metadata: [
                        'source_model' => 'vision/model',
                        'tool_calls' => [
                            ['id' => 'call_a', 'name' => 'view_image', 'arguments' => []],
                            ['id' => 'call_b', 'name' => 'view_image', 'arguments' => []],
                        ],
                    ],
                ),
                new AgentMessage(
                    role: 'tool',
                    content: [
                        ['type' => 'text', 'text' => 'a'],
                        ['type' => 'image_ref', 'path' => $imagePath, 'media_type' => 'image/png', 'bytes' => 68, 'width' => 1, 'height' => 1],
                    ],
                    toolCallId: 'call_a',
                    toolName: 'view_image',
                ),
            ];
            $first = $this->converter->toMessageBagForTarget($openBatch, 'vision/model');
            $stableUser = $first->getMessages()[0];
            $stableAssistant = $first->getMessages()[1];

            $completed = [
                ...$openBatch,
                new AgentMessage(
                    role: 'tool',
                    content: [
                        ['type' => 'text', 'text' => 'b'],
                        ['type' => 'image_ref', 'path' => $imagePath, 'media_type' => 'image/png', 'bytes' => 68, 'width' => 1, 'height' => 1],
                    ],
                    toolCallId: 'call_b',
                    toolName: 'view_image',
                ),
            ];
            $second = $this->converter->toMessageBagForTarget($completed, 'vision/model');
            $this->assertSame($stableUser, $second->getMessages()[0]);
            $this->assertSame($stableAssistant, $second->getMessages()[1]);
        } finally {
            TestDirectoryIsolation::removeDirectory($tmp);
        }
    }

    public function testContainerServiceSurvivesServicesResetter(): void
    {
        $converter = static::getContainer()->get(AgentMessageConverter::class);
        $this->assertInstanceOf(AgentMessageConverter::class, $converter);
        $this->assertNotInstanceOf(ResetInterface::class, $converter);

        $messages = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'persist across reset']]),
        ];
        $before = $converter->toMessageBagForTarget($messages, 'openai/gpt-test');

        $resetter = static::getContainer()->get('services_resetter');
        $this->assertInstanceOf(ServicesResetterInterface::class, $resetter);
        $resetter->reset();

        $after = $converter->toMessageBagForTarget($messages, 'openai/gpt-test');
        $this->assertSame($before, $after);
        $this->assertSame($before->getMessages()[0]->asText(), $after->getMessages()[0]->asText());
        $this->assertSame(
            static::getContainer()->get(AgentMessageConverter::class),
            $converter,
        );
    }

    #[Group('performance')]
    public function testLargeHistoryConversionCompletesWithoutTimingAssertion(): void
    {
        $messages = [];
        for ($i = 0; $i < 400; ++$i) {
            $callId = 'call_foreign_'.$i.'|fc_item_'.$i;
            $messages[] = new AgentMessage(
                role: 'assistant',
                content: [['type' => 'text', 'text' => 'step '.$i]],
                details: ['thinking' => 'think '.$i, 'thinking_signature' => '{"type":"reasoning","id":"rs_'.$i.'"}'],
                metadata: [
                    'source_model' => 'openai-codex/gpt-6-astra',
                    'tool_calls' => [['id' => $callId, 'name' => 'bash', 'arguments' => ['command' => 'echo '.$i]]],
                ],
            );
            $messages[] = new AgentMessage(
                role: 'tool',
                content: [['type' => 'text', 'text' => 'result '.$i]],
                toolCallId: $callId,
                toolName: 'bash',
            );
        }

        $bag = $this->converter->toMessageBagForTarget($messages, 'zai/glm-5.3-flash');
        $this->assertInstanceOf(MessageBag::class, $bag);
        $this->assertSame(800, \count($bag->getMessages()));

        $appended = $messages;
        $appended[] = new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'next']]);
        $again = $this->converter->toMessageBagForTarget($appended, 'zai/glm-5.3-flash');
        $this->assertSame('next', $again->getMessages()[\count($again->getMessages()) - 1]->asText());
    }
}
