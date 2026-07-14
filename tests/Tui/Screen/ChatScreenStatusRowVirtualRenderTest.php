<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Virtual proof that the working/status slot keeps a stable vertical footprint.
 *
 * Test thesis: toggling working visibility (idle ↔ hidden ↔ Working message)
 * must not shift content below the status area; without a reserved row,
 * LiveTextWidget returns zero lines when hidden and the layout jumps.
 *
 * Footer anchor: last full-width separator line immediately above the footer
 * session label (same naming as tmux E2E helper; stable in VirtualTerminal).
 */
final class ChatScreenStatusRowVirtualRenderTest extends TestCase
{
    private const string SESSION_ID = 'virtual-status-row-session';
    private const string FOOTER_NEEDLE = 'session virtual-status-row-session';

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
        $idleFooterIndex = $this->footerLineIndex($harness->plainScreenText());
        $idleSepIndex = $this->footerSeparatorLineIndexAboveFooter($harness->plainScreenText());

        $screen->setWorkingVisible(false);
        $harness->render();
        $hiddenFooterIndex = $this->footerLineIndex($harness->plainScreenText());
        $hiddenSepIndex = $this->footerSeparatorLineIndexAboveFooter($harness->plainScreenText());

        $screen->setWorkingVisible(true);
        $screen->setWorkingMessage('Working...');
        $harness->render();
        $workingFooterIndex = $this->footerLineIndex($harness->plainScreenText());
        $workingSepIndex = $this->footerSeparatorLineIndexAboveFooter($harness->plainScreenText());

        $screen->setWorkingVisible(true);
        $screen->setWorkingMessage(null);
        $harness->render();
        $idleAgainFooterIndex = $this->footerLineIndex($harness->plainScreenText());
        $idleAgainSepIndex = $this->footerSeparatorLineIndexAboveFooter($harness->plainScreenText());

        $this->assertSame($idleFooterIndex, $hiddenFooterIndex, 'Hiding working row must not shift footer');
        $this->assertSame($idleFooterIndex, $workingFooterIndex, 'Working message must not shift footer');
        $this->assertSame($idleFooterIndex, $idleAgainFooterIndex, 'Returning to idle must not shift footer');

        $this->assertSame($idleSepIndex, $hiddenSepIndex, 'Hiding working row must not shift footer separator');
        $this->assertSame($idleSepIndex, $workingSepIndex, 'Working message must not shift footer separator');
        $this->assertSame($idleSepIndex, $idleAgainSepIndex, 'Returning to idle must not shift footer separator');
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
