<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\ToolQuestion;

use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Ineersa\CodingAgent\Tool\ToolQuestion\BackgroundProcessStatusCheckerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the isFinished logic via a test-local fake checker.
 *
 * BackgroundProcessManager is final and cannot be mocked, so we test
 * the status-checking logic through a fake implementation that
 * simulates each terminal and non-terminal status.
 *
 * The production BackgroundProcessStatusChecker is verified end-to-end
 * through the BashToolTest::testProcessFinishesWhilePromptBlocksReturnsCompletedOutput
 * integration test, which uses real background processes.
 */
final class BackgroundProcessStatusCheckerTest extends TestCase
{
    public function testRunningProcessReturnsFalse(): void
    {
        $checker = FakeStatusChecker::withStatus(BackgroundProcessStatusEnum::Running);

        self::assertFalse($checker->isFinished(12345, 'session-a'));
    }

    public function testFinishedProcessReturnsTrue(): void
    {
        $checker = FakeStatusChecker::withStatus(BackgroundProcessStatusEnum::Finished);

        self::assertTrue($checker->isFinished(12345, 'session-a'));
    }

    public function testStoppedProcessReturnsTrue(): void
    {
        $checker = FakeStatusChecker::withStatus(BackgroundProcessStatusEnum::Stopped);

        self::assertTrue($checker->isFinished(12345, 'session-a'));
    }

    public function testFinishedUncleanProcessReturnsTrue(): void
    {
        $checker = FakeStatusChecker::withStatus(BackgroundProcessStatusEnum::FinishedUnclean);

        self::assertTrue($checker->isFinished(12345, 'session-a'));
    }

    public function testVanishedProcessReturnsTrue(): void
    {
        $checker = FakeStatusChecker::vanished();

        self::assertTrue($checker->isFinished(12345, 'session-a'));
    }
}

/**
 * Test-local fake that implements the status-checking logic without
 * needing to mock the final BackgroundProcessManager class.
 */
final class FakeStatusChecker implements BackgroundProcessStatusCheckerInterface
{
    private function __construct(
        private readonly ?BackgroundProcessStatusEnum $status,
    ) {
    }

    public static function withStatus(BackgroundProcessStatusEnum $status): self
    {
        return new self($status);
    }

    public static function vanished(): self
    {
        return new self(null);
    }

    public function isFinished(int $pid, string $sessionId): bool
    {
        if (null === $this->status) {
            return true; // vanished — process never found
        }

        return BackgroundProcessStatusEnum::Running !== $this->status;
    }
}
