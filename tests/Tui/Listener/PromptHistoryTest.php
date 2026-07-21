<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Runtime\PromptHistory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PromptHistory::class)]
final class PromptHistoryTest extends TestCase
{
    private PromptHistory $history;

    protected function setUp(): void
    {
        $this->history = new PromptHistory();
    }

    #[Test]
    public function seedFromFiltersUserMessagesIncludingBangAndExcludesOtherKinds(): void
    {
        $transcript = [
            self::block(TranscriptBlockKindEnum::System, 'welcome'),
            self::block(TranscriptBlockKindEnum::AssistantMessage, 'hi'),
            self::block(TranscriptBlockKindEnum::UserMessage, 'hello'),
            self::block(TranscriptBlockKindEnum::ToolCall, 'tool'),
            self::block(TranscriptBlockKindEnum::UserMessage, '!ls -1'),
            self::block(TranscriptBlockKindEnum::AssistantMessage, 'done'),
        ];

        $this->history->seedFrom($transcript);

        $this->assertSame(['hello', '!ls -1'], $this->history->prompts());
    }

    #[Test]
    public function appendAddsToEnd(): void
    {
        $this->history->append('a');
        $this->history->append('b');

        $this->assertSame(['a', 'b'], $this->history->prompts());
    }

    #[Test]
    public function seedFromAfterAppendReplacesPriorSessionAppends(): void
    {
        $this->history->append('stale-live');
        $this->history->seedFrom([
            self::block(TranscriptBlockKindEnum::UserMessage, 'from-transcript'),
        ]);

        $this->assertSame(['from-transcript'], $this->history->prompts());
    }

    // ─── Empty state ──────────────────────────────────────────

    #[Test]
    public function previousOnEmptyBlocksReturnsNull(): void
    {
        $this->history->seedFrom([]);
        $result = $this->history->previous();

        $this->assertNull($result);
        $this->assertFalse($this->history->isNavigating());
    }

    #[Test]
    public function nextOnEmptyBlocksReturnsNull(): void
    {
        $this->history->seedFrom([]);
        $result = $this->history->next();

        $this->assertNull($result);
        $this->assertFalse($this->history->isNavigating());
    }

    #[Test]
    public function isNotNavigatingInitially(): void
    {
        $this->assertFalse($this->history->isNavigating());
    }

    // ─── Single user message ──────────────────────────────────

    #[Test]
    public function previousWithSingleUserMessageReturnsIt(): void
    {
        $blocks = [
            self::userBlock('hello world', 'msg-1', 'run-1', 1),
        ];

        $this->history->seedFrom($blocks);
        $result = $this->history->previous();

        $this->assertSame('hello world', $result);
        $this->assertTrue($this->history->isNavigating());
    }

    #[Test]
    public function previousTwiceWithSingleUserMessageReturnsNullSecondTime(): void
    {
        $blocks = [
            self::userBlock('only one', 'msg-1', 'run-1', 1),
        ];

        $this->history->seedFrom($blocks);
        $this->history->previous(); // first — returns text
        $result = $this->history->previous(); // second — no earlier message

        $this->assertNull($result);
        $this->assertTrue($this->history->isNavigating()); // still at the first one
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

        $this->history->seedFrom($blocks);

        // First Up — newest prompt
        $text = $this->history->previous();
        $this->assertSame('third prompt', $text);

        // Second Up — next older
        $text = $this->history->previous();
        $this->assertSame('second prompt', $text);

        // Third Up — oldest
        $text = $this->history->previous();
        $this->assertSame('first prompt', $text);

        // Fourth Up — nothing older
        $text = $this->history->previous();
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

        $this->history->seedFrom($blocks);

        // Navigate back to the oldest first
        $this->history->previous(); // third (newest)
        $this->history->previous(); // second
        $this->history->previous(); // first (oldest)

        // Now walk forward
        $text = $this->history->next();
        $this->assertSame('second prompt', $text);

        $text = $this->history->next();
        $this->assertSame('third prompt', $text);
    }

    #[Test]
    public function cursorStaysStableWhenPromptAppendedDuringNavigation(): void
    {
        $blocks = [
            self::userBlock('a', 'msg-1', 'run-1', 1),
            self::userBlock('b', 'msg-2', 'run-1', 2),
            self::userBlock('c', 'msg-3', 'run-1', 3),
        ];

        $this->history->seedFrom($blocks);

        $this->assertSame('c', $this->history->previous());
        $this->assertTrue($this->history->isNavigating());
        $this->assertSame(2, $this->history->cursor());

        $this->history->append('d');

        $this->assertTrue($this->history->isNavigating());
        $this->assertSame(2, $this->history->cursor());

        $this->assertSame('b', $this->history->previous());
        $this->assertSame(1, $this->history->cursor());

        $this->assertSame('c', $this->history->next());
        $this->assertSame(2, $this->history->cursor());

        $this->assertSame('d', $this->history->next());
        $this->assertSame(3, $this->history->cursor());
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
        $result = $this->history->previous();

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

        $this->history->seedFrom($blocks);
        $this->history->previous(); // prompt 2 (newest)
        $this->history->previous(); // prompt 1 (oldest)

        // Next walks forward in the prompt list (non-user blocks already filtered at seed)
        $text = $this->history->next();
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
        $this->history->previous(); // shows prompt 1
        $this->assertTrue($this->history->isNavigating());

        $result = $this->history->next(); // past newest

        $this->assertNull($result);
        $this->assertFalse($this->history->isNavigating());
    }

    // ─── Exit navigation ──────────────────────────────────────

    #[Test]
    public function exitNavigationResetsCursor(): void
    {
        $blocks = [
            self::userBlock('prompt 1', 'user-1', 'run-1', 1),
        ];

        $this->history->seedFrom($blocks);
        $this->history->previous();
        $this->assertTrue($this->history->isNavigating());

        $this->history->exitNavigation();
        $this->assertFalse($this->history->isNavigating());

        // After exiting, next Up starts fresh from newest
        $this->history->seedFrom($blocks);
        $result = $this->history->previous();
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
        $this->history->previous(); // index 2 (newer)
        $this->history->previous(); // index 0 (oldest)

        $this->assertSame(0, $this->history->cursor());

        // Up again — no older message, cursor stays at 0
        $result = $this->history->previous();
        $this->assertNull($result);
        $this->assertSame(0, $this->history->cursor());
        $this->assertTrue($this->history->isNavigating());
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
        $result = $this->history->previous();

        $this->assertNull($result);
        $this->assertFalse($this->history->isNavigating());
    }

    // ─── cursor for diagnostics ────────────────────

    #[Test]
    public function cursorIsNullWhenNotNavigating(): void
    {
        $this->assertNull($this->history->cursor());
    }

    #[Test]
    public function cursorReturnsBlockIndexWhenNavigating(): void
    {
        $blocks = [
            self::userBlock('prompt 1', 'user-1', 'run-1', 1),
            self::userBlock('prompt 2', 'user-2', 'run-1', 2),
        ];

        $this->history->seedFrom($blocks);
        $this->history->previous(); // index 1 (newest)

        $this->assertSame(1, $this->history->cursor());
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
            $text = $this->history->previous();
            $this->assertNotNull($text, 'Up #'.($n + 1).' should recall a prompt');
            $walked[] = $text;
        }

        $this->assertSame(array_reverse(\array_slice($expectedNewestFirst, -150)), $walked);
    }

    // ─── Helpers ──────────────────────────────────────────────

    private static function block(TranscriptBlockKindEnum $kind, string $text): TranscriptBlock
    {
        return new TranscriptBlock(
            id: 'id-'.$text,
            kind: $kind,
            runId: 'run-1',
            seq: 1,
            text: $text,
        );
    }

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
