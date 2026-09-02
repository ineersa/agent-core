<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\Tui\CompactHeader\CompactHeaderSnapshot;
use Ineersa\Tui\Listener\FooterStateSegmentProvider;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Deterministic startup layout proof without tmux.
 *
 * Test thesis: stable user-visible startup elements (logo, welcome, idle
 * status, session id in footer) render from the mounted ChatScreen tree
 * without wall-clock pane polling.
 */
final class TuiStartupVirtualRenderTest extends TestCase
{
    private const string SESSION_ID = 'virtual-startup-session';

    #[Test]
    public function testStartupLayoutRendersStableElements(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $factory = new TranscriptBlockFactory();
        $welcome = $factory->system(
            runId: self::SESSION_ID,
            text: 'Welcome to Hatfield. Type a message below to start.',
            seq: 1,
        );

        $harness->screen()->setTranscriptBlocks([$welcome]);
        $harness->screen()->setWorkingVisible(true);
        $harness->screen()->setWorkingMessage(null);

        $screen = $harness->plainScreenText();

        $this->assertStringContainsString('█', $screen, 'Hatfield logo (box drawing) missing');
        $this->assertStringContainsString('Welcome to Hatfield', $screen, 'Welcome message missing');
        $this->assertStringContainsString('● idle', $screen, 'Idle working status missing');
        $this->assertStringContainsString('session '.self::SESSION_ID, $screen, 'Session id in footer missing');
    }

    #[Test]
    public function testFooterRendersElapsedTimeToMinutePrecision(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $state = new TuiSessionState(self::SESSION_ID);
        $state->sessionStartTime = microtime(true) - 125.0;
        $harness->screen()->addFooterProvider(new FooterStateSegmentProvider($state));

        $screen = $harness->plainScreenText();

        $this->assertStringContainsString('⏱ 2m', $screen);
        $this->assertDoesNotMatchRegularExpression('/⏱ [^\n]*\d+s/', $screen);
    }

    #[Test]
    public function testNoopWorkingVisibilityDoesNotInvalidateWidget(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $screen = $harness->screen();

        $initialRevision = $this->workingWidgetRenderRevision($screen);
        $screen->setWorkingVisible(true);
        $this->assertSame($initialRevision, $this->workingWidgetRenderRevision($screen));

        $screen->setWorkingVisible(false);
        $hiddenRevision = $this->workingWidgetRenderRevision($screen);
        $this->assertGreaterThan($initialRevision, $hiddenRevision);

        $screen->setWorkingVisible(false);
        $this->assertSame($hiddenRevision, $this->workingWidgetRenderRevision($screen));
    }

    #[Test]
    public function testNoopWorkingMessageNullDoesNotInvalidateWidget(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $screen = $harness->screen();

        $screen->setWorkingMessage(null);
        $idleRevision = $this->workingWidgetRenderRevision($screen);

        $screen->setWorkingMessage(null);
        $this->assertSame($idleRevision, $this->workingWidgetRenderRevision($screen));

        $screen->setWorkingMessage(null);
        $this->assertSame($idleRevision, $this->workingWidgetRenderRevision($screen));
    }

    /**
     * Replaces the deleted CompactHeaderPinnedOrderTest: the directly mounted
     * compact header renders between the status panel and the editor separator,
     * i.e. after transcript/status chrome and before the footer.
     */
    #[Test]
    public function testChromeOrderHeaderStatusCompactFooterOnMountedScreen(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $screen = $harness->screen();
        $factory = new TranscriptBlockFactory();
        $screen->setTranscriptBlocks([
            $factory->system(runId: self::SESSION_ID, text: 'anchor transcript', seq: 1),
        ]);
        $screen->setStatus('agents-live', 'child: running');
        $screen->compactHeaderWidget()->setSnapshot(new CompactHeaderSnapshot(
            prompts: ['review'],
            skills: ['pinned-skill'],
        ));
        $screen->setWorkingVisible(true);
        $screen->setWorkingMessage(null);

        $plain = $harness->plainScreenText();

        // Header logo at the top…
        $logoPos = strpos($plain, '█');
        $this->assertNotFalse($logoPos, 'Header logo missing');
        // …status panel after the transcript…
        $statusPos = strpos($plain, 'agents-live');
        $this->assertNotFalse($statusPos, 'Status panel entry missing');
        // …compact header after the status panel…
        $skillPos = strpos($plain, 'pinned-skill');
        $this->assertNotFalse($skillPos, 'Compact header snapshot missing');
        // …footer last.
        $footerPos = strpos($plain, 'session '.self::SESSION_ID);
        $this->assertNotFalse($footerPos, 'Footer session label missing');

        $this->assertLessThan($statusPos, $logoPos ?: \PHP_INT_MAX);
        $this->assertLessThan($skillPos, $statusPos);
        $this->assertLessThan($footerPos, $skillPos);
    }

    #[Test]
    public function testPendingMessagesRenderAboveWorkingStatus(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $screen = $harness->screen();
        $factory = new TranscriptBlockFactory();
        $screen->setTranscriptBlocks([
            $factory->system(runId: self::SESSION_ID, text: 'anchor transcript', seq: 1),
        ]);

        $screen->syncQueuedUserMessages(['k1' => 'Message queued — waiting for compaction to complete...']);
        $screen->setWorkingVisible(true);
        $screen->setWorkingMessage('Working...');

        $plain = $harness->plainScreenText();

        $pendingPos = strpos($plain, '⏳ Message queued');
        $this->assertNotFalse($pendingPos, 'Pending message row missing');
        $workingPos = strpos($plain, 'Working...');
        $this->assertNotFalse($workingPos, 'Working row missing');
        $this->assertLessThan($workingPos, $pendingPos, 'Pending messages must render above the working status');

        // Clearing the queue removes the pending row.
        $screen->syncQueuedUserMessages([]);
        $plain = $harness->plainScreenText();
        $this->assertStringNotContainsString('⏳ Message queued', $plain);
    }

    #[Test]
    public function testChromeRowsReflowAtNarrowWidthAfterResize(): void
    {
        $harness = new VirtualTuiHarness(columns: 120, rows: 80, sessionId: self::SESSION_ID);
        $screen = $harness->screen();
        $factory = new TranscriptBlockFactory();
        $screen->setTranscriptBlocks([
            $factory->system(runId: self::SESSION_ID, text: 'anchor transcript', seq: 1),
        ]);
        $screen->setStatus('agents-live', 'child: a very long status message that should wrap on narrow terminals');
        $screen->compactHeaderWidget()->setSnapshot(new CompactHeaderSnapshot(
            prompts: ['one', 'two', 'three', 'four', 'five', 'six'],
        ));
        $screen->setWorkingVisible(true);
        $screen->setWorkingMessage(null);

        $wide = $harness->plainScreenText();

        $harness->terminal()->simulateResize(40, 80);
        $harness->render();
        $narrow = $harness->plainScreenText();

        $this->assertNotSame($wide, $narrow, 'Resize must reflow mounted chrome rows');
        $this->assertStringContainsString('█', $narrow, 'Header logo must survive narrow reflow');
        $this->assertStringContainsString('session '.self::SESSION_ID, $narrow, 'Footer must survive narrow reflow');
        $this->assertStringContainsString('agents-live', $narrow, 'Status panel must survive narrow reflow');

        foreach (explode("\n", $narrow) as $i => $line) {
            $this->assertLessThanOrEqual(
                40,
                AnsiUtils::visibleWidth($line),
                "mounted row {$i} exceeds narrow width after resize",
            );
        }
    }

    private function workingWidgetRenderRevision(ChatScreen $screen): int
    {
        $property = new \ReflectionProperty($screen, 'workingWidget');
        $widget = $property->getValue($screen);
        $this->assertInstanceOf(AbstractWidget::class, $widget);

        return $widget->getRenderRevision();
    }
}
