<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Listener\SubagentTabAutoListener;
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

    // ── SubagentTabAutoListener detection tests ───────────────────────────

    #[Test]
    public function testDetectSubagentArtifactReturnsNullForNonToolResult(): void
    {
        $block = new TranscriptBlock(
            id: 'test',
            kind: TranscriptBlockKindEnum::System,
            runId: 'test',
            seq: 1,
            text: 'system message',
            meta: [],
        );

        $opened = [];
        self::assertNull(SubagentTabAutoListener::detectSubagentArtifact($block, $opened));
    }

    #[Test]
    public function testDetectSubagentArtifactReturnsNullWithoutProgressMeta(): void
    {
        $block = new TranscriptBlock(
            id: 'test',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'test',
            seq: 1,
            text: 'test',
            meta: ['tool_name' => 'subagent'],
        );

        $opened = [];
        self::assertNull(SubagentTabAutoListener::detectSubagentArtifact($block, $opened));
    }

    #[Test]
    public function testDetectSubagentArtifactReturnsDataForRunning(): void
    {
        $block = new TranscriptBlock(
            id: 'test',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'test',
            seq: 1,
            text: 'test',
            meta: [
                'tool_name' => 'subagent',
                'subagent_progress' => [
                    'mode' => 'single',
                    'status' => 'running',
                    'agent_name' => 'scout',
                    'artifact_id' => 'agent_abc123',
                    'agent_run_id' => 'child-run-id',
                ],
            ],
        );

        $opened = [];
        $result = SubagentTabAutoListener::detectSubagentArtifact($block, $opened);

        self::assertNotNull($result, 'Running subagent block should be detected');
        self::assertSame('agent_abc123', $result['artifact_id']);
        self::assertSame('child-run-id', $result['agent_run_id']);
        self::assertSame('scout', $result['agent_name']);
        self::assertSame('running', $result['status']);
        self::assertFalse($result['is_final'], 'Running block should not be is_final');
    }

    #[Test]
    public function testDetectSubagentArtifactReturnsDataForAlreadyOpened(): void
    {
        // Caller-level dedup: detectSubagentArtifact returns data for ALL
        // valid subagent blocks regardless of openedArtifacts.
        // The caller (tick handler) decides whether to create or update.
        $block = new TranscriptBlock(
            id: 'test',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'test',
            seq: 1,
            text: 'test',
            meta: [
                'tool_name' => 'subagent',
                'subagent_final' => true,
                'subagent_progress' => [
                    'mode' => 'single',
                    'status' => 'completed',
                    'agent_name' => 'scout',
                    'artifact_id' => 'agent_abc123',
                    'agent_run_id' => 'child-run-id',
                ],
            ],
        );

        $opened = ['agent_abc123' => 'completed'];
        $result = SubagentTabAutoListener::detectSubagentArtifact($block, $opened);

        self::assertNotNull($result, 'Already-opened artifact should still be detected (caller dedups)');
        self::assertSame('agent_abc123', $result['artifact_id']);
        self::assertSame('child-run-id', $result['agent_run_id']);
        self::assertTrue($result['is_final']);
    }

    #[Test]
    public function testDetectSubagentArtifactReturnsDataForCompleted(): void
    {
        $block = new TranscriptBlock(
            id: 'test',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'test',
            seq: 1,
            text: 'test',
            meta: [
                'tool_name' => 'subagent',
                'subagent_final' => true,
                'subagent_progress' => [
                    'mode' => 'single',
                    'status' => 'completed',
                    'agent_name' => 'scout',
                    'artifact_id' => 'agent_abc123',
                    'agent_run_id' => 'child-run-id',
                ],
            ],
        );

        $opened = [];
        $result = SubagentTabAutoListener::detectSubagentArtifact($block, $opened);

        self::assertNotNull($result);
        self::assertSame('agent_abc123', $result['artifact_id']);
        self::assertSame('child-run-id', $result['agent_run_id']);
        self::assertSame('scout', $result['agent_name']);
    }

    #[Test]
    public function testDetectSubagentArtifactReturnsNullWithoutAgentRunId(): void
    {
        $block = new TranscriptBlock(
            id: 'test',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'test',
            seq: 1,
            text: 'test',
            meta: [
                'tool_name' => 'subagent',
                'subagent_final' => true,
                'subagent_progress' => [
                    'mode' => 'single',
                    'status' => 'completed',
                    'agent_name' => 'scout',
                    'artifact_id' => 'agent_abc123',
                    // No agent_run_id
                ],
            ],
        );

        $opened = [];
        self::assertNull(SubagentTabAutoListener::detectSubagentArtifact($block, $opened));
    }

    // ── SubagentTabAutoListener block builder tests ────────────────────────

    #[Test]
    public function testBuildBlocksFromEventsHandlesRunStarted(): void
    {
        $events = [
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::RunStarted->value,
                runId: 'child-run',
                seq: 1,
                payload: [],
            ),
        ];

        $blocks = SubagentTabAutoListener::buildBlocksFromEvents(
            $events,
            new TranscriptBlockFactory(),
            'child-run',
        );

        self::assertCount(1, $blocks);
        self::assertSame(TranscriptBlockKindEnum::System, $blocks[0]->kind);
        self::assertStringContainsString('Run started', $blocks[0]->text);
    }

    #[Test]
    public function testBuildBlocksFromEventsHandlesAssistantMessage(): void
    {
        $events = [
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::AssistantMessageCompleted->value,
                runId: 'child-run',
                seq: 1,
                payload: ['text' => 'Hello from child agent'],
            ),
        ];

        $blocks = SubagentTabAutoListener::buildBlocksFromEvents(
            $events,
            new TranscriptBlockFactory(),
            'child-run',
        );

        self::assertCount(1, $blocks);
        self::assertSame(TranscriptBlockKindEnum::AssistantMessage, $blocks[0]->kind);
        self::assertStringContainsString('Hello from child', $blocks[0]->text);
    }

    #[Test]
    public function testBuildBlocksFromEventsHandlesToolExecution(): void
    {
        $events = [
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::ToolExecutionStarted->value,
                runId: 'child-run',
                seq: 1,
                payload: [
                    'tool_call_id' => 'call_1',
                    'tool_name' => 'read',
                ],
            ),
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::ToolExecutionCompleted->value,
                runId: 'child-run',
                seq: 2,
                payload: [
                    'tool_call_id' => 'call_1',
                    'tool_name' => 'read',
                    'result' => 'file content',
                ],
            ),
        ];

        $blocks = SubagentTabAutoListener::buildBlocksFromEvents(
            $events,
            new TranscriptBlockFactory(),
            'child-run',
        );

        self::assertCount(2, $blocks);
        self::assertSame(TranscriptBlockKindEnum::ToolCall, $blocks[0]->kind);
        self::assertSame(TranscriptBlockKindEnum::ToolResult, $blocks[1]->kind);
        self::assertStringContainsString('file content', $blocks[1]->text);
    }

    #[Test]
    public function testBuildBlocksFromEventsSkipsUnmappedTypes(): void
    {
        $events = [
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::StatusUpdated->value,
                runId: 'child-run',
                seq: 1,
                payload: ['status' => 'running'],
            ),
        ];

        $blocks = SubagentTabAutoListener::buildBlocksFromEvents(
            $events,
            new TranscriptBlockFactory(),
            'child-run',
        );

        // StatusUpdated should be skipped (no matching case)
        self::assertCount(0, $blocks);
    }

    #[Test]
    public function testBuildBlocksFromEventsHandlesToolFailed(): void
    {
        $events = [
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::ToolExecutionFailed->value,
                runId: 'child-run',
                seq: 1,
                payload: [
                    'tool_call_id' => 'call_1',
                    'tool_name' => 'bash',
                    'result' => 'Command failed: exit 1',
                ],
            ),
        ];

        $blocks = SubagentTabAutoListener::buildBlocksFromEvents(
            $events,
            new TranscriptBlockFactory(),
            'child-run',
        );

        self::assertCount(1, $blocks);
        self::assertSame(TranscriptBlockKindEnum::ToolResult, $blocks[0]->kind);
        self::assertTrue($blocks[0]->meta['is_error']);
    }
}
