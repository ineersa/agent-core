<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ConversationHistoryConversion;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\CodexContract;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Contracts\Service\ResetInterface;

final class ConversationHistoryConversionTest extends IsolatedKernelTestCase
{
    private ConversationHistoryConversion $conversion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conversion = new ConversationHistoryConversion(new AgentMessageConverter());
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

        $bag = $this->conversion->toMessageBagForTarget($messages, 'openai-codex/gpt-6-astra');
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
            $this->conversion->toMessageBagForTarget([$original], 'openai-codex/gpt-6-astra'),
            [],
        );

        $this->assertSame('message', $payload['input'][0]['type']);
        $this->assertSame("Done\nsecret plan", $payload['input'][0]['content'][0]['text']);
        $this->assertSame('function_call', $payload['input'][1]['type']);
        $this->assertSame('call_845adac454c64712b769d15b', $payload['input'][1]['call_id']);
        $this->assertArrayNotHasKey('id', $payload['input'][1]);
        $this->assertFalse(
            $this->conversion->toMessageBagForTarget([$original], 'openai-codex/gpt-6-astra')
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

        $this->conversion->toMessageBagForTarget([$original], 'openai-codex/gpt-6-astra');

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

        $bag = $this->conversion->toMessageBagForTarget($messages, 'zai/glm-5.3-flash');
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
            $this->conversion->toMessageBagForTarget($messages, 'custom-codex/model-a'),
            [],
        );
        $this->assertSame('reasoning', $same['input'][0]['type']);
        $this->assertSame('fc_native', $same['input'][2]['id']);
        $this->assertSame($same['input'][2]['call_id'], $same['input'][3]['call_id']);

        foreach (['custom-codex/model-b', 'other-codex/model-a'] as $target) {
            $changed = CodexContract::create()->createRequestPayload(
                new CodexModel('model-a'),
                $this->conversion->toMessageBagForTarget($messages, $target),
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
        $bag = $this->conversion->toMessageBagForTarget($messages, 'openai/b');
        $requestIds = [];
        foreach ($bag->getMessages()[0]->getToolCalls() as $index => $call) {
            $requestIds[] = $call->getId();
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]{1,40}$/', $call->getId());
            $this->assertSame($call->getId(), $bag->getMessages()[$index + 1]->getToolCall()->getId());
        }
        $this->assertCount(4, array_unique($requestIds));
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

        $first = $this->conversion->toMessageBagForTarget($original, 'openai-codex/gpt-6-astra');
        $fresh = array_map(
            static fn (AgentMessage $message): AgentMessage => AgentMessage::fromPayload($message->toArray()) ?? throw new \RuntimeException('payload round-trip failed'),
            $original,
        );
        $this->assertNotSame($original[0], $fresh[0]);
        $second = $this->conversion->toMessageBagForTarget($fresh, 'openai-codex/gpt-6-astra');

        $this->assertNotSame($first, $second);
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
        $first = $this->conversion->toMessageBagForTarget($prefix, 'zai/glm-5.3-flash');
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
        $second = $this->conversion->toMessageBagForTarget($withResult, 'zai/glm-5.3-flash');

        $this->assertSame($remapped, $second->getMessages()[0]->getToolCalls()[0]->getId());
        $this->assertSame($remapped, $second->getMessages()[1]->getToolCall()->getId());
        $this->assertSame('continue', $second->getMessages()[2]->asText());
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

        $sameModel = $this->conversion->toMessageBagForTarget($messages, 'openai-codex/gpt-6-astra');
        $this->assertTrue($sameModel->getMessages()[1]->hasThinking());

        $crossModel = $this->conversion->toMessageBagForTarget($messages, 'zai/glm-5.3-flash');
        $this->assertFalse($crossModel->getMessages()[1]->hasThinking());
        $this->assertSame("answer\nsecret", $crossModel->getMessages()[1]->asText());

        $edited = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'prompt changed']]),
            $messages[1],
        ];
        $rebuilt = $this->conversion->toMessageBagForTarget($edited, 'zai/glm-5.3-flash');
        $this->assertSame('prompt changed', $rebuilt->getMessages()[0]->asText());
        $this->assertSame("answer\nsecret", $rebuilt->getMessages()[1]->asText());
    }

    public function testReturnedMessageBagCloneProtectsCachedProjection(): void
    {
        $messages = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'one']]),
        ];
        $first = $this->conversion->toMessageBagForTarget($messages, 'openai/gpt-test');
        $first->add(new UserMessage(new Text('mutated')));

        $second = $this->conversion->toMessageBagForTarget($messages, 'openai/gpt-test');
        $this->assertCount(1, $second->getMessages());
        $this->assertSame('one', $second->getMessages()[0]->asText());
        $this->assertCount(2, $first->getMessages());
    }

    public function testAppendPreservesSyntheticImageOrderingAcrossToolBatch(): void
    {
        $tmp = sys_get_temp_dir().'/hatfield-history-conversion-'.bin2hex(random_bytes(4));
        mkdir($tmp);
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
            $this->conversion->toMessageBagForTarget($prefix, 'vision/model');

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

            $bag = $this->conversion->toMessageBagForTarget($withTools, 'vision/model');
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
            @unlink($imagePath);
            @rmdir($tmp);
        }
    }

    public function testContainerServiceSurvivesServicesResetter(): void
    {
        $conversion = static::getContainer()->get(ConversationHistoryConversion::class);
        $this->assertInstanceOf(ConversationHistoryConversion::class, $conversion);
        $this->assertNotInstanceOf(ResetInterface::class, $conversion);

        $messages = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'persist across reset']]),
        ];
        $before = $conversion->toMessageBagForTarget($messages, 'openai/gpt-test');

        $resetter = static::getContainer()->get('services_resetter');
        $this->assertInstanceOf(ServicesResetterInterface::class, $resetter);
        $resetter->reset();

        $after = $conversion->toMessageBagForTarget($messages, 'openai/gpt-test');
        $this->assertNotSame($before, $after);
        $this->assertSame($before->getMessages()[0]->asText(), $after->getMessages()[0]->asText());
        $this->assertSame(
            static::getContainer()->get(ConversationHistoryConversion::class),
            $conversion,
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

        $bag = $this->conversion->toMessageBagForTarget($messages, 'zai/glm-5.3-flash');
        $this->assertInstanceOf(MessageBag::class, $bag);
        $this->assertSame(800, \count($bag->getMessages()));

        $appended = $messages;
        $appended[] = new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'next']]);
        $again = $this->conversion->toMessageBagForTarget($appended, 'zai/glm-5.3-flash');
        $this->assertSame('next', $again->getMessages()[\count($again->getMessages()) - 1]->asText());
    }
}
