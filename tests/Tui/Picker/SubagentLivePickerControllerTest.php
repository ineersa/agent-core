<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Picker;

use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotProviderInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\Tui\Listener\RuntimeQuestionEventHandler;
use Ineersa\Tui\Picker\SubagentLivePickerController;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Widget\SelectListWidget;

final class SubagentLivePickerControllerTest extends TestCase
{
    #[Test]
    public function testOpenTwiceDoesNotStackOverlay(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'picker-idempotent');
        $state = new TuiSessionState('picker-idempotent');
        $this->seedCatalogChild($state, 'agent_a', 'child-run-1', 'running');

        $picker = $this->picker($harness, $state);
        $picker->open();
        $this->assertTrue($picker->isOpen());
        $picker->open();
        $this->assertTrue($picker->isOpen());
    }

    #[Test]
    public function dismissKeyDoesNotRemoveRunningChild(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'picker-dismiss');
        $state = new TuiSessionState('picker-dismiss');
        $this->seedCatalogChild($state, 'agent_running', 'child-run-running', 'running');

        $picker = $this->picker($harness, $state);
        $this->invokeDismissSelected($picker, $harness->screen(), $state);

        $this->assertCount(1, $state->subagentLiveCatalog->all());
        $this->assertStringContainsString(
            'Cannot remove active subagent scout',
            $this->workingMessage($harness->screen()),
        );
    }

    #[Test]
    public function dismissKeyRemovesCompletedChild(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'picker-dismiss-done');
        $state = new TuiSessionState('picker-dismiss-done');
        $this->seedCatalogChild($state, 'agent_done', 'child-run-done', 'completed');

        $picker = $this->picker($harness, $state);
        $this->invokeDismissSelected($picker, $harness->screen(), $state);

        $this->assertCount(0, $state->subagentLiveCatalog->all());
        $msg = $this->workingMessage($harness->screen());
        // Last child dismissed: no working flash, status cleared
        $this->assertSame('', $msg);
        $entries = $this->statusEntries($harness->screen());
        $this->assertArrayNotHasKey('agents-live', $entries, 'agents-live status should be cleared after last dismiss');
    }

    #[Test]
    public function testEmptyOpenClearsWorkingMessageAndStatus(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'picker-empty-open');
        $state = new TuiSessionState('picker-empty-open');

        $picker = $this->picker($harness, $state);
        $picker->open();

        $this->assertFalse($picker->isOpen(), 'Picker should not open when catalog is empty');
        $msg = $this->workingMessage($harness->screen());
        $this->assertSame('', $msg, 'Working message should be empty');
        $entries = $this->statusEntries($harness->screen());
        $this->assertArrayNotHasKey('agents-live', $entries, 'agents-live status should be absent/cleared');
    }

    #[Test]
    public function enterLiveViewCallsSnapshotProviderOnceAndCachesTranscript(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'picker-enter-snapshot');
        $state = new TuiSessionState('picker-enter-snapshot');
        $this->seedCatalogChild($state, 'agent_snap', 'child-run-snap', 'running');

        $child = $state->subagentLiveCatalog->findByArtifactId('agent_snap');
        $this->assertNotNull($child);

        $block = new \Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock(
            'snap-b',
            \Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum::Progress,
            'child-run-snap',
            4,
            'snapshot line',
        );

        $snapshotProvider = $this->createMock(ChildRunTranscriptSnapshotProviderInterface::class);
        $snapshotProvider->expects($this->once())
            ->method('snapshot')
            ->with('child-run-snap')
            ->willReturn(new ChildRunTranscriptSnapshotDTO([$block], [], 4));

        $picker = new SubagentLivePickerController(
            new SubagentLiveChildViewPoller(
                new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()),
                new NullLogger(),
            ),
            $snapshotProvider,
            new RuntimeQuestionEventHandler(),
            new QuestionCoordinator(),
            (new \ReflectionClass(QuestionController::class))->newInstanceWithoutConstructor(),
        );
        $picker->setRuntimeRefs($harness->tui(), $harness->screen(), $state);

        $method = new \ReflectionMethod(SubagentLivePickerController::class, 'enterLiveView');
        $method->invoke($picker, $child, $state, $harness->screen());

        $this->assertSame(4, $state->subagentLiveView->childLastSeq);
        $this->assertSame('snapshot line', $state->subagentLiveView->childTranscript[0]->text);
        $this->assertArrayHasKey('child-run-snap', $state->subagentLiveView->childCaches);

        $snapshotProvider->expects($this->never())->method('snapshot');
        $method->invoke($picker, $child, $state, $harness->screen());
        $this->assertSame(4, $state->subagentLiveView->childLastSeq);
    }

    private function picker(VirtualTuiHarness $harness, TuiSessionState $state): SubagentLivePickerController
    {
        $picker = new SubagentLivePickerController(
            new SubagentLiveChildViewPoller(
                new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()),
                new NullLogger(),
            ),
            $this->createStub(ChildRunTranscriptSnapshotProviderInterface::class),
            new RuntimeQuestionEventHandler(),
            new QuestionCoordinator(),
            (new \ReflectionClass(QuestionController::class))->newInstanceWithoutConstructor(),
        );
        $picker->setRuntimeRefs($harness->tui(), $harness->screen(), $state);

        return $picker;
    }

    private function invokeDismissSelected(
        SubagentLivePickerController $picker,
        ChatScreen $screen,
        TuiSessionState $state,
    ): void {
        $items = SubagentLivePickerController::buildItems($state->subagentLiveCatalog->all(), $screen->theme());
        $listWidget = new SelectListWidget(items: $items, keybindings: new Keybindings());
        $listWidget->setSelectedIndex(0);

        $method = new \ReflectionMethod(SubagentLivePickerController::class, 'dismissSelected');
        $children = $state->subagentLiveCatalog->all();
        $method->invokeArgs($picker, [&$listWidget, &$children, $screen->theme(), $screen, $state]);
    }

    private function seedCatalogChild(TuiSessionState $state, string $artifactId, string $runId, string $status): void
    {
        $state->subagentLiveCatalog->ingestRuntimeEvent(new RuntimeEvent(
            type: RuntimeEventTypeEnum::ToolExecutionOutputDelta->value,
            runId: 'parent-run',
            seq: 1,
            payload: [
                'tool_call_id' => 'tc_subagent',
                'tool_name' => 'subagent',
                'delta' => '',
                'subagent_progress' => [
                    'mode' => 'single',
                    'status' => $status,
                    'agent_name' => 'scout',
                    'artifact_id' => $artifactId,
                    'agent_run_id' => $runId,
                    'task_summary' => 'task',
                ],
            ],
        ));
    }

    private function workingMessage(ChatScreen $screen): string
    {
        $ref = new \ReflectionClass($screen);
        $registry = $ref->getProperty('registry');

        return $registry->getValue($screen)->getWorkingMessage();
    }

    /**
     * @return array<string, string>
     */
    private function statusEntries(ChatScreen $screen): array
    {
        $ref = new \ReflectionClass($screen);
        $registry = $ref->getProperty('registry');

        return $registry->getValue($screen)->getStatusEntries();
    }
}
