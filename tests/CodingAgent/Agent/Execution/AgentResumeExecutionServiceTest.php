<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Application\Dto\RunStateReplayResult;
use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Pipeline\AgentRunner;
use Ineersa\AgentCore\Application\Pipeline\ApplyCommandHandler;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\AgentDepthGuard;
use Ineersa\CodingAgent\Agent\Execution\AgentResumeExecutionService;
use Ineersa\CodingAgent\Agent\Execution\AgentResumeTaskDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalOutcomeDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result\SubagentChildRunArtifactFinalizer;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentChild;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\StubRunRelationshipReader;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Focused eligibility coverage for agent_resume (no deferred wait / messenger loop).
 */
#[CoversClass(AgentResumeExecutionService::class)]
final class AgentResumeExecutionServiceTest extends IsolatedKernelTestCase
{
    public function testRejectsUnknownArtifact(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unknown artifact_id "agent_missing"');

        $this->resume(
            parentRunId: 'parent-unknown',
            tasks: [new AgentResumeTaskDTO(artifact_id: 'agent_missing', task: 'continue')],
        );
    }

    public function testResumesForkWithUnmodifiedFollowUpAndPreservesHandoffHistory(): void
    {
        $parent = 'parent-fork-kind';
        $artifactId = 'agent_fork_kind';
        $childRunId = 'child-fork-kind';
        $this->seedTerminalChild(
            $parent,
            $artifactId,
            $childRunId,
            latestInputTokens: 10,
            contextWindow: 200_000,
            artifactStatus: AgentArtifactStatusEnum::Completed,
            agentName: 'fork',
            kind: AgentArtifactKindEnum::Fork,
        );
        $identity = new ChildRunIdentityDTO($parent, $childRunId, $artifactId, 'fork', 'implement slice', AgentArtifactKindEnum::Fork);
        $state = new RunState(
            runId: $childRunId,
            status: RunStatus::Completed,
            messages: [
                new AgentMessage('user', [['type' => 'text', 'text' => 'Inherited requirement: preserve ownership']]),
                new AgentMessage('assistant', [['type' => 'text', 'text' => 'Implementation handoff']]),
            ],
        );
        $originalMessages = $state->messages;
        $finalizer = self::getContainer()->get(SubagentChildRunArtifactFinalizer::class);
        $finalizer->apply(new ChildRunTerminalOutcomeDTO($identity, AgentArtifactStatusEnum::Completed, 'implemented slice', childState: $state));
        $firstHandoffId = $this->registry()->listHandoffHistory($parent, $artifactId)[0]['id'];

        $commandBus = new TestMessageBus();
        $agentRunner = new AgentRunner($commandBus, self::getContainer()->get(SerializerInterface::class));

        $this->resume(
            parentRunId: $parent,
            tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'Address review: fix ownership wording')],
            childRunId: $childRunId,
            agentRunner: $agentRunner,
            toolCallId: 'tc-fork-resume-1',
        );

        $this->assertCount(1, $commandBus->messages);
        $command = $commandBus->messages[0];
        $this->assertInstanceOf(ApplyCommand::class, $command);
        $this->assertSame($childRunId, $command->runId());
        $followUpText = $command->payload['message']['content'][0]['text'];
        $this->assertSame('Address review: fix ownership wording', $followUpText);

        $entry = $this->registry()->get($parent, $artifactId);
        $this->assertNotNull($entry);
        $this->assertSame(AgentArtifactKindEnum::Fork, $entry->kind);
        $this->assertSame($childRunId, $entry->agentRunId);
        $this->assertSame(AgentArtifactStatusEnum::Running, $entry->status);

        $store = new InMemoryCommandStore();
        $router = new CommandRouter([]);
        $mailbox = new CommandMailboxPolicy($store, $router);
        $handler = new ApplyCommandHandler(
            commandStore: $store,
            commandRouter: $router,
            commandMailboxPolicy: $mailbox,
            eventFactory: new \Ineersa\AgentCore\Domain\Event\EventFactory(),
            messageNormalizer: new \Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer(),
            maxPendingCommands: 10,
            commandBus: $commandBus,
        );
        $queued = $handler->handle($command, $state);
        $this->assertNotNull($queued->nextState);
        $continued = $mailbox->applyPendingTurnStartCommands($queued->nextState);
        $state = $continued->state;
        $this->assertSame($originalMessages, \array_slice($state->messages, 0, 2));
        $this->assertSame($followUpText, $state->messages[2]->content[0]['text']);
        // Model execution is outside this proof. Finalize its deterministic review-fix result.
        $state = $state->with([
            'messages' => [...$state->messages, new AgentMessage('assistant', [['type' => 'text', 'text' => 'Review-fix handoff']])],
            'status' => RunStatus::Completed,
        ]);
        $finalizer->apply(new ChildRunTerminalOutcomeDTO($identity, AgentArtifactStatusEnum::Completed, 'review fixes applied', childState: $state));

        $history = $this->registry()->listHandoffHistory($parent, $artifactId);
        $this->assertCount(2, $history);
        $secondHandoffId = $history[1]['id'];
        $this->assertSame($firstHandoffId, $history[0]['id']);
        $this->assertNotSame($firstHandoffId, $secondHandoffId);
        $this->assertStringContainsString('implemented slice', $this->registry()->readHandoffHistoryEntry($parent, $artifactId, $firstHandoffId));
        $this->assertStringContainsString('review fixes applied', $this->registry()->readHandoffHistoryEntry($parent, $artifactId, $secondHandoffId));

        $resumedEntry = $this->registry()->get($parent, $artifactId);
        $this->assertNotNull($resumedEntry);
        $this->assertSame($artifactId, $resumedEntry->artifactId);
        $this->assertSame($childRunId, $resumedEntry->agentRunId);
        $this->assertSame(AgentArtifactKindEnum::Fork, $resumedEntry->kind);
    }

    public function testSubagentResumeKeepsRawFollowUpTaskText(): void
    {
        $parent = 'parent-subagent-raw';
        $artifactId = 'agent_subagent_raw';
        $childRunId = 'child-subagent-raw';
        $this->seedTerminalChild($parent, $artifactId, $childRunId, latestInputTokens: 10, contextWindow: 200_000);

        $followUps = [];
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())
            ->method('followUp')
            ->willReturnCallback(static function (string $runId, AgentMessage $message) use (&$followUps): void {
                $followUps[$runId] = $message;
            });

        $this->resume(
            parentRunId: $parent,
            tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue the scout notes')],
            childRunId: $childRunId,
            agentRunner: $agentRunner,
            toolCallId: 'tc-subagent-raw',
        );

        $this->assertSame('continue the scout notes', $followUps[$childRunId]->content[0]['text']);
    }

    public function testRejectsInFlightRunningArtifact(): void
    {
        $parent = 'parent-running';
        $artifactId = 'agent_running';
        $childRunId = 'child-running';
        $this->registry()->create($parent, $artifactId, $childRunId, 'scout', AgentArtifactKindEnum::Subagent);
        $this->registry()->update($parent, $artifactId, status: AgentArtifactStatusEnum::Running);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('already in flight (status=running)');

        $this->resume(
            parentRunId: $parent,
            tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue')],
            childRunId: $childRunId,
        );
    }

    public function testRejectsNeedsClarificationArtifact(): void
    {
        $parent = 'parent-clarify';
        $artifactId = 'agent_clarify';
        $childRunId = 'child-clarify';
        $this->registry()->create($parent, $artifactId, $childRunId, 'scout', AgentArtifactKindEnum::Subagent);
        $this->registry()->update(
            $parent,
            $artifactId,
            status: AgentArtifactStatusEnum::NeedsClarification,
            needsClarification: 'need answer',
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('already in flight (status=needs_clarification)');

        $this->resume(
            parentRunId: $parent,
            tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue')],
            childRunId: $childRunId,
        );
    }

    public function testRejectsChildMidCancellationFromCanonicalReplay(): void
    {
        $parent = 'parent-mid-cancel';
        $artifactId = 'agent-mid-cancel';
        $childRunId = 'child-mid-cancel';
        $this->seedTerminalChild($parent, $artifactId, $childRunId, latestInputTokens: 10, contextWindow: 200_000);
        $runStateRebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $runStateRebuilder->expects($this->once())
            ->method('rebuildIfStale')
            ->with($this->isInstanceOf(RunState::class), $childRunId)
            ->willReturn(RunStateReplayResult::rebuilt(new RunState(runId: $childRunId, status: RunStatus::Cancelling)));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('mid-cancel and cannot be resumed yet');

        $this->resume(
            parentRunId: $parent,
            tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue')],
            childRunId: $childRunId,
            runStateRebuilder: $runStateRebuilder,
        );
    }

    public function testRejectsRunningForkDespiteTerminalArtifact(): void
    {
        $parent = 'parent-stale-fork';
        $artifactId = 'agent_stale_fork';
        $childRunId = 'child-stale-fork';
        $this->seedTerminalChild($parent, $artifactId, $childRunId, latestInputTokens: 10, contextWindow: 200_000, agentName: 'fork', kind: AgentArtifactKindEnum::Fork);
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->never())->method('followUp');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('is not terminal (status=running)');
        $this->resume(
            parentRunId: $parent,
            tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue')],
            childRunId: $childRunId,
            runStatus: RunStatus::Running,
            agentRunner: $agentRunner,
        );
    }

    public function testRejectsOversizeContextWithMissingWindowFloor(): void
    {
        $parent = 'parent-oversize-floor';
        $artifactId = 'agent_oversize_floor';
        $childRunId = 'child-oversize-floor';
        $this->seedTerminalChild($parent, $artifactId, $childRunId, latestInputTokens: 200_000, contextWindow: null);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('child context is near the limit');

        $this->resume(
            parentRunId: $parent,
            tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue')],
            childRunId: $childRunId,
            runStatus: RunStatus::Completed,
        );
    }

    public function testRejectsOversizeContextAtSeventyFivePercentOfWindow(): void
    {
        $parent = 'parent-oversize-window';
        $artifactId = 'agent_oversize_window';
        $childRunId = 'child-oversize-window';
        $this->seedTerminalChild($parent, $artifactId, $childRunId, latestInputTokens: 300_000, contextWindow: 400_000);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('threshold 300000');

        $this->resume(
            parentRunId: $parent,
            tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue')],
            childRunId: $childRunId,
            runStatus: RunStatus::Completed,
        );
    }

    public function testResumesDistinctFailedAndCancelledArtifactsInParallelViaExistingChildRuns(): void
    {
        $parent = 'parent-parallel';
        $failedArtifactId = 'agent_failed';
        $failedChildRunId = 'child-failed';
        $cancelledArtifactId = 'agent_cancelled';
        $cancelledChildRunId = 'child-cancelled';
        $this->seedTerminalChild($parent, $failedArtifactId, $failedChildRunId, latestInputTokens: 10, contextWindow: 200_000, artifactStatus: AgentArtifactStatusEnum::Failed, agentName: 'fork', kind: AgentArtifactKindEnum::Fork);
        $this->seedTerminalChild($parent, $cancelledArtifactId, $cancelledChildRunId, latestInputTokens: 10, contextWindow: 200_000, artifactStatus: AgentArtifactStatusEnum::Cancelled, agentName: 'fork', kind: AgentArtifactKindEnum::Fork);

        $followUps = [];
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->exactly(2))
            ->method('followUp')
            ->willReturnCallback(static function (string $runId, AgentMessage $message) use (&$followUps): void {
                $followUps[$runId] = $message;
            });

        $this->resume(
            parentRunId: $parent,
            tasks: [
                new AgentResumeTaskDTO(artifact_id: $failedArtifactId, task: 'verify failure fix'),
                new AgentResumeTaskDTO(artifact_id: $cancelledArtifactId, task: 'continue cancellation-safe work'),
            ],
            agentRunner: $agentRunner,
            toolCallId: 'tc-parallel',
            executionMode: ChildRunBatchExecutionModeEnum::Parallel,
        );

        $this->assertStringContainsString('verify failure fix', $followUps[$failedChildRunId]->content[0]['text']);
        $this->assertStringContainsString('continue cancellation-safe work', $followUps[$cancelledChildRunId]->content[0]['text']);
        $this->assertSame(AgentArtifactStatusEnum::Running, $this->registry()->get($parent, $failedArtifactId)?->status);
        $this->assertSame(AgentArtifactStatusEnum::Running, $this->registry()->get($parent, $cancelledArtifactId)?->status);

        $batch = self::getContainer()->get(DeferredSubagentBatchRepository::class)
            ->findByParentRunAndToolCall($parent, 'tc-parallel');
        $this->assertNotNull($batch);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Launched, $batch->launchStatus);
    }

    public function testRejectsNestedParentCaller(): void
    {
        $parent = 'parent-nested';
        $artifactId = 'agent_nested';
        $childRunId = 'child-nested';
        $this->registry()->create($parent, $artifactId, $childRunId, 'scout', AgentArtifactKindEnum::Subagent);
        $this->registry()->update($parent, $artifactId, status: AgentArtifactStatusEnum::Completed, summary: 'done');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('is an agent child; nested launches are not supported');

        $this->resume(
            parentRunId: $parent,
            tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue')],
            childRunId: $childRunId,
            relationshipReader: StubRunRelationshipReader::child($parent, 'grandparent'),
        );
    }

    public function testFollowUpFailureMarksBatchFailedAndRevertsChildToPriorTerminal(): void
    {
        $parent = 'parent-followup-fail';
        $artifactId = 'agent_followup_fail';
        $childRunId = 'child-followup-fail';
        $toolCallId = 'tc-followup-fail';
        $this->seedTerminalChild($parent, $artifactId, $childRunId, latestInputTokens: 10, contextWindow: 200_000);

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())
            ->method('followUp')
            ->willThrowException(new \RuntimeException('follow_up boom'));

        try {
            $this->resume(
                parentRunId: $parent,
                tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue')],
                childRunId: $childRunId,
                runStatus: RunStatus::Completed,
                agentRunner: $agentRunner,
                toolCallId: $toolCallId,
            );
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('failed to follow_up', $e->getMessage());
        }

        $batch = self::getContainer()->get(DeferredSubagentBatchRepository::class)
            ->findByParentRunAndToolCall($parent, $toolCallId);
        $this->assertNotNull($batch);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Failed, $batch->launchStatus);

        $entry = $this->registry()->get($parent, $artifactId);
        $this->assertNotNull($entry);
        // followUp failure reverts the Running mark back to the prior terminal status.
        $this->assertSame(AgentArtifactStatusEnum::Completed, $entry->status);

        $this->expectException(ToolCallException::class);
        // Same tool_call_id still short-circuits on the previously failed batch.
        $this->expectExceptionMessage('batch launch previously failed');
        $this->resume(
            parentRunId: $parent,
            tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue again')],
            childRunId: $childRunId,
            runStatus: RunStatus::Completed,
            toolCallId: $toolCallId,
        );
    }

    public function testLaunchSuccessPersistenceFailureLeavesDeferredResumeRecoverable(): void
    {
        $parent = 'parent-launch-success-persist-failure';
        $artifactId = 'agent_launch_success_persist_failure';
        $childRunId = 'child-launch-success-persist-failure';
        $toolCallId = 'tc-launch-success-persist-failure';
        $this->seedTerminalChild($parent, $artifactId, $childRunId, latestInputTokens: 10, contextWindow: 200_000);

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('followUp')->willReturnCallback(
            static function () use ($childRunId): void {
                self::getContainer()->get('doctrine')->getManager()->getConnection()->executeStatement(
                    'DELETE FROM deferred_subagent_child WHERE child_run_id = :child_run_id',
                    ['child_run_id' => $childRunId],
                );
            },
        );
        $logger = new TestLogger();

        $outcome = $this->resume(
            parentRunId: $parent,
            tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue')],
            childRunId: $childRunId,
            agentRunner: $agentRunner,
            toolCallId: $toolCallId,
            logger: $logger,
        );

        $this->assertNotSame('', $outcome->deferredId);
        $batch = self::getContainer()->get(DeferredSubagentBatchRepository::class)
            ->findByParentRunAndToolCall($parent, $toolCallId);
        $this->assertNotNull($batch);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Reserved, $batch->launchStatus);
        $this->assertSame('agent_resume.launch_success_persist_failed', $logger->records[0]['message'] ?? null);
        $this->assertSame($parent, $logger->records[0]['context']['session_id'] ?? null);
        $this->assertSame($toolCallId, $logger->records[0]['context']['tool_call_id'] ?? null);
    }

    public function testArtifactCanResumeDuringCurrentParentLifetimeButNotAfterAttach(): void
    {
        $parent = self::getContainer()->get(HatfieldSessionStore::class)->createSession();
        $artifactId = 'agent_lifetime';
        $childRunId = 'child-lifetime';
        $this->seedTerminalChild($parent, $artifactId, $childRunId, latestInputTokens: 10, contextWindow: 200_000);

        $this->resume(
            parentRunId: $parent,
            tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue')],
            childRunId: $childRunId,
            toolCallId: 'tc-lifetime-before-attach',
        );
        $this->registry()->update($parent, $artifactId, status: AgentArtifactStatusEnum::Completed);

        self::getContainer()->get(InProcessAgentSessionClient::class)->attach($parent);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('belongs to a previous parent lifetime');
        $this->resume(
            parentRunId: $parent,
            tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue after attach')],
            childRunId: $childRunId,
            toolCallId: 'tc-lifetime-after-attach',
        );
    }

    public function testRejectsDuplicateResolvedArtifactViaMixedIdentifiers(): void
    {
        $parent = 'parent-dup-mixed';
        $artifactId = 'agent_dup_mixed';
        $childRunId = 'child-dup-mixed';
        $this->seedTerminalChild($parent, $artifactId, $childRunId, latestInputTokens: 10, contextWindow: 200_000);

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->never())->method('followUp');
        $runStateRebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $runStateRebuilder->expects($this->once())
            ->method('rebuildIfStale')
            ->with(
                $this->callback(static fn (RunState $state): bool => $childRunId === $state->runId),
                $childRunId,
            )
            ->willReturn(RunStateReplayResult::rebuilt(new RunState(runId: $childRunId, status: RunStatus::Completed)));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(\sprintf('Duplicate artifact_id "%s" in one agent_resume call.', $artifactId));

        $this->resume(
            parentRunId: $parent,
            tasks: [
                new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue via artifact'),
                new AgentResumeTaskDTO(agent_run_id: $childRunId, task: 'continue via run id'),
            ],
            childRunId: $childRunId,
            agentRunner: $agentRunner,
            executionMode: ChildRunBatchExecutionModeEnum::Parallel,
            runStateRebuilder: $runStateRebuilder,
        );
    }

    /**
     * @param list<AgentResumeTaskDTO> $tasks
     */
    private function resume(
        string $parentRunId,
        array $tasks,
        ?string $childRunId = null,
        RunStatus $runStatus = RunStatus::Completed,
        ?AgentRunnerInterface $agentRunner = null,
        string $toolCallId = 'tc-resume-1',
        ChildRunBatchExecutionModeEnum $executionMode = ChildRunBatchExecutionModeEnum::Single,
        ?TestLogger $logger = null,
        ?RunStateRebuilderInterface $runStateRebuilder = null,
        ?\Ineersa\CodingAgent\Repository\RunRelationshipReaderInterface $relationshipReader = null,
    ): \Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome {
        $contextAccessor = new StackToolExecutionContextAccessor();
        if (null === $runStateRebuilder) {
            $runStateRebuilder = $this->createStub(RunStateRebuilderInterface::class);
            $runStateRebuilder->method('rebuildIfStale')->willReturnCallback(
                static function (RunState $state, string $runId) use ($runStatus): RunStateReplayResult {
                    return RunStateReplayResult::rebuilt(new RunState(runId: $runId, status: $runStatus));
                },
            );
        }

        $service = new AgentResumeExecutionService(
            artifactRegistry: $this->registry(),
            batchRepository: self::getContainer()->get(DeferredSubagentBatchRepository::class),
            childRepository: self::getContainer()->get(DeferredSubagentChildRepository::class),
            identityFactory: new DeferredSubagentBatchIdentityFactory(),
            agentRunner: $agentRunner ?? $this->createStub(AgentRunnerInterface::class),
            runStateRebuilder: $runStateRebuilder,
            relationshipReader: $relationshipReader ?? StubRunRelationshipReader::topLevel($parentRunId),
            depthGuard: new AgentDepthGuard(),
            contextAccessor: $contextAccessor,
            agentsConfig: new AgentsConfig(maxAgents: 4),
            logger: $logger ?? new NullLogger(),
        );

        return $contextAccessor->with(
            new ToolContext(
                runId: $parentRunId,
                turnNo: 1,
                toolCallId: $toolCallId,
                toolName: 'agent_resume',
                cancellationToken: new NullCancellationToken(),
                timeoutSeconds: 30,
                orderIndex: 0,
                parentModel: 'test/model',
            ),
            static function () use ($service, $parentRunId, $tasks, $executionMode): \Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome {
                return $service->resume($parentRunId, $tasks, $executionMode);
            },
        );
    }

    private function seedTerminalChild(
        string $parent,
        string $artifactId,
        string $childRunId,
        int $latestInputTokens,
        ?int $contextWindow,
        AgentArtifactStatusEnum $artifactStatus = AgentArtifactStatusEnum::Completed,
        string $agentName = 'scout',
        AgentArtifactKindEnum $kind = AgentArtifactKindEnum::Subagent,
    ): void {
        $this->registry()->create($parent, $artifactId, $childRunId, $agentName, $kind);
        $this->registry()->update($parent, $artifactId, status: $artifactStatus, summary: 'done');

        /** @var SerializerInterface $serializer */
        $serializer = self::getContainer()->get(SerializerInterface::class);
        $projection = new DeferredChildRunLifecycleProjectionDTO(
            childStatus: RunStatus::Completed,
            childTurnNo: 2,
            lastCommittedSeq: 5,
            model: 'test/model',
            reasoning: 'medium',
            latestInputTokens: $latestInputTokens,
            contextWindow: $contextWindow,
        );
        $projectionArray = $serializer->normalize(
            $projection,
            null,
            [AbstractObjectNormalizer::SKIP_NULL_VALUES => true],
        );
        if (!\is_array($projectionArray)) {
            throw new \RuntimeException('Expected normalized lifecycle projection array.');
        }

        $em = self::getContainer()->get('doctrine')->getManager();
        $row = new DeferredSubagentChild();
        $row->batchLifecycleId = 'batch-'.$childRunId;
        $row->batchIndex = 1;
        $row->childRunId = $childRunId;
        $row->artifactId = $artifactId;
        $row->agentName = $agentName;
        $row->task = 'seed';
        $row->launchModel = 'test/model';
        $row->launchReasoning = 'medium';
        $row->launchStatus = DeferredSubagentChildLaunchStatusEnum::Launched;
        $row->childEventCursor = 5;
        $row->childLifecycleProjection = $projectionArray;
        $row->terminalCompletedAt = new \DateTimeImmutable('2026-08-21T00:01:00Z');
        $row->terminalStatus = 'completed';
        $em->persist($row);
        $em->flush();
    }

    private function registry(): AgentArtifactRegistry
    {
        return self::getContainer()->get(AgentArtifactRegistry::class);
    }
}
