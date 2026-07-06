<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Scenario;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\Tui\Tests\Support\SubagentLiveScenarioHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Canonical subagent live HITL / attention / input-routing flows (virtual layer).
 */
final class SubagentLiveHitlScenarioTest extends TestCase
{
    private const PARENT_RUN = 'parent-run-1';
    private const CHILD_RUN = 'child-run-scenario';
    private const ARTIFACT = 'agent_scenario';

    #[Test]
    public function childHitlAnswerClearsAttentionAndStaleProgressCannotDowngradeTerminal(): void
    {
        $h = $this->newHarness();
        $h->seedChildInCatalog(self::ARTIFACT, self::CHILD_RUN, 'waiting_human');
        $h->enterLiveView(self::ARTIFACT, self::CHILD_RUN, RunActivityStateEnum::WaitingHuman, SubagentLiveStatusEnum::WaitingHuman);
        $h->refreshAttentionFooter();

        $this->assertStringContainsString('needs your input', (string) $h->statusText('subagent_live'));
        $this->assertStringContainsString('needs input', $h->pickerLabels()[0] ?? '');

        $h->enqueueChildHumanInputViaTickPoll(self::CHILD_RUN);
        $this->assertTrue($h->questionCoordinator->actionRequired());

        $h->submit('docs/agents.md');

        $this->assertFalse($h->questionCoordinator->actionRequired());
        $last = $h->client->lastSend();
        $this->assertNotNull($last);
        $this->assertSame(self::CHILD_RUN, $last['runId']);
        $this->assertSame('answer_human', $last['command']->type);
        $this->assertNull($h->state->subagentLiveCatalog->firstChildNeedingAttention());
        $this->assertNull($h->statusText('subagent_live'));

        $h->ingestChildProgress(self::ARTIFACT, self::CHILD_RUN, 'completed');
        $h->refreshAttentionFooter();
        $this->assertNull($h->statusText('subagent_live'));
        $this->assertStringNotContainsString('needs input', $h->pickerLabels()[0] ?? '');

        $h->ingestChildProgress(self::ARTIFACT, self::CHILD_RUN, 'waiting_human');
        $child = $h->state->subagentLiveCatalog->findByArtifactId(self::ARTIFACT);
        $this->assertNotNull($child);
        $this->assertSame(SubagentLiveStatusEnum::Completed, $child->status);
        $this->assertNull($h->state->subagentLiveCatalog->firstChildNeedingAttention());
    }

    #[Test]
    public function childHitlEscCancelClearsQuestionAndNextParentPromptIsNotSwallowed(): void
    {
        $h = $this->newHarness();
        $h->seedChildInCatalog(self::ARTIFACT, self::CHILD_RUN, 'waiting_human');
        $h->enterLiveView(self::ARTIFACT, self::CHILD_RUN, RunActivityStateEnum::WaitingHuman, SubagentLiveStatusEnum::WaitingHuman);
        $h->enqueueChildHumanInputViaTickPoll(self::CHILD_RUN);
        $h->refreshAttentionFooter();

        $h->pressEsc();

        $this->assertFalse($h->questionCoordinator->actionRequired());
        $this->assertNull($h->state->subagentLiveCatalog->firstChildNeedingAttention());
        $this->assertNull($h->statusText('subagent_live'));
        $this->assertSame(0, \count(array_filter($h->client->ops, static fn (array $o): bool => 'cancel' === $o['op'])));

        $h->state->subagentLiveView->exit();
        $h->state->activity = RunActivityStateEnum::Running;

        $h->submit('Now launch a scout to run bash with sleep 60');

        $last = $h->client->lastSend();
        $this->assertNotNull($last);
        $this->assertSame(self::PARENT_RUN, $last['runId']);
        $this->assertContains($last['command']->type, ['steer', 'follow_up', 'message']);
        $this->assertNotSame('answer_human', $last['command']->type);
    }

    #[Test]
    public function childRunCancelMarksCancelledEverywhereAndStaleProgressDoesNotDowngrade(): void
    {
        $h = $this->newHarness();
        $h->seedChildInCatalog(self::ARTIFACT, self::CHILD_RUN, 'waiting_human');
        $h->enterLiveView(self::ARTIFACT, self::CHILD_RUN, RunActivityStateEnum::Running, SubagentLiveStatusEnum::WaitingHuman);
        $h->refreshAttentionFooter();

        $h->pressEsc();

        $cancelOps = array_filter($h->client->ops, static fn (array $o): bool => 'cancel' === $o['op']);
        $this->assertCount(1, $cancelOps);
        $this->assertSame(self::CHILD_RUN, array_values($cancelOps)[0]['runId']);

        $child = $h->state->subagentLiveCatalog->findByArtifactId(self::ARTIFACT);
        $this->assertNotNull($child);
        $this->assertSame(SubagentLiveStatusEnum::Cancelled, $child->status);
        $this->assertNull($h->state->subagentLiveCatalog->firstChildNeedingAttention());
        $this->assertNull($h->statusText('subagent_live'));
        $this->assertStringContainsString('cancelled', strtolower($h->pickerLabels()[0] ?? ''));

        $h->ingestChildProgress(self::ARTIFACT, self::CHILD_RUN, 'waiting_human');
        $child = $h->state->subagentLiveCatalog->findByArtifactId(self::ARTIFACT);
        $this->assertSame(SubagentLiveStatusEnum::Cancelled, $child?->status);
    }

    #[Test]
    public function parentHitlAfterSubagentCompletionIsAnswerableWithoutStaleChildAttention(): void
    {
        $h = $this->newHarness();
        $h->seedChildInCatalog(self::ARTIFACT, self::CHILD_RUN, 'completed');
        $h->state->activity = RunActivityStateEnum::Completed;
        $h->refreshAttentionFooter();
        $this->assertNull($h->statusText('subagent_live'));

        $h->enqueueParentHumanInputViaTickPoll();
        $this->assertTrue($h->questionCoordinator->actionRequired());
        $active = $h->questionCoordinator->activeRequest();
        $this->assertNotNull($active);
        $this->assertSame(self::PARENT_RUN, $active->runId);

        $h->submit('docs/agents.md');

        $this->assertFalse($h->questionCoordinator->actionRequired());
        $last = $h->client->lastSend();
        $this->assertNotNull($last);
        $this->assertSame(self::PARENT_RUN, $last['runId']);
        $this->assertSame('answer_human', $last['command']->type);
        $this->assertSame('docs/agents.md', $last['command']->payload['answer'] ?? null);
    }

    #[Test]
    public function mainCancelWhileChildWaitingMarksChildCancelledAndClearsAttention(): void
    {
        $h = $this->newHarness();
        $h->seedChildInCatalog(self::ARTIFACT, self::CHILD_RUN, 'waiting_human');
        $this->assertFalse($h->state->subagentLiveView->active);
        $h->refreshAttentionFooter();

        $this->assertStringContainsString('needs your input', (string) $h->statusText('subagent_live'));
        $this->assertStringContainsString('needs input', $h->pickerLabels()[0] ?? '');

        $h->pressEsc();

        $cancelOps = array_filter($h->client->ops, static fn (array $o): bool => 'cancel' === $o['op']);
        $this->assertCount(1, $cancelOps);
        $this->assertSame(self::PARENT_RUN, array_values($cancelOps)[0]['runId']);
        $this->assertSame(RunActivityStateEnum::Cancelling, $h->state->activity);
        $this->assertNull($h->statusText('subagent_live'));
        $this->assertStringContainsString('cancelled', strtolower($h->pickerLabels()[0] ?? ''));
        $this->assertStringNotContainsString('needs input', $h->pickerLabels()[0] ?? '');

        $child = $h->state->subagentLiveCatalog->findByArtifactId(self::ARTIFACT);
        $this->assertNotNull($child);
        $this->assertSame(SubagentLiveStatusEnum::Cancelled, $child->status);
        $this->assertNull($h->state->subagentLiveCatalog->firstChildNeedingAttention());

        $h->ingestChildProgress(self::ARTIFACT, self::CHILD_RUN, 'waiting_human');
        $child = $h->state->subagentLiveCatalog->findByArtifactId(self::ARTIFACT);
        $this->assertSame(SubagentLiveStatusEnum::Cancelled, $child?->status);
    }

    #[Test]
    public function agentsMainReturnClearsAgentsLiveStatusRowOnMainScreen(): void
    {
        $h = $this->newHarness();
        $h->seedChildInCatalog(self::ARTIFACT, self::CHILD_RUN, 'running');
        $h->enterLiveView(self::ARTIFACT, self::CHILD_RUN, RunActivityStateEnum::Running, SubagentLiveStatusEnum::Running);
        $h->refreshAttentionFooter();

        $this->assertTrue($h->state->subagentLiveView->active);
        $this->assertNull($h->statusText('agents-live'));

        $h->agentsMain();

        $this->assertFalse($h->state->subagentLiveView->active);
        $this->assertNull($h->statusText('agents-live'));
    }

    #[Test]
    public function completedProgressClearsLiveWarningForSelectedChild(): void
    {
        $h = $this->newHarness();
        $h->seedChildInCatalog(self::ARTIFACT, self::CHILD_RUN, 'waiting_human');
        $h->enterLiveView(self::ARTIFACT, self::CHILD_RUN, RunActivityStateEnum::WaitingHuman, SubagentLiveStatusEnum::WaitingHuman);
        $h->refreshAttentionFooter();
        $this->assertStringContainsString('needs your input', (string) $h->statusText('subagent_live'));

        $h->ingestChildProgress(self::ARTIFACT, self::CHILD_RUN, 'completed');
        $h->state->subagentLiveView->childActivity = RunActivityStateEnum::Completed;
        $refreshed = $h->state->subagentLiveCatalog->findByArtifactId(self::ARTIFACT);
        if (null !== $refreshed) {
            $h->state->subagentLiveView->selected = $refreshed;
        }
        $h->refreshAttentionFooter();

        $this->assertNull($h->state->subagentLiveCatalog->firstChildNeedingAttention());
        $this->assertNull($h->statusText('subagent_live'));
        $this->assertStringNotContainsString('needs input', $h->pickerLabels()[0] ?? '');
    }

    private function newHarness(): SubagentLiveScenarioHarness
    {
        return SubagentLiveScenarioHarness::create(
            $this,
            parentRunId: self::PARENT_RUN,
            entityManager: $this->createStub(EntityManagerInterface::class),
            switchService: $this->createStub(TuiSessionSwitchServiceInterface::class),
        );
    }
}
