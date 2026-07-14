<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Virtual proof that the working/status slot keeps a stable vertical footprint.
 *
 * Test thesis: toggling working visibility (idle ↔ hidden ↔ Working message)
 * must not shift content below the status area; without a reserved row,
 * LiveTextWidget returns zero lines when hidden and the layout jumps.
 */
final class ChatScreenStatusRowVirtualRenderTest extends TestCase
{
    private const string SESSION_ID = 'virtual-status-row-session';
    private const string FOOTER_NEEDLE = 'session virtual-status-row-session';

    #[Test]
    public function testFooterRowStableAcrossWorkingVisibilityLifecycle(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $screen = $harness->screen();

        $screen->setWorkingVisible(true);
        $screen->setWorkingMessage(null);
        $harness->render();
        $idleFooterIndex = $this->footerLineIndex($harness->plainScreenText());

        $screen->setWorkingVisible(false);
        $harness->render();
        $hiddenFooterIndex = $this->footerLineIndex($harness->plainScreenText());

        $screen->setWorkingVisible(true);
        $screen->setWorkingMessage('Working...');
        $harness->render();
        $workingFooterIndex = $this->footerLineIndex($harness->plainScreenText());

        $screen->setWorkingVisible(true);
        $screen->setWorkingMessage(null);
        $harness->render();
        $idleAgainFooterIndex = $this->footerLineIndex($harness->plainScreenText());

        $this->assertSame($idleFooterIndex, $hiddenFooterIndex, 'Hiding working row must not shift footer');
        $this->assertSame($idleFooterIndex, $workingFooterIndex, 'Working message must not shift footer');
        $this->assertSame($idleFooterIndex, $idleAgainFooterIndex, 'Returning to idle must not shift footer');
    }

    private function footerLineIndex(string $screen): int
    {
        foreach (explode("\n", $screen) as $i => $line) {
            if (str_contains($line, self::FOOTER_NEEDLE)) {
                return $i;
            }
        }

        $this->fail('Footer session label missing from virtual screen');
    }
}
