<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Listener\PromptHistory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PromptHistory::class)]
final class PromptHistoryTest extends TestCase
{
    #[Test]
    public function seedFromFiltersUserMessagesIncludingBangAndExcludesOtherKinds(): void
    {
        $history = new PromptHistory();
        $transcript = [
            self::block(TranscriptBlockKindEnum::System, 'welcome'),
            self::block(TranscriptBlockKindEnum::AssistantMessage, 'hi'),
            self::block(TranscriptBlockKindEnum::UserMessage, 'hello'),
            self::block(TranscriptBlockKindEnum::ToolCall, 'tool'),
            self::block(TranscriptBlockKindEnum::UserMessage, '!ls -1'),
            self::block(TranscriptBlockKindEnum::AssistantMessage, 'done'),
        ];

        $history->seedFrom($transcript);

        $this->assertSame(['hello', '!ls -1'], $history->prompts());
    }

    #[Test]
    public function appendAddsToEnd(): void
    {
        $history = new PromptHistory();
        $history->append('a');
        $history->append('b');

        $this->assertSame(['a', 'b'], $history->prompts());
    }

    #[Test]
    public function seedFromAfterAppendReplacesPriorSessionAppends(): void
    {
        $history = new PromptHistory();
        $history->append('stale-live');
        $history->seedFrom([
            self::block(TranscriptBlockKindEnum::UserMessage, 'from-transcript'),
        ]);

        $this->assertSame(['from-transcript'], $history->prompts());
    }

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
}
