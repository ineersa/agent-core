<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Virtual proof that the working/status slot keeps a stable vertical footprint
 * and drives the native circle LoaderWidget through ChatScreen lifecycle APIs.
 *
 * Test thesis: toggling working visibility (idle ↔ hidden ↔ Working message)
 * must not shift content below the status area; active work must animate the
 * built-in circle spinner via the real Tui tick/render path; clearing must
 * restore the native finished idle state.
 *
 * Footer anchor: last full-width separator line immediately above the footer
 * session label (same naming as tmux E2E helper; stable in VirtualTerminal).
 */
final class ChatScreenStatusRowVirtualRenderTest extends TestCase
{
    private const string SESSION_ID = 'virtual-status-row-session';
    private const string FOOTER_NEEDLE = 'session virtual-status-row-session';

    /** Built-in LoaderWidget "circle" frames. */
    private const array CIRCLE_FRAMES = ['◐', '◓', '◑', '◒'];

    #[Test]
    public function testFooterAndEditorRegionAnchorsStableAcrossWorkingVisibilityLifecycle(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $screen = $harness->screen();
        $factory = new TranscriptBlockFactory();
        $screen->setTranscriptBlocks([
            $factory->system(runId: self::SESSION_ID, text: 'anchor transcript', seq: 1),
        ]);

        $screen->setWorkingVisible(true);
        $screen->setWorkingMessage(null);
        $harness->render();
        $idleText = $harness->plainScreenText();
        $this->assertStringContainsString('● idle', $idleText);
        $idleFooterIndex = $this->footerLineIndex($idleText);
        $idleSepIndex = $this->footerSeparatorLineIndexAboveFooter($idleText);

        $screen->setWorkingVisible(false);
        $harness->render();
        $hiddenText = $harness->plainScreenText();
        $this->assertStringNotContainsString('● idle', $hiddenText);
        $this->assertStringNotContainsString('Working...', $hiddenText);
        $this->assertSame([], $this->circleFramesIn($hiddenText), 'Hidden working slot must not show circle spinner frames');
        $hiddenFooterIndex = $this->footerLineIndex($hiddenText);
        $hiddenSepIndex = $this->footerSeparatorLineIndexAboveFooter($hiddenText);

        $screen->setWorkingVisible(true);
        $screen->setWorkingMessage('Working...');
        $harness->render();
        $workingText = $harness->plainScreenText();
        $firstFrame = $this->firstCircleFrameIn($workingText);
        $this->assertNotNull($firstFrame, 'Circle loader frame missing while working');
        $this->assertStringContainsString('Working...', $workingText);
        $this->assertStringNotContainsString('● idle', $workingText);
        $workingFooterIndex = $this->footerLineIndex($workingText);
        $workingSepIndex = $this->footerSeparatorLineIndexAboveFooter($workingText);

        // Advance past LoaderWidget's 80ms frame interval via the real Tui tick path.
        usleep(120_000);
        $harness->tui()->tick();
        $harness->render();
        $animatedText = $harness->plainScreenText();
        $nextFrame = $this->firstCircleFrameIn($animatedText);
        $this->assertNotNull($nextFrame, 'Circle loader frame missing after tick');
        $this->assertNotSame($firstFrame, $nextFrame, 'LoaderWidget must advance to a different circle frame after its interval');
        $this->assertStringContainsString('Working...', $animatedText);
        $this->assertContains($nextFrame, self::CIRCLE_FRAMES);

        $screen->setWorkingMessage('Compacting...');
        $harness->render();
        $updatedText = $harness->plainScreenText();
        $this->assertStringContainsString('Compacting...', $updatedText);
        $this->assertStringNotContainsString('Working...', $updatedText);
        $this->assertNotNull($this->firstCircleFrameIn($updatedText), 'Circle frame must remain while message updates');

        $screen->setWorkingVisible(true);
        $screen->setWorkingMessage(null);
        $harness->render();
        $idleAgainText = $harness->plainScreenText();
        $this->assertStringContainsString('● idle', $idleAgainText);
        $this->assertStringNotContainsString('Compacting...', $idleAgainText);
        $this->assertSame([], $this->circleFramesIn($idleAgainText), 'Idle finished state must not keep circle spinner frames');
        $idleAgainFooterIndex = $this->footerLineIndex($idleAgainText);
        $idleAgainSepIndex = $this->footerSeparatorLineIndexAboveFooter($idleAgainText);

        $this->assertSame($idleFooterIndex, $hiddenFooterIndex, 'Hiding working row must not shift footer');
        $this->assertSame($idleFooterIndex, $workingFooterIndex, 'Working message must not shift footer');
        $this->assertSame($idleFooterIndex, $idleAgainFooterIndex, 'Returning to idle must not shift footer');

        $this->assertSame($idleSepIndex, $hiddenSepIndex, 'Hiding working row must not shift footer separator');
        $this->assertSame($idleSepIndex, $workingSepIndex, 'Working message must not shift footer separator');
        $this->assertSame($idleSepIndex, $idleAgainSepIndex, 'Returning to idle must not shift footer separator');
    }

    private function firstCircleFrameIn(string $screen): ?string
    {
        $frames = $this->circleFramesIn($screen);

        return $frames[0] ?? null;
    }

    /**
     * @return list<string>
     */
    private function circleFramesIn(string $screen): array
    {
        $found = [];
        foreach (self::CIRCLE_FRAMES as $frame) {
            if (str_contains($screen, $frame)) {
                $found[] = $frame;
            }
        }

        return $found;
    }

    private function footerLineIndex(string $screen): int
    {
        return $this->lineIndex($screen, self::FOOTER_NEEDLE);
    }

    private function footerSeparatorLineIndexAboveFooter(string $screen): int
    {
        $lines = explode("\n", $screen);
        $footerIndex = $this->footerLineIndex($screen);

        for ($i = $footerIndex - 1; $i >= 0; --$i) {
            if (str_contains($lines[$i], '─')) {
                return $i;
            }
        }

        $this->fail('Footer separator line missing above footer anchor in virtual screen');
    }

    private function lineIndex(string $screen, string $needle): int
    {
        foreach (explode("\n", $screen) as $i => $line) {
            if (str_contains($line, $needle)) {
                return $i;
            }
        }

        $this->fail('Anchor line missing from virtual screen: '.$needle);
    }
}
