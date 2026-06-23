<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use PHPUnit\Framework\TestCase;

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
}
