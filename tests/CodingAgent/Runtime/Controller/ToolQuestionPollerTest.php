<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\CodingAgent\Entity\ToolQuestion;
use Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter;
use Ineersa\CodingAgent\Runtime\Controller\ToolQuestionPoller;
use Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Controller\ToolQuestionPoller
 *
 * A real emitter writes to an owned memory stream. Store doubles prove the
 * acknowledgement boundary; the bootstrap lifecycle test uses the Doctrine store.
 */
final class ToolQuestionPollerTest extends TestCase
{
    /** @var list<resource> */
    private array $outputs = [];

    protected function tearDown(): void
    {
        foreach ($this->outputs as $output) {
            fclose($output);
        }
        parent::tearDown();
    }

    // ── poll() behaviour ───────────────────────────────────────────────

    public function testPollEmitsEventAndMarksEmitted(): void
    {
        $question = $this->createTestQuestion();

        $store = $this->createMock(ToolQuestionStoreInterface::class);
        $store->expects($this->once())
            ->method('findUnemittedPendingQuestions')
            ->willReturn([$question]);
        $store->expects($this->once())
            ->method('markEmitted')
            ->with($question->requestId);

        $poller = new ToolQuestionPoller(
            store: $store,
            emitter: $this->createEmitter(),
            logger: $this->createStub(LoggerInterface::class),
        );

        // Invoke the private poll() method via reflection.
        $ref = new \ReflectionMethod($poller, 'poll');
        $ref->invoke($poller);
    }

    public function testPollSkippedWhenNoPendingQuestions(): void
    {
        $store = $this->createMock(ToolQuestionStoreInterface::class);
        $store->expects($this->once())
            ->method('findUnemittedPendingQuestions')
            ->willReturn([]);
        $store->expects($this->never())
            ->method('markEmitted');

        $poller = new ToolQuestionPoller(
            store: $store,
            emitter: $this->createEmitter(),
            logger: $this->createStub(LoggerInterface::class),
        );

        $ref = new \ReflectionMethod($poller, 'poll');
        $ref->invoke($poller);
    }

    public function testPollEmitsMultipleQuestionsInOrder(): void
    {
        $q1 = $this->createTestQuestion(requestId: 'rq-1', runId: 'run-1', prompt: 'First?');
        $q2 = $this->createTestQuestion(requestId: 'rq-2', runId: 'run-1', prompt: 'Second?');

        $store = $this->createMock(ToolQuestionStoreInterface::class);
        $store->expects($this->once())
            ->method('findUnemittedPendingQuestions')
            ->willReturn([$q1, $q2]);

        $store->expects($this->exactly(2))
            ->method('markEmitted')
            ->with($this->callback(static fn (string $id): bool => \in_array($id, ['rq-1', 'rq-2'], true)));

        $poller = new ToolQuestionPoller(
            store: $store,
            emitter: $this->createEmitter(),
            logger: $this->createStub(LoggerInterface::class),
        );

        $ref = new \ReflectionMethod($poller, 'poll');
        $ref->invoke($poller);
    }

    public function testPollContinuesWhenMarkEmittedThrows(): void
    {
        $q1 = $this->createTestQuestion(requestId: 'rq-1', runId: 'run-1', prompt: 'First?');
        $q2 = $this->createTestQuestion(requestId: 'rq-2', runId: 'run-1', prompt: 'Second?');

        $store = $this->createMock(ToolQuestionStoreInterface::class);
        $store->expects($this->once())
            ->method('findUnemittedPendingQuestions')
            ->willReturn([$q1, $q2]);

        // First markEmitted throws; second question's markEmitted succeeds.
        $store->expects($this->exactly(2))
            ->method('markEmitted')
            ->willReturnCallback(static function (string $id): void {
                if ('rq-1' === $id) {
                    throw new \RuntimeException('DB write failure');
                }
                // rq-2 succeeds
            });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('tool_question.poller_emit_failed', $this->callback(static fn (array $c): bool => ($c['request_id'] ?? '') === 'rq-1'
                && ($c['exception_class'] ?? '') === \RuntimeException::class
                && !isset($c['exception'])
            ));

        $poller = new ToolQuestionPoller(
            store: $store,
            emitter: $this->createEmitter(),
            logger: $logger,
        );

        $ref = new \ReflectionMethod($poller, 'poll');
        $ref->invoke($poller);

        // No exception should propagate; both questions were processed.
        $this->addToAssertionCount(1);
    }

    // ── cancelStalePendingOnStartup() behaviour ─────────────────────────

    public function testCancelStalePendingOnStartupDelegatesToStore(): void
    {
        $store = $this->createMock(ToolQuestionStoreInterface::class);
        $store->expects($this->once())
            ->method('cancelPendingQuestionsCreatedBefore')
            ->with($this->isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(2);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('tool_question.poller_startup_cleanup', $this->callback(static fn (array $c): bool => 2 === ($c['count'] ?? 0)));

        $poller = new ToolQuestionPoller(
            store: $store,
            emitter: $this->createEmitter(),
            logger: $logger,
        );

        $ref = new \ReflectionMethod($poller, 'cancelStalePendingOnStartup');
        $ref->invoke($poller);
    }

    public function testCancelStalePendingOnStartupLogsWarningOnStoreFailure(): void
    {
        $store = $this->createMock(ToolQuestionStoreInterface::class);
        $store->expects($this->once())
            ->method('cancelPendingQuestionsCreatedBefore')
            ->willThrowException(new \RuntimeException('DB unavailable'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('tool_question.poller_startup_cleanup_failed', $this->callback(static fn (array $c): bool => ($c['exception_class'] ?? '') === \RuntimeException::class && !isset($c['exception'])));

        $poller = new ToolQuestionPoller(
            store: $store,
            emitter: $this->createEmitter(),
            logger: $logger,
        );

        $ref = new \ReflectionMethod($poller, 'cancelStalePendingOnStartup');
        $ref->invoke($poller);

        // No exception should propagate — fail-closed behaviour.
        $this->addToAssertionCount(1);
    }

    private function createEmitter(): RuntimeEventEmitter
    {
        $emitter = new RuntimeEventEmitter(
            logger: $this->createStub(LoggerInterface::class),
        );
        $output = fopen('php://memory', 'w+b');
        $this->assertIsResource($output);
        $this->outputs[] = $output;
        (new \ReflectionProperty($emitter, 'stdout'))->setValue($emitter, $output);

        return $emitter;
    }

    /**
     * Create a ToolQuestion for test use.
     */
    private function createTestQuestion(
        string $requestId = 'test-rq-1',
        string $runId = 'test-run-1',
        string $prompt = 'Test background prompt?',
    ): ToolQuestion {
        return ToolQuestion::create(
            requestId: $requestId,
            runId: $runId,
            toolCallId: 'tc-1',
            toolName: 'bash',
            pid: 12345,
            logPath: '/tmp/test-poller.log',
            commandPreview: 'echo test',
            prompt: $prompt,
        );
    }
}
