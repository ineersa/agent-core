<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Infrastructure\SymfonyAi\ReasoningContentFeatureShaper;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Content\Thinking;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\Role;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\ToolCall;

final class ReasoningContentFeatureShaperTest extends TestCase
{
    private const DEEPSEEK_FEATURES = [ReasoningContentFeatureShaper::FEATURE];
    private ReasoningContentFeatureShaper $shaper;

    protected function setUp(): void
    {
        $this->shaper = new ReasoningContentFeatureShaper();
    }

    // ── supports() ────────────────────────────────────────────────────────

    public function testSupportsWhenCompatHasFlag(): void
    {
        self::assertTrue($this->shaper->supports(self::DEEPSEEK_FEATURES));
    }

    public function testSupportsWhenCompatHasNoFlag(): void
    {
        self::assertFalse($this->shaper->supports([]));
    }

    // ── shape() ───────────────────────────────────────────────────────────

    public function testNoOpWhenNoMessageBag(): void
    {
        $result = $this->shaper->shape(
            'deepseek-v4-pro',
            ['something_else' => 'value'],
            [],
            self::DEEPSEEK_FEATURES,
        );

        self::assertNull($result);
    }

    // ── Flag active → adds empty Thinking ─────────────────────────────────

    public function testAddsEmptyThinkingToAssistantWithoutThinking(): void
    {
        $bag = new MessageBag(
            $this->userMessage('Hello'),
            $this->assistantText('Hi there'),
            $this->userMessage('How are you?'),
        );

        $result = $this->shaper->shape(
            'deepseek-v4-pro',
            ['message_bag' => $bag],
            ['stream' => true, 'reasoning_effort' => 'high'],
            self::DEEPSEEK_FEATURES,
        );

        self::assertNotNull($result);
        self::assertNotNull($result->input);
        self::assertArrayHasKey('message_bag', $result->input);

        /** @var MessageBag $newBag */
        $newBag = $result->input['message_bag'];
        $messages = $newBag->getMessages();

        self::assertCount(3, $messages);

        // User messages untouched
        self::assertSame(Role::User, $messages[0]->getRole());
        self::assertSame(Role::User, $messages[2]->getRole());

        // Assistant message now has Thinking
        self::assertSame(Role::Assistant, $messages[1]->getRole());
        self::assertInstanceOf(AssistantMessage::class, $messages[1]);
        self::assertTrue($messages[1]->hasThinking(), 'AssistantMessage should have Thinking block');
        self::assertSame('Hi there', $messages[1]->asText());
    }

    // ── Existing thinking preserved ───────────────────────────────────────

    public function testDoesNotDuplicateExistingThinking(): void
    {
        $bag = new MessageBag(
            $this->assistantWithThinking('I considered...', 'I should respond kindly.'),
        );

        $result = $this->shaper->shape(
            'deepseek-v4-pro',
            ['message_bag' => $bag],
            [],
            self::DEEPSEEK_FEATURES,
        );

        self::assertNull($result, 'Should return null when no assistant messages needed changing');
    }

    // ── Mixed: some with thinking, some without ────────────────────────────

    public function testAddsThinkingOnlyToMessagesThatLackIt(): void
    {
        $bag = new MessageBag(
            $this->userMessage('Turn 1'),
            $this->assistantWithThinking('Already has thinking', 'existing thinking'),
            $this->userMessage('Turn 2'),
            $this->assistantText('No thinking here'),
        );

        $result = $this->shaper->shape(
            'deepseek-v4-pro',
            ['message_bag' => $bag],
            [],
            self::DEEPSEEK_FEATURES,
        );

        self::assertNotNull($result);
        self::assertNotNull($result->input);
        self::assertArrayHasKey('message_bag', $result->input);

        /** @var MessageBag $newBag */
        $newBag = $result->input['message_bag'];
        $messages = $newBag->getMessages();

        self::assertCount(4, $messages);

        // First assistant (Turn 1) keeps its existing thinking
        self::assertSame(Role::Assistant, $messages[1]->getRole());
        self::assertTrue($messages[1]->hasThinking());
        $thinking = $messages[1]->getThinking();
        self::assertCount(1, $thinking);
        self::assertSame('existing thinking', $thinking[0]->getContent());

        // Second assistant (Turn 2) gets new empty thinking
        self::assertSame(Role::Assistant, $messages[3]->getRole());
        self::assertTrue($messages[3]->hasThinking());
        self::assertSame('No thinking here', $messages[3]->asText());

        $thinking2 = $messages[3]->getThinking();
        self::assertCount(1, $thinking2);
        self::assertSame('', $thinking2[0]->getContent());
        self::assertNull($thinking2[0]->getSignature());
    }

    // ── Non-assistant messages untouched ───────────────────────────────────

    public function testNonAssistantMessagesUntouched(): void
    {
        $toolMessage = new ToolCallMessage(
            new ToolCall('tool-call-1', 'read', ['path' => 'file.txt']),
            'file contents',
        );

        $bag = new MessageBag(
            $this->systemMessage('You are helpful.'),
            $this->userMessage('Read README.md'),
            $this->assistantText('OK'),
            $toolMessage,
        );

        $result = $this->shaper->shape(
            'deepseek-v4-pro',
            ['message_bag' => $bag],
            [],
            self::DEEPSEEK_FEATURES,
        );

        self::assertNotNull($result);
        self::assertNotNull($result->input);

        /** @var MessageBag $newBag */
        $newBag = $result->input['message_bag'];
        $messages = $newBag->getMessages();

        self::assertCount(4, $messages);

        // system, user, tool messages unchanged
        self::assertSame(Role::System, $messages[0]->getRole());
        self::assertSame(Role::User, $messages[1]->getRole());
        self::assertSame(Role::ToolCall, $messages[3]->getRole());

        // Assistant gets thinking added
        self::assertTrue($messages[2]->hasThinking());
    }

    // ── Tool calls preserved ───────────────────────────────────────────────

    public function testToolCallsPreservedWhenThinkingAdded(): void
    {
        $bag = new MessageBag(
            $this->userMessage('Run ls'),
            $this->assistantWithToolCall('tool-123', 'bash'),
        );

        $result = $this->shaper->shape(
            'deepseek-v4-pro',
            ['message_bag' => $bag],
            [],
            self::DEEPSEEK_FEATURES,
        );

        self::assertNotNull($result);

        /** @var MessageBag $newBag */
        $newBag = $result->input['message_bag'];
        $messages = $newBag->getMessages();

        self::assertCount(2, $messages);

        /** @var AssistantMessage $assistant */
        $assistant = $messages[1];
        self::assertTrue($assistant->hasThinking(), 'Should have added empty Thinking');
        self::assertTrue($assistant->hasToolCalls(), 'Should preserve tool calls');

        $toolCalls = $assistant->getToolCalls();
        self::assertCount(1, $toolCalls);
        self::assertSame('bash', $toolCalls[0]->getName());

        // Thinking was added empty
        $thinking = $assistant->getThinking();
        self::assertCount(1, $thinking);
        self::assertSame('', $thinking[0]->getContent());
    }

    public function testTextAndToolCallsPreservedWhenThinkingAdded(): void
    {
        $bag = new MessageBag(
            $this->userMessage('Run ls'),
            $this->assistantTextWithToolCall('I will run ls', 'tool-456', 'bash'),
        );

        $result = $this->shaper->shape(
            'deepseek-v4-pro',
            ['message_bag' => $bag],
            [],
            self::DEEPSEEK_FEATURES,
        );

        self::assertNotNull($result);

        /** @var MessageBag $newBag */
        $newBag = $result->input['message_bag'];
        $messages = $newBag->getMessages();

        /** @var AssistantMessage $assistant */
        $assistant = $messages[1];
        self::assertTrue($assistant->hasThinking());
        self::assertSame('I will run ls', $assistant->asText());
        self::assertTrue($assistant->hasToolCalls());

        $toolCalls = $assistant->getToolCalls();
        self::assertCount(1, $toolCalls);
        self::assertSame('bash', $toolCalls[0]->getName());
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function userMessage(string $text): UserMessage
    {
        return Message::ofUser($text);
    }

    private function systemMessage(string $text): SystemMessage
    {
        return Message::forSystem($text);
    }

    private function assistantText(string $text): AssistantMessage
    {
        return new AssistantMessage(new Text($text));
    }

    private function assistantWithThinking(string $text, string $thinking): AssistantMessage
    {
        return new AssistantMessage(new Thinking($thinking), new Text($text));
    }

    private function assistantWithToolCall(string $toolCallId, string $toolName): AssistantMessage
    {
        return new AssistantMessage(
            new ToolCall($toolCallId, $toolName, ['command' => 'ls']),
        );
    }

    private function assistantTextWithToolCall(string $text, string $toolCallId, string $toolName): AssistantMessage
    {
        return new AssistantMessage(
            new Text($text),
            new ToolCall($toolCallId, $toolName, ['command' => 'ls']),
        );
    }
}
