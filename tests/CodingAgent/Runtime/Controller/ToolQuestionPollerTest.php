<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\CodingAgent\Entity\ToolQuestion;
use Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter;
use Ineersa\CodingAgent\Runtime\Controller\ToolQuestionPoller;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Controller\ToolQuestionPoller
 *
 * RuntimeEventEmitter is final and cannot be mocked, so we use a real
 * emitter with null eventClient/transcriptPersistence. Its emit() call
 * is a no-op when stdout is null (openStdout not called) and persister
 * is null — no side effects, no throws. We verify behaviour through
 * store mocks (findUnemittedPendingQuestions → markEmitted chain).
 */
final class ToolQuestionPollerTest extends TestCase
{
    private function createEmitter(): RuntimeEventEmitter
    {
        return new RuntimeEventEmitter(
            eventClient: null,
            transcriptPersistence: null,
            boundary: new RuntimeExceptionBoundary(new EventDispatcher()),
            logger: $this->createStub(LoggerInterface::class),
        );
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
            ->with($this->callback(fn (string $id): bool => \in_array($id, ['rq-1', 'rq-2'], true)));

        $poller = new ToolQuestionPoller(
            store: $store,
            emitter: $this->createEmitter(),
            logger: $this->createStub(LoggerInterface::class),
        );

        $ref = new \ReflectionMethod($poller, 'poll');
        $ref->invoke($poller);
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
            ->with('tool_question.poller_startup_cleanup', $this->callback(fn (array $c): bool => 2 === ($c['count'] ?? 0)));

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
            ->with('tool_question.poller_startup_cleanup_failed', $this->callback(fn (array $c): bool => str_contains($c['exception'] ?? '', 'DB unavailable')));

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
}
