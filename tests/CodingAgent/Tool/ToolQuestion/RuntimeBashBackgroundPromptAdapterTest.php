<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\ToolQuestion;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\CodingAgent\Entity\ToolQuestion;
use Ineersa\CodingAgent\Tool\ToolQuestion\BackgroundProcessStatusCheckerInterface;
use Ineersa\CodingAgent\Tool\ToolQuestion\RuntimeBashBackgroundPromptAdapter;
use Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for RuntimeBashBackgroundPromptAdapter.
 *
 * Focuses on the critical paths:
 * - No ToolContext available → should decline without creating a question.
 * - User answers yes → should create the question and return true.
 * - Process finishes while waiting → should cancel and return false.
 * - elapsedSeconds at threshold boundary → still works.
 *
 * Cancellation and timeout paths are omitted because they would require
 * async time manipulation or flaky sleep-based testing. They are covered
 * indirectly by the ToolQuestionStore integration tests and the existing
 * BashTool foreground supervision loop tests.
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(RuntimeBashBackgroundPromptAdapter::class)]
final class RuntimeBashBackgroundPromptAdapterTest extends TestCase
{
    /**
     * Create a stub checker that always reports the process is still running.
     */
    private function notFinishedChecker(): BackgroundProcessStatusCheckerInterface
    {
        $checker = $this->createMock(BackgroundProcessStatusCheckerInterface::class);
        $checker->method('isFinished')->willReturn(false);

        return $checker;
    }

    /**
     * Create a stub checker that reports finished after the given call count.
     */
    private function finishedAfterCalls(int $callsBeforeFinished): BackgroundProcessStatusCheckerInterface
    {
        $checker = $this->createMock(BackgroundProcessStatusCheckerInterface::class);
        $checker->method('isFinished')
            ->willReturnOnConsecutiveCalls(
                ...array_merge(
                    array_fill(0, $callsBeforeFinished, false),
                    [true],
                ),
            );

        return $checker;
    }

    public function testNoContextReturnsFalseAndDoesNotCreateQuestion(): void
    {
        $contextAccessor = new StackToolExecutionContextAccessor();
        \assert(null === $contextAccessor->current()); // empty stack

        $store = $this->createMock(ToolQuestionStoreInterface::class);
        $store->expects(self::never())->method('create');

        $logger = $this->createStub(LoggerInterface::class);

        $adapter = new RuntimeBashBackgroundPromptAdapter(
            contextAccessor: $contextAccessor,
            store: $store,
            logger: $logger,
            processStatusChecker: $this->notFinishedChecker(),
        );

        $result = $adapter->shouldBackground(
            command: 'echo test',
            pid: 12345,
            logPath: '/tmp/test-bash.log',
            elapsedSeconds: 30.0,
        );

        self::assertFalse($result);
    }

    public function testAnsweredYesCreatesQuestionAndReturnsTrue(): void
    {
        $contextAccessor = new StackToolExecutionContextAccessor();

        $store = $this->createMock(ToolQuestionStoreInterface::class);

        // The adapter calls store->create() exactly once with a ToolQuestion entity.
        $store->expects(self::once())
            ->method('create')
            ->with(self::isInstanceOf(ToolQuestion::class));

        // The adapter polls until it gets a non-null answer. Simulate pending then yes.
        $store->expects(self::atLeast(2))
            ->method('pollAnswer')
            ->willReturnOnConsecutiveCalls(null, true);

        // The adapter must NOT call cancel() for the accepted path.
        $store->expects(self::never())->method('cancel');

        $logger = $this->createStub(LoggerInterface::class);

        $adapter = new RuntimeBashBackgroundPromptAdapter(
            contextAccessor: $contextAccessor,
            store: $store,
            logger: $logger,
            processStatusChecker: $this->notFinishedChecker(),
        );

        $cancellationToken = $this->createStub(CancellationTokenInterface::class);
        $cancellationToken->method('isCancellationRequested')->willReturn(false);

        $context = new ToolContext(
            runId: 'run-accept-test',
            turnNo: 1,
            toolCallId: 'tc-accept',
            toolName: 'bash',
            cancellationToken: $cancellationToken,
            timeoutSeconds: 30,
        );

        $result = $contextAccessor->with($context, fn (): bool => $adapter->shouldBackground(
            command: 'echo "long running command"',
            pid: 67890,
            logPath: '/tmp/test-bash-2.log',
            elapsedSeconds: 10.0,
        ));

        self::assertTrue($result);
    }

    public function testElapsedSecondsAtThresholdStillWorks(): void
    {
        $contextAccessor = new StackToolExecutionContextAccessor();

        $store = $this->createMock(ToolQuestionStoreInterface::class);
        $store->expects(self::once())
            ->method('create')
            ->with(self::isInstanceOf(ToolQuestion::class));
        $store->expects(self::atLeast(2))
            ->method('pollAnswer')
            ->willReturnOnConsecutiveCalls(null, true);
        $store->expects(self::never())->method('cancel');

        $logger = $this->createStub(LoggerInterface::class);

        $adapter = new RuntimeBashBackgroundPromptAdapter(
            contextAccessor: $contextAccessor,
            store: $store,
            logger: $logger,
            processStatusChecker: $this->notFinishedChecker(),
        );

        $cancellationToken = $this->createStub(CancellationTokenInterface::class);
        $cancellationToken->method('isCancellationRequested')->willReturn(false);

        $context = new ToolContext(
            runId: 'run-threshold-test',
            turnNo: 1,
            toolCallId: 'tc-threshold',
            toolName: 'bash',
            cancellationToken: $cancellationToken,
            timeoutSeconds: 30,
        );

        // elapsedSeconds exactly equals timeoutSeconds → remaining=0, no deadline
        $result = $contextAccessor->with($context, fn (): bool => $adapter->shouldBackground(
            command: 'sleep 1',
            pid: 11111,
            logPath: '/tmp/test-threshold.log',
            elapsedSeconds: 30.0,
        ));

        self::assertTrue($result);
    }

    public function testProcessFinishedDuringWaitCancelsAndReturnsFalse(): void
    {
        $contextAccessor = new StackToolExecutionContextAccessor();

        $requestId = 'bash_bg_run-finish_tc-finish_99999';

        $store = $this->createMock(ToolQuestionStoreInterface::class);
        $store->expects(self::once())
            ->method('create')
            ->with(self::isInstanceOf(ToolQuestion::class));
        // pollAnswer must NOT be called — the process-finished check fires
        // on the first loop iteration, before any store poll.
        $store->expects(self::never())
            ->method('pollAnswer');
        // Adapter must cancel the pending question when process finishes.
        $store->expects(self::once())
            ->method('cancel')
            ->with($requestId);

        $logger = $this->createStub(LoggerInterface::class);

        // First call to isFinished returns true immediately (process done).
        $checker = $this->finishedAfterCalls(0);

        $adapter = new RuntimeBashBackgroundPromptAdapter(
            contextAccessor: $contextAccessor,
            store: $store,
            logger: $logger,
            processStatusChecker: $checker,
        );

        $cancellationToken = $this->createStub(CancellationTokenInterface::class);
        $cancellationToken->method('isCancellationRequested')->willReturn(false);

        $context = new ToolContext(
            runId: 'run-finish',
            turnNo: 1,
            toolCallId: 'tc-finish',
            toolName: 'bash',
            cancellationToken: $cancellationToken,
            timeoutSeconds: 30,
        );

        $result = $contextAccessor->with($context, fn (): bool => $adapter->shouldBackground(
            command: 'sleep 60 && echo "Hello world"',
            pid: 99999,
            logPath: '/tmp/test-finish.log',
            elapsedSeconds: 5.0,
        ));

        self::assertFalse($result);
    }
}
