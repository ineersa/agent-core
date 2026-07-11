<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\TranscriptDisplayConfig;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatScreen::class)]
final class ChatScreenTranscriptUpdateTest extends TestCase
{
    #[Test]
    public function setTranscriptBlocksMergesChangedOnlyBlocks(): void
    {
        $screen = $this->screen();
        $screen->setTranscriptBlocks([
            new TranscriptBlock('b1', TranscriptBlockKindEnum::UserMessage, 'run', 1, 'hello'),
            new TranscriptBlock('b2', TranscriptBlockKindEnum::AssistantMessage, 'run', 2, 'draft'),
        ]);

        $screen->setTranscriptBlocks([
            new TranscriptBlock('b2', TranscriptBlockKindEnum::AssistantMessage, 'run', 2, 'final'),
        ]);

        $ref = new \ReflectionClass($screen);
        $renderable = $ref->getProperty('transcriptRenderable')->getValue($screen);
        $merged = $renderable->getBlocks();
        $this->assertCount(2, $merged);
        $this->assertSame('hello', $merged[0]->text);
        $this->assertSame('final', $merged[1]->text);
    }

    #[Test]
    public function setTranscriptBlocksWithIdenticalFullListPreservesRenderableBlocks(): void
    {
        $screen = $this->screen();
        $blocks = [
            new TranscriptBlock('b1', TranscriptBlockKindEnum::UserMessage, 'run', 1, 'hello'),
        ];
        $screen->setTranscriptBlocks($blocks);
        $ref = new \ReflectionClass($screen);
        $renderable = $ref->getProperty('transcriptRenderable')->getValue($screen);
        $before = $renderable->getBlocks();
        $screen->setTranscriptBlocks($blocks);
        $after = $renderable->getBlocks();
        $this->assertSame($before[0]->text, $after[0]->text);
        $this->assertCount(1, $after);
    }

    private function screen(): ChatScreen
    {
        return new ChatScreen(
            new DefaultTheme(new ThemePalette('test')),
            'session-eq',
            new PromptEditor(),
            new TranscriptDisplayConfig(),
            new TranscriptDisplayState(),
        );
    }
}
