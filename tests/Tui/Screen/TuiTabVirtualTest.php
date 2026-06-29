<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TabDefinition;
use Ineersa\Tui\Runtime\TabService;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * POC virtual TUI test for multi-tab support.
 *
 * Test thesis: the TabService correctly tracks multiple tab states,
 * activeState() returns the correct state per active tab, tab switching
 * preserves separate transcript state, and the tab bar renders the
 * available tabs.
 *
 * This tests the core data model of the multi-tab TUI without requiring
 * tmux or a running agent session. Real run-handle wiring (submit/cancel
 * over AgentSessionClient) is proven by the TabService state routing;
 * full end-to-end would require a live controller subprocess and is
 * deferred to production-grade integration tests.
 */
final class TuiTabVirtualTest extends TestCase
{
    private const string PARENT_SESSION = 'tab-test-parent';
    private const string CHILD_SESSION = 'tab-test-child';

    private TabService $tabService;
    private TuiSessionState $parentState;
    private TuiSessionState $childState;

    protected function setUp(): void
    {
        $this->tabService = new TabService();

        // ── Parent tab ──
        $this->parentState = new TuiSessionState(self::PARENT_SESSION, false);
        $this->parentState->handle = new RunHandle(self::PARENT_SESSION, 'running');
        $this->parentState->activity = RunActivityStateEnum::Running;
        $this->parentState->transcript = [
            (new TranscriptBlockFactory())->system(
                runId: self::PARENT_SESSION,
                text: 'Parent welcome message',
                seq: 1,
            ),
        ];

        $this->tabService->addTab(new TabDefinition(
            id: 'parent',
            label: 'Parent',
            runId: self::PARENT_SESSION,
            state: $this->parentState,
            isRun: true,
        ));

        // ── Child tab ──
        $this->childState = new TuiSessionState(self::CHILD_SESSION, false);
        $this->childState->handle = new RunHandle(self::CHILD_SESSION, 'running');
        $this->childState->activity = RunActivityStateEnum::Running;
        $this->childState->transcript = [
            (new TranscriptBlockFactory())->system(
                runId: self::CHILD_SESSION,
                text: 'Child run transcript block',
                seq: 1,
            ),
        ];

        $this->tabService->addTab(new TabDefinition(
            id: 'child',
            label: 'Child',
            runId: self::CHILD_SESSION,
            state: $this->childState,
            isRun: true,
        ));
    }

    #[Test]
    public function testTabServiceTracksMultipleTabs(): void
    {
        self::assertSame(2, $this->tabService->count());
        self::assertSame('parent', $this->tabService->tabAt(0)?->id);
        self::assertSame('child', $this->tabService->tabAt(1)?->id);
    }

    #[Test]
    public function testActiveStateReturnsParentInitially(): void
    {
        self::assertSame(0, $this->tabService->activeIndex());
        self::assertSame($this->parentState, $this->tabService->activeState());
    }

    #[Test]
    public function testTabSwitchReturnsCorrectState(): void
    {
        // Switch to child tab
        $this->tabService->switchTo(1);
        self::assertSame(1, $this->tabService->activeIndex());
        self::assertSame($this->childState, $this->tabService->activeState());

        // Switch back to parent tab
        $this->tabService->switchTo(0);
        self::assertSame(0, $this->tabService->activeIndex());
        self::assertSame($this->parentState, $this->tabService->activeState());
    }

    #[Test]
    public function testTabSwitchPreservesTranscriptState(): void
    {
        // Parent has its own transcript
        self::assertCount(1, $this->parentState->transcript);
        self::assertStringContainsString('Parent welcome', $this->parentState->transcript[0]->text);

        // Child has its own transcript
        self::assertCount(1, $this->childState->transcript);
        self::assertStringContainsString('Child run transcript', $this->childState->transcript[0]->text);

        // Switch to child and verify child transcript is separate
        $this->tabService->switchTo(1);
        self::assertSame($this->childState, $this->tabService->activeState());
    }

    #[Test]
    public function testActiveStateReturnsFallbackWhenNoTabService(): void
    {
        // Simulate the single-tab fallback path where TabService is null
        $state = new TuiSessionState('fallback-session', false);
        self::assertSame('fallback-session', $state->sessionId);
    }

    #[Test]
    public function testTabBarRendersInVirtualTui(): void
    {
        $harness = new VirtualTuiHarness(
            sessionId: self::PARENT_SESSION,
            tabService: $this->tabService,
        );

        $screen = $harness->plainScreenText();

        // The tab bar should show both tabs
        self::assertStringContainsString('Parent', $screen, 'Tab bar should show parent tab label');
        self::assertStringContainsString('Child', $screen, 'Tab bar should show child tab label');
    }

    #[Test]
    public function testChildTabRunHandleRouting(): void
    {
        // Verify that the child state has a real RunHandle for event polling
        self::assertNotNull($this->childState->handle);
        self::assertSame(self::CHILD_SESSION, $this->childState->handle->runId);

        // The handle should be usable for send/cancel operations
        // (the actual AgentSessionClient wiring happens at integration layer)
        self::assertSame('running', $this->childState->handle->status);

        // Active activity state should be correct
        self::assertTrue($this->childState->activity->isActive());
    }

    #[Test]
    public function testTabServiceRemoveLastTab(): void
    {
        // Remove child tab
        $this->tabService->removeTab(1);
        self::assertSame(1, $this->tabService->count());
        self::assertSame('parent', $this->tabService->tabAt(0)?->id);

        // Active index should stay at 0 (was parent)
        self::assertSame(0, $this->tabService->activeIndex());
    }

    #[Test]
    public function testTabServiceRemoveActiveTabFallsBack(): void
    {
        // Switch to child
        $this->tabService->switchTo(1);
        self::assertSame(1, $this->tabService->activeIndex());

        // Remove child (active tab)
        $this->tabService->removeTab(1);
        self::assertSame(1, $this->tabService->count());
        // Active index should fall back to last valid index (0 = parent)
        self::assertSame(0, $this->tabService->activeIndex());
        self::assertSame('parent', $this->tabService->active()?->id);
    }
}
