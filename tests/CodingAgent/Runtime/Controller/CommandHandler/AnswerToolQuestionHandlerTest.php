<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Entity\ToolQuestion;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\AnswerToolQuestionHandler;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnswerToolQuestionHandler::class)]
final class AnswerToolQuestionHandlerTest extends TestCase
{
    private SpyToolQuestionStore $spyStore;

    protected function setUp(): void
    {
        $this->spyStore = new SpyToolQuestionStore();
    }

    public function testDispatchesAnswerToolQuestionToStore(): void
    {
        $handler = new AnswerToolQuestionHandler($this->spyStore);

        $command = new RuntimeCommand(
            id: 'cmd_1',
            type: 'answer_tool_question',
            runId: 'run-123',
            payload: [
                'request_id' => 'bash_bg_run-123_tc456_789',
                'answer' => true,
            ],
        );

        $event = new ControllerCommandEvent($command, static function (): void {});
        $handler($event);

        $this->assertNotNull($this->spyStore->lastRequestId);
        $this->assertSame('bash_bg_run-123_tc456_789', $this->spyStore->lastRequestId);
        $this->assertTrue($this->spyStore->lastAnswer);
    }

    public function testDispatchesAnswerFalse(): void
    {
        $handler = new AnswerToolQuestionHandler($this->spyStore);

        $command = new RuntimeCommand(
            id: 'cmd_2',
            type: 'answer_tool_question',
            runId: 'run-123',
            payload: [
                'request_id' => 'bash_bg_run-123_tc456_789',
                'answer' => false,
            ],
        );

        $event = new ControllerCommandEvent($command, static function (): void {});
        $handler($event);

        $this->assertFalse($this->spyStore->lastAnswer);
    }

    public function testEmitsProtocolErrorWhenRunIdMissing(): void
    {
        $handler = new AnswerToolQuestionHandler($this->spyStore);

        $emittedEvents = [];
        $emit = static function (RuntimeEvent $event) use (&$emittedEvents): void {
            $emittedEvents[] = $event;
        };

        $command = new RuntimeCommand(
            id: 'cmd_3',
            type: 'answer_tool_question',
            runId: '',
            payload: ['request_id' => 'bash_bg_abc'],
        );

        $event = new ControllerCommandEvent($command, $emit);
        $handler($event);

        $this->assertNull($this->spyStore->lastRequestId);
        $this->assertCount(1, $emittedEvents);
        $this->assertSame(RuntimeEventTypeEnum::ProtocolError->value, $emittedEvents[0]->type);
    }

    public function testEmitsProtocolErrorWhenRequestIdMissing(): void
    {
        $handler = new AnswerToolQuestionHandler($this->spyStore);

        $emittedEvents = [];
        $emit = static function (RuntimeEvent $event) use (&$emittedEvents): void {
            $emittedEvents[] = $event;
        };

        $command = new RuntimeCommand(
            id: 'cmd_4',
            type: 'answer_tool_question',
            runId: 'run-456',
            payload: ['answer' => true],
        );

        $event = new ControllerCommandEvent($command, $emit);
        $handler($event);

        $this->assertNull($this->spyStore->lastRequestId);
        $this->assertCount(1, $emittedEvents);
        $this->assertSame(RuntimeEventTypeEnum::ProtocolError->value, $emittedEvents[0]->type);
        $this->assertSame('run-456', $emittedEvents[0]->runId);
    }

    public function testIgnoresNonToolQuestionCommands(): void
    {
        $handler = new AnswerToolQuestionHandler($this->spyStore);

        $command = new RuntimeCommand(
            id: 'cmd_5',
            type: 'user_message',
            runId: 'run-789',
            payload: ['text' => 'hello'],
        );

        $event = new ControllerCommandEvent($command, static function (): void {});
        $handler($event);

        $this->assertNull($this->spyStore->lastRequestId);
        $this->addToAssertionCount(1);
    }

    public function testResolvesStringYesToTrue(): void
    {
        $handler = new AnswerToolQuestionHandler($this->spyStore);

        $command = new RuntimeCommand(
            id: 'cmd_6',
            type: 'answer_tool_question',
            runId: 'run-abc',
            payload: [
                'request_id' => 'bash_bg_r1',
                'answer' => 'yes',
            ],
        );

        $event = new ControllerCommandEvent($command, static function (): void {});
        $handler($event);

        $this->assertTrue($this->spyStore->lastAnswer);
    }

    public function testResolvesStringNoToFalse(): void
    {
        $handler = new AnswerToolQuestionHandler($this->spyStore);

        $command = new RuntimeCommand(
            id: 'cmd_7',
            type: 'answer_tool_question',
            runId: 'run-abc',
            payload: [
                'request_id' => 'bash_bg_r2',
                'answer' => 'no',
            ],
        );

        $event = new ControllerCommandEvent($command, static function (): void {});
        $handler($event);

        $this->assertFalse($this->spyStore->lastAnswer);
    }

    public function testStoredConfirmKindWithNullSchemaAcceptsBooleanFalse(): void
    {
        $stored = ToolQuestion::create(
            requestId: 'bash_bg_run-confirm-null',
            runId: 'run-confirm',
            toolCallId: 'tc1',
            toolName: 'bash',
            pid: 1,
            logPath: '/tmp/log',
            commandPreview: 'sleep 8',
            prompt: 'Move it to the background?',
            kind: 'confirm',
            schema: null,
        );
        $this->spyStore->storedByRequestId['bash_bg_run-confirm-null'] = $stored;

        $handler = new AnswerToolQuestionHandler($this->spyStore);

        $emittedEvents = [];
        $emit = static function (RuntimeEvent $event) use (&$emittedEvents): void {
            $emittedEvents[] = $event;
        };

        $command = new RuntimeCommand(
            id: 'cmd_confirm_null',
            type: 'answer_tool_question',
            runId: 'run-confirm',
            payload: [
                'request_id' => 'bash_bg_run-confirm-null',
                'answer' => false,
            ],
        );

        $event = new ControllerCommandEvent($command, $emit);
        $handler($event);

        $this->assertSame([], $emittedEvents);
        $this->assertSame('bash_bg_run-confirm-null', $this->spyStore->lastRequestId);
        $this->assertFalse($this->spyStore->lastAnswer);
        $this->assertNull($this->spyStore->lastAnswerText);
    }

    public function testEmitsProtocolErrorOnStoreFailure(): void
    {
        $this->spyStore->throwOnAnswer = new \RuntimeException('DB connection lost');
        $handler = new AnswerToolQuestionHandler($this->spyStore);

        $emittedEvents = [];
        $emit = static function (RuntimeEvent $event) use (&$emittedEvents): void {
            $emittedEvents[] = $event;
        };

        $command = new RuntimeCommand(
            id: 'cmd_8',
            type: 'answer_tool_question',
            runId: 'run-999',
            payload: [
                'request_id' => 'bash_bg_run-999_tc1_1',
                'answer' => true,
            ],
        );

        $event = new ControllerCommandEvent($command, $emit);
        $handler($event);

        // Verify the handler caught the exception and emitted a ProtocolError.
        $this->assertNull($this->spyStore->lastAnswer, 'answer() should not have reached real store logic');
        $this->assertCount(1, $emittedEvents);
        $this->assertSame(RuntimeEventTypeEnum::ProtocolError->value, $emittedEvents[0]->type);
        $this->assertSame('run-999', $emittedEvents[0]->runId);
        $this->assertStringContainsString('Failed to answer tool question', $emittedEvents[0]->payload['error'] ?? '');
        $this->assertStringContainsString('DB connection lost', $emittedEvents[0]->payload['error'] ?? '');

        // Verify no exception propagates to the caller.
        $this->addToAssertionCount(1);
    }
}

/**
 * Inline spy implementation of ToolQuestionStoreInterface for testing.
 */
final class SpyToolQuestionStore implements ToolQuestionStoreInterface
{
    public ?string $lastRequestId = null;
    public ?bool $lastAnswer = null;

    /** @var array<string, ToolQuestion> */
    public array $storedByRequestId = [];

    public ?string $lastAnswerText = null;

    /**
     * When set, answer() throws this exception instead of recording.
     */
    public ?\Throwable $throwOnAnswer = null;

    public function answer(string $requestId, bool $answer): bool
    {
        if (null !== $this->throwOnAnswer) {
            throw $this->throwOnAnswer;
        }

        $this->lastRequestId = $requestId;
        $this->lastAnswer = $answer;

        return true;
    }

    public function findUnemittedPendingQuestions(): array
    {
        return [];
    }

    public function findByRequestId(string $requestId): ?ToolQuestion
    {
        return $this->storedByRequestId[$requestId] ?? null;
    }

    public function markEmitted(string $requestId): void
    {
    }

    public function pollAnswer(string $requestId): ?bool
    {
        return null;
    }

    public function cancel(string $requestId): void
    {
    }

    public function cancelPendingQuestionsCreatedBefore(\DateTimeImmutable $cutoff): int
    {
        return 0;
    }

    public function answerWithText(string $requestId, string $answer): bool
    {
        $this->lastRequestId = $requestId;
        $this->lastAnswerText = $answer;

        return true;
    }

    public function pollAnswerText(string $requestId): ?string
    {
        return null;
    }

    public function create(ToolQuestion $question): ToolQuestion
    {
        return $question;
    }
}
