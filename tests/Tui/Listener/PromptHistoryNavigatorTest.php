<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Listener\PromptHistory;
use Ineersa\Tui\Listener\PromptHistoryNavigator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PromptHistoryNavigator::class)]
final class PromptHistoryNavigatorTest extends TestCase
{
    private PromptHistory $history;

    private PromptHistoryNavigator $navigator;

    protected function setUp(): void
    {
        $this->history = new PromptHistory();
        $this->navigator = new PromptHistoryNavigator($this->history);
    }

    // ─── Empty state ──────────────────────────────────────────

    #[Test]
    public function previousOnEmptyBlocksReturnsNull(): void
    {
        $this->history->seedFrom([]);
        $result = $this->navigator->previous();

        $this->assertNull($result);
        $this->assertFalse($this->navigator->isNavigating());
    }

    #[Test]
    public function nextOnEmptyBlocksReturnsNull(): void
    {
        $this->history->seedFrom([]);
        $result = $this->navigator->next();

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

        $this->history->seedFrom($blocks);
        $result = $this->navigator->previous();

        $this->assertSame('hello world', $result);
        $this->assertTrue($this->navigator->isNavigating());
    }

    #[Test]
    public function previousTwiceWithSingleUserMessageReturnsNullSecondTime(): void
    {
        $blocks = [
            self::userBlock('only one', 'msg-1', 'run-1', 1),
        ];

        $this->history->seedFrom($blocks);
        $this->navigator->previous(); // first — returns text
        $result = $this->navigator->previous(); // second — no earlier message

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
        $this->history->seedFrom($blocks);
        $text = $this->navigator->previous();
        $this->assertSame('third prompt', $text);

        // Second Up — next older (index 2)
        $this->history->seedFrom($blocks);
        $text = $this->navigator->previous();
        $this->assertSame('second prompt', $text);

        // Third Up — oldest (index 0)
        $this->history->seedFrom($blocks);
        $text = $this->navigator->previous();
        $this->assertSame('first prompt', $text);

        // Fourth Up — nothing older
        $this->history->seedFrom($blocks);
        $text = $this->navigator->previous();
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
        $this->history->seedFrom($blocks);
        $this->navigator->previous(); // third (newest)
        $this->navigator->previous(); // second
        $this->history->seedFrom($blocks);
        $this->navigator->previous(); // first (oldest)

        // Now walk forward
        $this->history->seedFrom($blocks);
        $text = $this->navigator->next();
        $this->assertSame('second prompt', $text);

        $this->history->seedFrom($blocks);
        $text = $this->navigator->next();
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

        $this->history->seedFrom($blocks);
        $result = $this->navigator->previous();

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
        $this->history->seedFrom($blocks);
        $this->navigator->previous(); // prompt 2
        $this->navigator->previous(); // prompt 1

        // Next should skip the assistant and system blocks
        $this->history->seedFrom($blocks);
        $text = $this->navigator->next();
        $this->assertSame('prompt 2', $text);
    }

    // ─── Down past newest exits navigation ───────────────────

    #[Test]
    public function nextPastNewestExitsNavigationAndReturnsNull(): void
    {
        $blocks = [
            self::userBlock('prompt 1', 'user-1', 'run-1', 1),
        ];

        $this->history->seedFrom($blocks);
        $this->navigator->previous(); // shows prompt 1
        $this->assertTrue($this->navigator->isNavigating());

        $this->history->seedFrom($blocks);
        $result = $this->navigator->next(); // past newest

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

        $this->history->seedFrom($blocks);
        $this->navigator->previous();
        $this->assertTrue($this->navigator->isNavigating());

        $this->navigator->exitNavigation();
        $this->assertFalse($this->navigator->isNavigating());

        // After exiting, next Up starts fresh from newest
        $this->history->seedFrom($blocks);
        $result = $this->navigator->previous();
        $this->assertSame('prompt 1', $result);
    }

    // ─── Up at oldest stays in place ─────────────────────────

    #[Test]
    public function upAtOldestWhileNavigatingIsNoOpCursorStays(): void
    {
        $blocks = [
            self::userBlock('oldest', 'user-1', 'run-1', 1),
            self::assistantBlock('reply', 'asst-1', 'run-1', 2),
            self::userBlock('newer', 'user-2', 'run-1', 3),
        ];

        // Navigate back to oldest
        $this->history->seedFrom($blocks);
        $this->navigator->previous(); // index 2 (newer)
        $this->navigator->previous(); // index 0 (oldest)

        $this->assertSame(0, $this->navigator->cursor());

        // Up again — no older message, cursor stays at 0
        $this->history->seedFrom($blocks);
        $result = $this->navigator->previous();
        $this->assertNull($result);
        $this->assertSame(0, $this->navigator->cursor());
        $this->assertTrue($this->navigator->isNavigating());
    }

    // ─── No user blocks at all ────────────────────────────────

    #[Test]
    public function previousWithNoUserBlocksReturnsNull(): void
    {
        $blocks = [
            self::systemBlock('Welcome', 'sys-1', 'run-1', 1),
            self::assistantBlock('reply', 'asst-1', 'run-1', 2),
        ];

        $this->history->seedFrom($blocks);
        $result = $this->navigator->previous();

        $this->assertNull($result);
        $this->assertFalse($this->navigator->isNavigating());
    }

    // ─── cursor for diagnostics ────────────────────

    #[Test]
    public function cursorIsNullWhenNotNavigating(): void
    {
        $this->assertNull($this->navigator->cursor());
    }

    #[Test]
    public function cursorReturnsBlockIndexWhenNavigating(): void
    {
        $blocks = [
            self::userBlock('prompt 1', 'user-1', 'run-1', 1),
            self::userBlock('prompt 2', 'user-2', 'run-1', 2),
        ];

        $this->history->seedFrom($blocks);
        $this->navigator->previous(); // index 1 (newest)

        $this->assertSame(1, $this->navigator->cursor());
    }

    #[Test]
    public function navigationOverLargeSeededHistoryUsesPromptListOnly(): void
    {
        $blocks = [];
        $expectedNewestFirst = [];
        $userCount = 0;
        for ($i = 0; $i < 1000; ++$i) {
            if (0 === $i % 5) {
                $text = 'user-prompt-'.$userCount;
                $blocks[] = self::userBlock($text, 'msg-'.$i, 'run-1', $i);
                $expectedNewestFirst[] = $text;
                ++$userCount;
            } elseif (1 === $i % 5) {
                $blocks[] = self::assistantBlock('reply', 'asst-'.$i, 'run-1', $i);
            } elseif (2 === $i % 5) {
                $blocks[] = self::systemBlock('sys', 'sys-'.$i, 'run-1', $i);
            } else {
                $blocks[] = self::toolBlock('tool', 'tool-'.$i, 'run-1', $i);
            }
        }

        $this->assertGreaterThanOrEqual(150, $userCount);

        $this->history->seedFrom($blocks);

        $walked = [];
        for ($n = 0; $n < 150; ++$n) {
            $text = $this->navigator->previous();
            $this->assertNotNull($text, 'Up #'.($n + 1).' should recall a prompt');
            $walked[] = $text;
        }

        $this->assertSame(array_reverse(\array_slice($expectedNewestFirst, -150)), $walked);
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

    private static function toolBlock(
        string $text,
        string $id = 'test-id',
        string $runId = 'test-run',
        int $seq = 1,
    ): TranscriptBlock {
        return new TranscriptBlock(
            id: $id,
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: $runId,
            seq: $seq,
            text: $text,
        );
    }
}
