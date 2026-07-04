<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Runtime;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\SubagentLiveAttention;
use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\TranscriptDisplayConfig;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use PHPUnit\Framework\TestCase;

/** @covers \Ineersa\Tui\Runtime\SubagentLiveAttention */
final class SubagentLiveAttentionTest extends TestCase
{
    public function testClearWaitingHumanClearsSubagentLiveStatusWhileLiveViewActive(): void
    {
        $state = new TuiSessionState('parent-session');
        $state->subagentLiveView->enter(new SubagentLiveChildDTO(
            agentRunId: 'child-run-1',
            artifactId: 'agent_a',
            agentName: 'scout',
            status: SubagentLiveStatusEnum::WaitingHuman,
            taskSummary: 'Task',
            lastActivityAtMs: 1,
        ));
        $state->subagentLiveView->childActivity = RunActivityStateEnum::WaitingHuman;
        $state->subagentLiveCatalog->ingestRuntimeEvent($this->progressEvent([
            'mode' => 'single', 'status' => 'waiting_human', 'agent_name' => 'scout',
            'artifact_id' => 'agent_a', 'agent_run_id' => 'child-run-1', 'task_summary' => 'Task',
        ]));

        $screen = new ChatScreen(
            new DefaultTheme(new ThemePalette('test')),
            'parent-session',
            new PromptEditor(),
            new TranscriptDisplayConfig(),
            new TranscriptDisplayState(),
        );
        $screen->setStatus('subagent_live', '⚠ Subagent scout needs your input — /agents-live');

        SubagentLiveAttention::clearWaitingHumanForRun($state, $screen, 'child-run-1');

        self::assertNull($state->subagentLiveCatalog->firstChildNeedingAttention());
        self::assertNull($this->statusText($screen, 'subagent_live'));
    }

    public function testMarkCancelledClearsNeedsInputWhileLiveViewActive(): void
    {
        $state = new TuiSessionState('parent-session');
        $state->subagentLiveView->enter(new SubagentLiveChildDTO(
            agentRunId: 'child-run-1',
            artifactId: 'agent_a',
            agentName: 'scout',
            status: SubagentLiveStatusEnum::WaitingHuman,
            taskSummary: 'Task',
            lastActivityAtMs: 1,
        ));
        $state->subagentLiveCatalog->ingestRuntimeEvent($this->progressEvent([
            'mode' => 'single', 'status' => 'waiting_human', 'agent_name' => 'scout',
            'artifact_id' => 'agent_a', 'agent_run_id' => 'child-run-1', 'task_summary' => 'Task',
        ]));

        $screen = new ChatScreen(
            new DefaultTheme(new ThemePalette('test')),
            'parent-session',
            new PromptEditor(),
            new TranscriptDisplayConfig(),
            new TranscriptDisplayState(),
        );
        $screen->setStatus('subagent_live', '⚠ Subagent scout needs your input — /agents-live');

        SubagentLiveAttention::markCancelledForRun($state, $screen, 'child-run-1');

        $child = $state->subagentLiveCatalog->findByArtifactId('agent_a');
        self::assertNotNull($child);
        self::assertSame(SubagentLiveStatusEnum::Cancelled, $child->status);
        self::assertNull($state->subagentLiveCatalog->firstChildNeedingAttention());
        self::assertNull($this->statusText($screen, 'subagent_live'));
        self::assertNull($this->statusText($screen, 'agents-live'));
    }


    public function testMarkActiveChildrenCancelledForParentCancelClearsWaitingHumanOnMain(): void
    {
        $state = new TuiSessionState('parent-session');
        $state->subagentLiveCatalog->ingestRuntimeEvent($this->progressEvent([
            'mode' => 'single', 'status' => 'waiting_human', 'agent_name' => 'scout',
            'artifact_id' => 'agent_a', 'agent_run_id' => 'child-run-1', 'task_summary' => 'Task',
        ]));

        $screen = new ChatScreen(
            new DefaultTheme(new ThemePalette('test')),
            'parent-session',
            new PromptEditor(),
            new TranscriptDisplayConfig(),
            new TranscriptDisplayState(),
        );
        $screen->setStatus('subagent_live', '⚠ Subagent scout needs your input — /agents-live');

        SubagentLiveAttention::markActiveChildrenCancelledForParentCancel($state, $screen);

        $child = $state->subagentLiveCatalog->findByArtifactId('agent_a');
        self::assertNotNull($child);
        self::assertSame(SubagentLiveStatusEnum::Cancelled, $child->status);
        self::assertNull($state->subagentLiveCatalog->firstChildNeedingAttention());
        self::assertNull($this->statusText($screen, 'subagent_live'));
    }

    public function testMarkChildNeedsInputForRunSetsWaitingHumanInCatalogAndLiveView(): void
    {
        $state = new TuiSessionState('parent-session');
        $state->subagentLiveCatalog->ingestRuntimeEvent($this->progressEvent([
            'mode' => 'single', 'status' => 'running', 'agent_name' => 'scout',
            'artifact_id' => 'agent_a', 'agent_run_id' => 'child-run-1', 'task_summary' => 'Task',
        ]));
        $state->subagentLiveView->enter(new SubagentLiveChildDTO(
            agentRunId: 'child-run-1',
            artifactId: 'agent_a',
            agentName: 'scout',
            status: SubagentLiveStatusEnum::Running,
            taskSummary: 'Task',
            lastActivityAtMs: 1,
        ));
        $state->subagentLiveView->childActivity = RunActivityStateEnum::Running;

        $screen = new ChatScreen(
            new DefaultTheme(new ThemePalette('test')),
            'parent-session',
            new PromptEditor(),
            new TranscriptDisplayConfig(),
            new TranscriptDisplayState(),
        );
        SubagentLiveAttention::markChildNeedsInputForRun($state, $screen, 'child-run-1');

        $child = $state->subagentLiveCatalog->findByArtifactId('agent_a');
        self::assertNotNull($child);
        self::assertSame(SubagentLiveStatusEnum::WaitingHuman, $child->status);
        self::assertSame(RunActivityStateEnum::WaitingHuman, $state->subagentLiveView->childActivity);
        self::assertStringContainsString('needs your input', (string) $this->statusText($screen, 'subagent_live'));
        self::assertNull($this->statusText($screen, 'agents-live'));
    }

    public function testRefreshAttentionFooterClearsStaleAgentsLiveWhenLiveViewInactive(): void
    {
        $state = new TuiSessionState('parent-session');
        self::assertFalse($state->subagentLiveView->active);

        $screen = new ChatScreen(
            new DefaultTheme(new ThemePalette('test')),
            'parent-session',
            new PromptEditor(),
            new TranscriptDisplayConfig(),
            new TranscriptDisplayState(),
        );
        $screen->setStatus('agents-live', 'Subagent live: scout [running] — stale after exit.');

        SubagentLiveAttention::refreshAttentionFooter($state, $screen);

        self::assertNull($this->statusText($screen, 'agents-live'));
    }

    public function testRefreshAttentionFooterKeepsSubagentLiveMarkerWhileClearingAgentsLiveOnMain(): void
    {
        $state = new TuiSessionState('parent-session');
        $state->subagentLiveCatalog->ingestRuntimeEvent($this->progressEvent([
            'mode' => 'single', 'status' => 'waiting_human', 'agent_name' => 'scout',
            'artifact_id' => 'agent_a', 'agent_run_id' => 'child-run-1', 'task_summary' => 'Task',
        ]));
        self::assertFalse($state->subagentLiveView->active);

        $screen = new ChatScreen(
            new DefaultTheme(new ThemePalette('test')),
            'parent-session',
            new PromptEditor(),
            new TranscriptDisplayConfig(),
            new TranscriptDisplayState(),
        );
        $screen->setStatus('agents-live', 'Subagent live: scout [waiting_human] — stale.');

        SubagentLiveAttention::refreshAttentionFooter($state, $screen);

        self::assertStringContainsString('needs your input', (string) $this->statusText($screen, 'subagent_live'));
        self::assertNull($this->statusText($screen, 'agents-live'));
    }


    public function testRefreshAttentionFooterClearsAgentsLiveEvenWhenLiveViewActive(): void
    {
        $state = new TuiSessionState('parent-session');
        $state->subagentLiveCatalog->ingestRuntimeEvent($this->progressEvent([
            'mode' => 'single', 'status' => 'running', 'agent_name' => 'scout',
            'artifact_id' => 'agent_a', 'agent_run_id' => 'child-run-1', 'task_summary' => 'Task',
        ]));
        $state->subagentLiveView->enter(new SubagentLiveChildDTO(
            agentRunId: 'child-run-1',
            artifactId: 'agent_a',
            agentName: 'scout',
            status: SubagentLiveStatusEnum::Running,
            taskSummary: 'Task',
            lastActivityAtMs: 1,
        ));

        $screen = new ChatScreen(
            new DefaultTheme(new ThemePalette('test')),
            'parent-session',
            new PromptEditor(),
            new TranscriptDisplayConfig(),
            new TranscriptDisplayState(),
        );
        $screen->setStatus('agents-live', 'Subagent live: scout [running] — type to steer next step; /agents-main to return.');

        SubagentLiveAttention::refreshAttentionFooter($state, $screen);

        self::assertNull($this->statusText($screen, 'agents-live'));
    }

    private function statusText(ChatScreen $screen, string $key): ?string
    {
        $ref = new \ReflectionClass($screen);
        $providerProp = $ref->getProperty('footerDataProvider');
        $data = $providerProp->getValue($screen);
        /** @var array<string, string> $entries */
        $entries = $data->getStatusEntries();

        return $entries[$key] ?? null;
    }

    /** @param array<string, mixed> $progress */
    private function progressEvent(array $progress): RuntimeEvent
    {
        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::ToolExecutionOutputDelta->value,
            runId: 'parent-1',
            seq: 1,
            payload: ['tool_call_id' => 'tc1', 'tool_name' => 'subagent', 'delta' => '', 'subagent_progress' => $progress],
        );
    }
}
