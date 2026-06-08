<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Listener\PromptHistoryNavigator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PromptHistoryNavigator::class)]
final class PromptHistoryNavigatorTest extends TestCase
{
    private PromptHistoryNavigator $navigator;

    protected function setUp(): void
    {
        $this->navigator = new PromptHistoryNavigator();
    }

    // ─── Empty state ──────────────────────────────────────────

    #[Test]
    public function previousOnEmptyBlocksReturnsNull(): void
    {
        $result = $this->navigator->previous([]);

        $this->assertNull($result);
        $this->assertFalse($this->navigator->isNavigating());
    }

    #[Test]
    public function nextOnEmptyBlocksReturnsNull(): void
    {
        $result = $this->navigator->next([]);

        $this->assertNull($result);
        $this->assertFalse($this->navigator->isNavigating());
    }

    #[Test]
    public function isNotNavigatingInitially(): void
    {
        $this->assertFalse($this->navigator->isNavigating());
    }

    // ─── Single user message ──────────────────────────────────

    #[Test]
    public function previousWithSingleUserMessageReturnsIt(): void
    {
        $blocks = [
            self::userBlock('hello world', 'msg-1', 'run-1', 1),
        ];

        $result = $this->navigator->previous($blocks);

        $this->assertSame('hello world', $result);
        $this->assertTrue($this->navigator->isNavigating());
    }

    #[Test]
    public function previousTwiceWithSingleUserMessageReturnsNullSecondTime(): void
    {
        $blocks = [
            self::userBlock('only one', 'msg-1', 'run-1', 1),
        ];

        $this->navigator->previous($blocks); // first — returns text
        $result = $this->navigator->previous($blocks); // second — no earlier message

        $this->assertNull($result);
        $this->assertTrue($this->navigator->isNavigating()); // still at the first one
    }

    // ─── Multiple user messages ───────────────────────────────

    #[Test]
    public function previousWalksBackwardThroughMultipleUserMessages(): void
    {
        $blocks = [
            self::userBlock('first prompt', 'msg-1', 'run-1', 1),
            self::assistantBlock('reply', 'msg-2', 'run-1', 2),
            self::userBlock('second prompt', 'msg-3', 'run-1', 3),
            self::userBlock('third prompt', 'msg-4', 'run-1', 4),
        ];

        // First Up — newest prompt (index 3)
        $text = $this->navigator->previous($blocks);
        $this->assertSame('third prompt', $text);

        // Second Up — next older (index 2)
        $text = $this->navigator->previous($blocks);
        $this->assertSame('second prompt', $text);

        // Third Up — oldest (index 0)
        $text = $this->navigator->previous($blocks);
        $this->assertSame('first prompt', $text);

        // Fourth Up — nothing older
        $text = $this->navigator->previous($blocks);
        $this->assertNull($text);
    }

    #[Test]
    public function nextWalksForwardThroughMultipleUserMessages(): void
    {
        $blocks = [
            self::userBlock('first prompt', 'msg-1', 'run-1', 1),
            self::assistantBlock('reply', 'msg-2', 'run-1', 2),
            self::userBlock('second prompt', 'msg-3', 'run-1', 3),
            self::userBlock('third prompt', 'msg-4', 'run-1', 4),
        ];

        // Navigate back to the oldest first
        $this->navigator->previous($blocks); // third (newest)
        $this->navigator->previous($blocks); // second
        $this->navigator->previous($blocks); // first (oldest)

        // Now walk forward
        $text = $this->navigator->next($blocks);
        $this->assertSame('second prompt', $text);

        $text = $this->navigator->next($blocks);
        $this->assertSame('third prompt', $text);
    }

    // ─── Non-user blocks are skipped ──────────────────────────

    #[Test]
    public function previousSkipsNonUserBlocks(): void
    {
        $blocks = [
            self::systemBlock('Welcome', 'sys-1', 'run-1', 1),
            self::assistantBlock('reply 1', 'asst-1', 'run-1', 2),
            self::userBlock('my prompt', 'user-1', 'run-1', 3),
        ];

        $result = $this->navigator->previous($blocks);

        $this->assertSame('my prompt', $result);
    }

    #[Test]
    public function nextSkipsNonUserBlocks(): void
    {
        $blocks = [
            self::userBlock('prompt 1', 'user-1', 'run-1', 1),
            self::assistantBlock('reply 1', 'asst-1', 'run-1', 2),
            self::systemBlock('status', 'sys-1', 'run-1', 3),
            self::userBlock('prompt 2', 'user-2', 'run-1', 4),
        ];

        // Go to oldest
        $this->navigator->previous($blocks); // prompt 2
        $this->navigator->previous($blocks); // prompt 1

        // Next should skip the assistant and system blocks
        $text = $this->navigator->next($blocks);
        $this->assertSame('prompt 2', $text);
    }

    // ─── Down past newest exits navigation ───────────────────

    #[Test]
    public function nextPastNewestExitsNavigationAndReturnsNull(): void
    {
        $blocks = [
            self::userBlock('prompt 1', 'user-1', 'run-1', 1),
        ];

        $this->navigator->previous($blocks); // shows prompt 1
        $this->assertTrue($this->navigator->isNavigating());

        $result = $this->navigator->next($blocks); // past newest

        $this->assertNull($result);
        $this->assertFalse($this->navigator->isNavigating());
    }

    // ─── Exit navigation ──────────────────────────────────────

    #[Test]
    public function exitNavigationResetsCursor(): void
    {
        $blocks = [
            self::userBlock('prompt 1', 'user-1', 'run-1', 1),
        ];

        $this->navigator->previous($blocks);
        $this->assertTrue($this->navigator->isNavigating());

        $this->navigator->exitNavigation();
        $this->assertFalse($this->navigator->isNavigating());

        // After exiting, next Up starts fresh from newest
        $result = $this->navigator->previous($blocks);
        $this->assertSame('prompt 1', $result);
    }

    // ─── No user blocks at all ────────────────────────────────

    #[Test]
    public function previousWithNoUserBlocksReturnsNull(): void
    {
        $blocks = [
            self::systemBlock('Welcome', 'sys-1', 'run-1', 1),
            self::assistantBlock('reply', 'asst-1', 'run-1', 2),
        ];

        $result = $this->navigator->previous($blocks);

        $this->assertNull($result);
        $this->assertFalse($this->navigator->isNavigating());
    }

    // ─── currentCursor for diagnostics ────────────────────────

    #[Test]
    public function currentCursorIsNullWhenNotNavigating(): void
    {
        $this->assertNull($this->navigator->currentCursor());
    }

    #[Test]
    public function currentCursorReturnsBlockIndexWhenNavigating(): void
    {
        $blocks = [
            self::userBlock('prompt 1', 'user-1', 'run-1', 1),
            self::userBlock('prompt 2', 'user-2', 'run-1', 2),
        ];

        $this->navigator->previous($blocks); // index 1 (newest)

        $this->assertSame(1, $this->navigator->currentCursor());
    }

    // ─── Helpers ──────────────────────────────────────────────

    private static function userBlock(
        string $text,
        string $id = 'test-id',
        string $runId = 'test-run',
        int $seq = 1,
    ): TranscriptBlock {
        return new TranscriptBlock(
            id: $id,
            kind: TranscriptBlockKindEnum::UserMessage,
            runId: $runId,
            seq: $seq,
            text: $text,
        );
    }

    private static function assistantBlock(
        string $text,
        string $id = 'test-id',
        string $runId = 'test-run',
        int $seq = 1,
    ): TranscriptBlock {
        return new TranscriptBlock(
            id: $id,
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: $runId,
            seq: $seq,
            text: $text,
        );
    }

    private static function systemBlock(
        string $text,
        string $id = 'test-id',
        string $runId = 'test-run',
        int $seq = 1,
    ): TranscriptBlock {
        return new TranscriptBlock(
            id: $id,
            kind: TranscriptBlockKindEnum::System,
            runId: $runId,
            seq: $seq,
            text: $text,
        );
    }
}
