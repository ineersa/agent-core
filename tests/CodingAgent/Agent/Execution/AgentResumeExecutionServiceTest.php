<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Pipeline\AgentRunner;
use Ineersa\AgentCore\Application\Pipeline\ApplyCommandHandler;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\RunOperationalStatusDTO;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Coordination\EnqueueCommandDTO;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStoreFactory;
use Ineersa\CodingAgent\Agent\Execution\AgentDepthGuard;
use Ineersa\CodingAgent\Agent\Execution\AgentResumeExecutionService;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalOutcomeDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result\SubagentChildRunArtifactFinalizer;
use Ineersa\CodingAgent\Application\Pipeline\ChildInvocationLifecycleService;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentChild;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Session\Contract\RunSequenceAllocatorInterface;
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver;
use Ineersa\CodingAgent\Tests\Support\HistoryEventStoreFactory;
use Ineersa\CodingAgent\Tests\Support\StubRunRelationshipReader;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\DTO\AgentResumeArgumentsDTO;
use Ineersa\Tui\Tests\Support\AgentTaskPresentationAssertionsTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Focused eligibility coverage for agent_resume (no deferred wait / messenger loop).
 */
#[CoversClass(AgentResumeExecutionService::class)]
final class AgentResumeExecutionServiceTest extends IsolatedKernelTestCase
{
    use AgentTaskPresentationAssertionsTrait;

    public function testRejectsUnknownArtifact(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unknown artifact_id "agent_missing"');

        $this->resume(
            parentRunId: $this->parentSession('unknown'),
            task: new AgentResumeArgumentsDTO(artifact_id: 'agent_missing', task: 'continue'),
        );
    }

    public function testResumesForkWithUnmodifiedFollowUpAndPreservesHandoffHistory(): void
    {
        $parent = $this->parentSession('fork-kind');
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
        $inheritedHuman = 'Inherited requirement: preserve ownership'.str_repeat("\nInherited human detail.", 9)."\nInherited human final line.";
        $state = new RunState(
            runId: $childRunId,
            status: RunStatus::Completed,
            messages: [
                new AgentMessage('user', [['type' => 'text', 'text' => $inheritedHuman]]),
                new AgentMessage('assistant', [['type' => 'text', 'text' => 'Implementation handoff']]),
            ],
        );
        $originalMessages = $state->messages;
        $finalizer = self::getContainer()->get(SubagentChildRunArtifactFinalizer::class);
        $finalizer->apply(new ChildRunTerminalOutcomeDTO($identity, AgentArtifactStatusEnum::Completed, 'implemented slice'));
        $firstHandoffId = $this->registry()->listHandoffHistory($parent, $artifactId)[0]['id'];

        $commandBus = new TestMessageBus();
        $agentRunner = new AgentRunner($commandBus, self::getContainer()->get(SerializerInterface::class));

        $this->resume(
            parentRunId: $parent,
            task: new AgentResumeArgumentsDTO(artifact_id: $artifactId, task: 'Address review: fix ownership wording'),
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
        $this->assertSame('user', $command->payload['message']['role']);
        $this->assertSame(['agent_task' => ['artifact_id' => $artifactId]], $command->payload['message']['metadata']);

        $entry = $this->registry()->get($parent, $artifactId);
        $this->assertNotNull($entry);
        $this->assertSame(AgentArtifactKindEnum::Fork, $entry->kind);
        $this->assertSame($childRunId, $entry->agentRunId);
        $this->assertSame(AgentArtifactStatusEnum::Running, $entry->status);

        $mailbox = self::getContainer()->get(CommandMailboxPolicy::class);
        $handler = self::getContainer()->get(ApplyCommandHandler::class);
        $queued = $handler->handle($command, $state);
        $this->assertNotNull($queued->nextState);
        $enqueues = array_values(array_filter($queued->postCommitActions, static fn (object $action): bool => $action instanceof EnqueueCommandDTO));
        $this->assertCount(1, $enqueues);
        $enqueue = $enqueues[0];
        $this->assertInstanceOf(EnqueueCommandDTO::class, $enqueue);
        self::getContainer()->get(\Ineersa\AgentCore\Contract\CommandStoreInterface::class)->enqueue($enqueue->command);
        $continued = $mailbox->applyPendingTurnStartCommands($queued->nextState);
        $state = $continued->state;
        $this->assertSame($originalMessages, \array_slice($state->messages, 0, 2));
        $this->assertSame($followUpText, $state->messages[2]->content[0]['text']);
        $this->assertSame([], $state->messages[0]->metadata);
        $this->assertAgentTaskPresentation([
            new RunEvent($childRunId, 0, 0, 'run_started', ['payload' => ['messages' => array_map(static fn (AgentMessage $message): array => $message->toArray(), $originalMessages)]]),
            ...new EventFactory()->eventsFromSpecs($childRunId, 0, 1, $continued->eventSpecs),
        ], $artifactId, $followUpText, $followUpText, $inheritedHuman);
        // Model execution is outside this proof. Finalize its deterministic review-fix result.
        $state = $state->with([
            'messages' => [...$state->messages, new AgentMessage('assistant', [['type' => 'text', 'text' => 'Review-fix handoff']])],
            'status' => RunStatus::Completed,
        ]);
        $finalizer->apply(new ChildRunTerminalOutcomeDTO($identity, AgentArtifactStatusEnum::Completed, 'review fixes applied'));

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
        $parent = $this->parentSession('subagent-raw');
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
            task: new AgentResumeArgumentsDTO(artifact_id: $artifactId, task: 'continue the scout notes'),
            childRunId: $childRunId,
            agentRunner: $agentRunner,
            toolCallId: 'tc-subagent-raw',
        );

        $this->assertSame('continue the scout notes', $followUps[$childRunId]->content[0]['text']);
    }

    public function testRejectsInFlightRunningArtifact(): void
    {
        $parent = $this->parentSession('running');
        $artifactId = 'agent_running';
        $childRunId = 'child-running';
        $this->registry()->create($parent, $artifactId, $childRunId, 'scout', AgentArtifactKindEnum::Subagent);
        $this->registry()->update($parent, $artifactId, status: AgentArtifactStatusEnum::Running);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('already in flight (status=running)');

        $this->resume(
            parentRunId: $parent,
            task: new AgentResumeArgumentsDTO(artifact_id: $artifactId, task: 'continue'),
            childRunId: $childRunId,
        );
    }

    public function testRejectsNeedsClarificationArtifact(): void
    {
        $parent = $this->parentSession('clarify');
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
            task: new AgentResumeArgumentsDTO(artifact_id: $artifactId, task: 'continue'),
            childRunId: $childRunId,
        );
    }

    public function testRejectsChildMidCancellationFromOperationalStatus(): void
    {
        $parent = $this->parentSession('mid-cancel');
        $artifactId = 'agent-mid-cancel';
        $childRunId = 'child-mid-cancel';
        $this->seedTerminalChild($parent, $artifactId, $childRunId, latestInputTokens: 10, contextWindow: 200_000);
        $operationalStatusReader = $this->createMock(RunOperationalStatusReaderInterface::class);
        $operationalStatusReader->expects($this->once())
            ->method('findOperationalStatus')
            ->with($childRunId)
            ->willReturn(new RunOperationalStatusDTO(RunStatus::Cancelling, 1));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('mid-cancel and cannot be resumed yet');

        $this->resume(
            parentRunId: $parent,
            task: new AgentResumeArgumentsDTO(artifact_id: $artifactId, task: 'continue'),
            childRunId: $childRunId,
            operationalStatusReader: $operationalStatusReader,
        );
    }

    public function testRejectsRunningForkDespiteTerminalArtifact(): void
    {
        $parent = $this->parentSession('stale-fork');
        $artifactId = 'agent_stale_fork';
        $childRunId = 'child-stale-fork';
        $this->seedTerminalChild($parent, $artifactId, $childRunId, latestInputTokens: 10, contextWindow: 200_000, agentName: 'fork', kind: AgentArtifactKindEnum::Fork);
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->never())->method('followUp');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('is not terminal (status=running)');
        $this->resume(
            parentRunId: $parent,
            task: new AgentResumeArgumentsDTO(artifact_id: $artifactId, task: 'continue'),
            childRunId: $childRunId,
            runStatus: RunStatus::Running,
            agentRunner: $agentRunner,
        );
    }

    public function testRejectsOversizeContextWithUnknownWindowCutoff(): void
    {
        $parent = $this->parentSession('oversize-unknown-window');
        $artifactId = 'agent_oversize_unknown_window';
        $childRunId = 'child-oversize-unknown-window';
        $this->seedTerminalChild($parent, $artifactId, $childRunId, latestInputTokens: 200_000, contextWindow: null);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('threshold 200000');

        $this->resume(
            parentRunId: $parent,
            task: new AgentResumeArgumentsDTO(artifact_id: $artifactId, task: 'continue'),
            childRunId: $childRunId,
            runStatus: RunStatus::Completed,
        );
    }

    public function testRejectsOversizeContextAtSeventyFivePercentOfWindow(): void
    {
        $parent = $this->parentSession('oversize-window');
        $artifactId = 'agent_oversize_window';
        $childRunId = 'child-oversize-window';
        // 75% of a 240k window (180k) stays below the 200k absolute cap.
        $this->seedTerminalChild($parent, $artifactId, $childRunId, latestInputTokens: 180_000, contextWindow: 240_000);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('threshold 180000');

        $this->resume(
            parentRunId: $parent,
            task: new AgentResumeArgumentsDTO(artifact_id: $artifactId, task: 'continue'),
            childRunId: $childRunId,
            runStatus: RunStatus::Completed,
        );
    }

    public function testRejectsOversizeContextWhenLargeWindowIsCappedAtTwoHundredThousandTokens(): void
    {
        $parent = $this->parentSession('oversize-capped');
        $artifactId = 'agent_oversize_capped';
        $childRunId = 'child-oversize-capped';
        $this->seedTerminalChild($parent, $artifactId, $childRunId, latestInputTokens: 250_000, contextWindow: 1_050_000);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('threshold 200000');

        $this->resume(
            parentRunId: $parent,
            task: new AgentResumeArgumentsDTO(artifact_id: $artifactId, task: 'continue'),
            childRunId: $childRunId,
            runStatus: RunStatus::Completed,
        );
    }

    public function testResumesBelowTwoHundredThousandTokenCapWithLargeWindow(): void
    {
        $parent = $this->parentSession('below-cap');
        $artifactId = 'agent_below_cap';
        $childRunId = 'child-below-cap';
        $this->seedTerminalChild($parent, $artifactId, $childRunId, latestInputTokens: 199_999, contextWindow: 1_050_000);

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('followUp');

        $this->resume(
            parentRunId: $parent,
            task: new AgentResumeArgumentsDTO(artifact_id: $artifactId, task: 'continue'),
            childRunId: $childRunId,
            agentRunner: $agentRunner,
        );

        $this->assertSame(AgentArtifactStatusEnum::Running, $this->registry()->get($parent, $artifactId)?->status);
    }

    public function testIndependentCallsResumeFailedAndCancelledArtifactsViaBothIdentifiers(): void
    {
        $parent = $this->parentSession('parallel');
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
            task: new AgentResumeArgumentsDTO(artifact_id: $failedArtifactId, task: 'verify failure fix'),
            agentRunner: $agentRunner,
            toolCallId: 'tc-failed',
        );
        $this->resume(
            parentRunId: $parent,
            task: new AgentResumeArgumentsDTO(agent_run_id: $cancelledChildRunId, task: 'continue cancellation-safe work'),
            agentRunner: $agentRunner,
            toolCallId: 'tc-cancelled',
        );

        $this->assertStringContainsString('verify failure fix', $followUps[$failedChildRunId]->content[0]['text']);
        $this->assertStringContainsString('continue cancellation-safe work', $followUps[$cancelledChildRunId]->content[0]['text']);
        $this->assertSame(AgentArtifactStatusEnum::Running, $this->registry()->get($parent, $failedArtifactId)?->status);
        $this->assertSame(AgentArtifactStatusEnum::Running, $this->registry()->get($parent, $cancelledArtifactId)?->status);

        $batch = self::getContainer()->get(DeferredSubagentBatchRepository::class)
            ->findByParentRunAndToolCall($parent, 'tc-failed');
        $this->assertNotNull($batch);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Launched, $batch->launchStatus);
    }

    public function testRejectsNestedParentCaller(): void
    {
        $parent = $this->parentSession('nested');
        $artifactId = 'agent_nested';
        $childRunId = 'child-nested';
        $this->registry()->create($parent, $artifactId, $childRunId, 'scout', AgentArtifactKindEnum::Subagent);
        $this->registry()->update($parent, $artifactId, status: AgentArtifactStatusEnum::Completed, summary: 'done');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('is an agent child; nested launches are not supported');

        $this->resume(
            parentRunId: $parent,
            task: new AgentResumeArgumentsDTO(artifact_id: $artifactId, task: 'continue'),
            childRunId: $childRunId,
            relationshipReader: StubRunRelationshipReader::child($parent, 'grandparent'),
        );
    }

    public function testFollowUpFailureMarksBatchFailedAndRevertsChildToPriorTerminal(): void
    {
        $parent = $this->parentSession('followup-fail');
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
                task: new AgentResumeArgumentsDTO(artifact_id: $artifactId, task: 'continue'),
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
            task: new AgentResumeArgumentsDTO(artifact_id: $artifactId, task: 'continue'),
            childRunId: $childRunId,
            runStatus: RunStatus::Completed,
            toolCallId: $toolCallId,
        );
    }

    public function testLaunchSuccessPersistenceFailureLeavesDeferredResumeRecoverable(): void
    {
        $parent = $this->parentSession('launch-success-persist-failure');
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
            task: new AgentResumeArgumentsDTO(artifact_id: $artifactId, task: 'continue'),
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
        $parent = $this->parentSession('lifetime');
        $artifactId = 'agent_lifetime';
        $childRunId = 'child-lifetime';
        $this->seedTerminalChild($parent, $artifactId, $childRunId, latestInputTokens: 10, contextWindow: 200_000);

        $this->resume(
            parentRunId: $parent,
            task: new AgentResumeArgumentsDTO(artifact_id: $artifactId, task: 'continue'),
            childRunId: $childRunId,
            toolCallId: 'tc-lifetime-before-attach',
        );
        $this->registry()->update($parent, $artifactId, status: AgentArtifactStatusEnum::Completed);

        self::getContainer()->get(InProcessAgentSessionClient::class)->attach($parent);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('belongs to a previous parent lifetime');
        $this->resume(
            parentRunId: $parent,
            task: new AgentResumeArgumentsDTO(artifact_id: $artifactId, task: 'continue after attach'),
            childRunId: $childRunId,
            toolCallId: 'tc-lifetime-after-attach',
        );
    }

    public function testMissingOperationalStatusFailsClosed(): void
    {
        $parent = $this->parentSession('missing-status');
        $child = 'child-missing-status';
        $artifact = 'agent_missing_status';
        $this->seedTerminalChild($parent, $artifact, $child, latestInputTokens: 10, contextWindow: 200_000);
        $runner = $this->createMock(AgentRunnerInterface::class);
        $runner->expects($this->never())->method('followUp');
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('is unusable for resume');
        $this->resume($parent, new AgentResumeArgumentsDTO(artifact_id: $artifact, task: 'continue'), agentRunner: $runner,
            operationalStatusReader: self::getContainer()->get(RunOperationalStatusReaderInterface::class));
    }

    public function testUnavailableOperationalStatusFailsClosed(): void
    {
        $parent = $this->parentSession('unavailable-status');
        $this->seedTerminalChild($parent, 'agent_unavailable', 'child-unavailable', latestInputTokens: 10, contextWindow: 200_000);
        $reader = $this->createMock(RunOperationalStatusReaderInterface::class);
        $reader->expects($this->once())->method('findOperationalStatus')->willThrowException(new \RuntimeException('projection unavailable'));
        $runner = $this->createMock(AgentRunnerInterface::class);
        $runner->expects($this->never())->method('followUp');
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('is unusable for resume');
        $this->resume($parent, new AgentResumeArgumentsDTO(artifact_id: 'agent_unavailable', task: 'continue'), agentRunner: $runner, operationalStatusReader: $reader);
    }

    public function testConfiguredOperationalReaderObservesTerminalStatusThenFreshRunningStatus(): void
    {
        $parent = $this->parentSession('fresh-status');
        $child = 'child-fresh-status';
        $artifact = 'agent_fresh_status';
        $this->seedTerminalChild($parent, $artifact, $child, latestInputTokens: 10, contextWindow: 200_000);
        $repository = self::getContainer()->get(RunOperationalProjectionRepository::class);
        $repository->replace(new RunState($child, RunStatus::Completed, parentRunId: $parent, lastSeq: 1));
        $reader = self::getContainer()->get(RunOperationalStatusReaderInterface::class);
        $runner = $this->createMock(AgentRunnerInterface::class);
        $runner->expects($this->once())->method('followUp')->with($child, $this->isInstanceOf(AgentMessage::class));
        $this->resume($parent, new AgentResumeArgumentsDTO(artifact_id: $artifact, task: 'continue'), agentRunner: $runner, operationalStatusReader: $reader);
        $this->registry()->update(parentRunId: $parent, artifactId: $artifact, status: AgentArtifactStatusEnum::Completed);
        self::getContainer()->get('doctrine')->getConnection()->executeStatement(
            'UPDATE run_operational_state SET status = ? WHERE run_id = ?', [RunStatus::Running->value, $child]);
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('is not terminal (status=running)');
        // A later batch must re-run eligibility; reusing the launched invocation skips it.
        $this->resume($parent, new AgentResumeArgumentsDTO(artifact_id: $artifact, task: 'continue'), agentRunner: $runner, operationalStatusReader: $reader, toolCallId: 'tc-resume-2');
    }

    public function testCanonicalAppendWithoutProjectionPublicationRejectsResume(): void
    {
        $parent = $this->parentSession('behind-canonical');
        $child = 'child-behind-canonical';
        $artifact = 'agent_behind_canonical';
        $this->seedTerminalChild($parent, $artifact, $child, latestInputTokens: 10, contextWindow: 200_000);
        $readLog = new TestLogger();
        $factory = new AgentChildRunEventStoreFactory(
            self::getContainer()->get(SessionAgentArtifactPathResolver::class),
            self::getContainer()->get(EventPayloadNormalizer::class),
            self::getContainer()->get(LockFactory::class),
            $readLog,
            self::getContainer()->get(RunSequenceAllocatorInterface::class),
            presentations: HistoryEventStoreFactory::presentationFactory());
        $store = $factory->create($parent, $child, $artifact);
        $history = [];
        for ($turn = 1; $turn <= 100; ++$turn) {
            $history[] = RunEvent::forAppend($child, $turn, 'turn_advanced');
        }
        PreparedEventStoreSeeder::appendMany($store, $history);
        $terminal = PreparedEventStoreSeeder::append($store, RunEvent::forAppend($child, 101, 'agent_end', ['status' => 'completed']));
        $repository = self::getContainer()->get(RunOperationalProjectionRepository::class);
        $repository->replace(new RunState($child, RunStatus::Completed, parentRunId: $parent, lastSeq: $terminal->seq));
        // Fault boundary: the next canonical append succeeds, but projection
        // publication never happens. Artifact and narrow status remain terminal.
        $newWork = PreparedEventStoreSeeder::append($store, RunEvent::forAppend($child, 2, 'turn_advanced'));
        $reader = self::getContainer()->get(RunOperationalStatusReaderInterface::class);
        $this->assertSame(RunStatus::Completed, $reader->findOperationalStatus($child)?->status);
        $this->assertGreaterThan($terminal->seq, $newWork->seq);
        $runner = $this->createMock(AgentRunnerInterface::class);
        $runner->expects($this->never())->method('followUp');
        $readLog->records = [];
        try {
            $this->resume($parent, new AgentResumeArgumentsDTO(artifact_id: $artifact, task: 'continue'),
                agentRunner: $runner, operationalStatusReader: $reader, eventStore: $store);
            $this->fail('A terminal projection behind canonical history must not authorize resume.');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('unusable for resume', $e->getMessage());
            $this->assertStringContainsString('not current; recovery required', $e->getPrevious()?->getMessage() ?? '');
        }
        $this->assertNull(self::getContainer()->get(DeferredSubagentBatchRepository::class)->findByParentRunAndToolCall($parent, 'tc-resume-1'));
        $reads = array_values(array_filter($readLog->records,
            static fn (array $record): bool => 'child_event_store.physical_read' === ($record['context']['event_type'] ?? null)));
        $this->assertCount(1, $reads);
        $read = $reads[0]['context'];
        $this->assertSame('reverseFor', $read['method']);
        $this->assertSame(1, $read['decoded_event_count']);
        $this->assertSame(8192, $read['archive_bytes_read']);
        $this->assertFalse($read['full_scan']);
        $path = self::getContainer()->get(SessionAgentArtifactPathResolver::class)->eventsPath($parent, $artifact);
        $this->assertGreaterThan($read['archive_bytes_read'], filesize($path));
    }

    public function testActualTerminalTailAcceptsDespiteAllocationHoles(): void
    {
        $parent = $this->parentSession('allocation-holes');
        $child = 'child-allocation-holes';
        $artifact = 'agent_allocation_holes';
        $this->seedTerminalChild($parent, $artifact, $child, latestInputTokens: 10, contextWindow: 200_000);
        $store = self::getContainer()->get(AgentChildRunEventStoreFactory::class)->create($parent, $child, $artifact);
        $first = PreparedEventStoreSeeder::append($store, RunEvent::forAppend($child, 1, 'run_started'));
        $path = self::getContainer()->get(SessionAgentArtifactPathResolver::class)->eventsPath($parent, $artifact);
        $allocator = new FileRunSequenceAllocator();
        $allocator->allocateBlock(FileRunSequenceAllocator::counterPathForEventsLog($path), 5);
        $terminal = PreparedEventStoreSeeder::append($store, RunEvent::forAppend($child, 1, 'agent_end', ['status' => 'completed']));
        $this->assertSame($first->seq + 6, $terminal->seq);
        $allocated = $allocator->allocateBlock(FileRunSequenceAllocator::counterPathForEventsLog($path), 3);
        $this->assertGreaterThan($terminal->seq, $allocated[0]);
        $this->assertSame($terminal->seq, $store->latestSequenceFor($child));
        self::getContainer()->get(RunOperationalProjectionRepository::class)->replace(
            new RunState($child, RunStatus::Completed, parentRunId: $parent, lastSeq: $terminal->seq));
        $runner = $this->createMock(AgentRunnerInterface::class);
        $runner->expects($this->once())->method('followUp')->with($child, $this->isInstanceOf(AgentMessage::class));
        $outcome = $this->resume($parent, new AgentResumeArgumentsDTO(artifact_id: $artifact, task: 'continue'),
            agentRunner: $runner, operationalStatusReader: self::getContainer()->get(RunOperationalStatusReaderInterface::class), eventStore: $store);
        $this->assertNotNull(self::getContainer()->get(DeferredSubagentBatchRepository::class)->findByLifecycleId($outcome->deferredId));
    }

    public function testSameChildBatchDuplicatesRefuseBeforeReservationRegardlessOfArrivalOrder(): void
    {
        $parent = $this->parentSession('batch-dup');
        $artifact = 'agent_batch_dup';
        $child = 'child-batch-dup';
        $this->seedTerminalChild($parent, $artifact, $child, latestInputTokens: 10, contextWindow: 200_000);
        $followUps = 0;
        $runner = $this->createStub(AgentRunnerInterface::class);
        $runner->method('followUp')->willReturnCallback(static function () use (&$followUps): void {
            ++$followUps;
        });

        $stepId = 'dup-step';
        $store = self::getContainer()->get(ToolBatchStoreInterface::class);
        TestToolBatchRegistration::register(
            new ToolBatchCollector($store),
            $store,
            $parent,
            1,
            $stepId,
            [
                new ExecuteToolCall($parent, 1, $stepId, 1, hash('sha256', 'first'), 'resume-first', 'agent_resume', [
                    'artifact_id' => $artifact,
                    'task' => 'continue',
                ], 0, parentModel: 'test/model'),
                new ExecuteToolCall($parent, 1, $stepId, 1, hash('sha256', 'second'), 'resume-second', 'agent_resume', [
                    'agent_run_id' => $child,
                    'task' => 'continue alias',
                ], 1, parentModel: 'test/model'),
                new ExecuteToolCall($parent, 1, $stepId, 1, hash('sha256', 'other'), 'resume-other', 'agent_resume', [
                    'artifact_id' => 'agent_other',
                    'task' => 'other child',
                ], 2, parentModel: 'test/model'),
            ],
        );
        $this->seedTerminalChild($parent, 'agent_other', 'child-other', latestInputTokens: 10, contextWindow: 200_000);

        $context = new StackToolExecutionContextAccessor();
        $service = $this->service($runner, $parent, $context);
        $secondCall = new ExecuteToolCall($parent, 1, $stepId, 1, hash('sha256', 'second'), 'resume-second', 'agent_resume', [
            'agent_run_id' => $child,
            'task' => 'continue alias',
        ], 1, parentModel: 'test/model');
        try {
            $service->prepare($secondCall);
            $second = null;
        } catch (\Throwable $error) {
            $second = $error;
        }
        $this->assertInstanceOf(ToolCallException::class, $second);
        $this->assertStringContainsString('Duplicate agent_resume', $second->getMessage());
        $this->assertSame(0, $followUps);
        $this->assertNull(self::getContainer()->get(DeferredSubagentBatchRepository::class)->findByParentRunAndToolCall($parent, 'resume-second'));

        $firstCall = new ExecuteToolCall($parent, 1, $stepId, 1, hash('sha256', 'first'), 'resume-first', 'agent_resume', [
            'artifact_id' => $artifact,
            'task' => 'continue',
        ], 0, parentModel: 'test/model');
        $service->prepare($firstCall);
        $first = $context->with(
            new ToolContext($parent, 1, 'resume-first', 'agent_resume', new NullCancellationToken(), 30, 0, stepId: $stepId, parentModel: 'test/model'),
            static fn () => $service->resume($parent, new AgentResumeArgumentsDTO(artifact_id: $artifact, task: 'continue')),
        );
        $this->assertSame(1, $followUps);
        $this->assertSame($first->deferredId, self::getContainer()->get(DeferredSubagentChildRepository::class)->findByChildRunId($child)?->batchLifecycleId);

        $otherCall = new ExecuteToolCall($parent, 1, $stepId, 1, hash('sha256', 'other'), 'resume-other', 'agent_resume', [
            'artifact_id' => 'agent_other',
            'task' => 'other child',
        ], 2, parentModel: 'test/model');
        $service->prepare($otherCall);
        $other = $context->with(
            new ToolContext($parent, 1, 'resume-other', 'agent_resume', new NullCancellationToken(), 30, 2, stepId: $stepId, parentModel: 'test/model'),
            static fn () => $service->resume($parent, new AgentResumeArgumentsDTO(artifact_id: 'agent_other', task: 'other child')),
        );
        $this->assertSame(2, $followUps);
        $this->assertNotSame($first->deferredId, $other->deferredId);

        // Completing the winner does not make the same-batch duplicate eligible.
        self::getContainer()->get(AgentArtifactRegistry::class)->update($parent, $artifact, status: AgentArtifactStatusEnum::Completed, summary: 'done again');
        try {
            $service->prepare($secondCall);
            $replay = null;
        } catch (\Throwable $error) {
            $replay = $error;
        }
        $this->assertInstanceOf(ToolCallException::class, $replay);
        $this->assertStringContainsString('Duplicate agent_resume', $replay->getMessage());
        $this->assertSame(2, $followUps);

        // A later tool batch may resume the same child again.
        $later = $this->resume($parent, new AgentResumeArgumentsDTO(artifact_id: $artifact, task: 'later batch'), agentRunner: $runner, toolCallId: 'resume-later');
        $this->assertSame(3, $followUps);
        $this->assertSame($later->deferredId, self::getContainer()->get(DeferredSubagentChildRepository::class)->findByChildRunId($child)?->batchLifecycleId);
    }

    public function testQueuedFollowUpDoesNotAdmitSameBatchDuplicate(): void
    {
        $parent = $this->parentSession('queued-dup');
        $artifact = 'agent_queued_dup';
        $child = 'child-queued-dup';
        $this->seedTerminalChild($parent, $artifact, $child, latestInputTokens: 10, contextWindow: 200_000);
        $commandBus = new TestMessageBus();
        $agentRunner = new AgentRunner($commandBus, self::getContainer()->get(SerializerInterface::class));
        $stepId = 'queued-dup-step';
        $store = self::getContainer()->get(ToolBatchStoreInterface::class);
        TestToolBatchRegistration::register(
            new ToolBatchCollector($store),
            $store,
            $parent,
            1,
            $stepId,
            [
                new ExecuteToolCall($parent, 1, $stepId, 1, hash('sha256', 'q1'), 'resume-q1', 'agent_resume', [
                    'artifact_id' => $artifact,
                    'task' => 'continue',
                ], 0, parentModel: 'test/model'),
                new ExecuteToolCall($parent, 1, $stepId, 1, hash('sha256', 'q2'), 'resume-q2', 'agent_resume', [
                    'artifact_id' => $artifact,
                    'task' => 'duplicate',
                ], 1, parentModel: 'test/model'),
            ],
        );

        $context = new StackToolExecutionContextAccessor();
        $service = $this->service($agentRunner, $parent, $context);
        $winnerCall = new ExecuteToolCall($parent, 1, $stepId, 1, hash('sha256', 'q1'), 'resume-q1', 'agent_resume', [
            'artifact_id' => $artifact,
            'task' => 'continue',
        ], 0, parentModel: 'test/model');
        $service->prepare($winnerCall);
        $winner = $context->with(
            new ToolContext($parent, 1, 'resume-q1', 'agent_resume', new NullCancellationToken(), 30, 0, stepId: $stepId, parentModel: 'test/model'),
            static fn () => $service->resume($parent, new AgentResumeArgumentsDTO(artifact_id: $artifact, task: 'continue')),
        );
        $this->assertCount(1, $commandBus->messages);
        $command = $commandBus->messages[0];
        $this->assertInstanceOf(ApplyCommand::class, $command);
        $state = new RunState(runId: $child, status: RunStatus::Completed, messages: [
            new AgentMessage('assistant', [['type' => 'text', 'text' => 'prior handoff']]),
        ]);
        $queued = self::getContainer()->get(ApplyCommandHandler::class)->handle($command, $state);
        $this->assertNotNull($queued->nextState);
        // Hold AdvanceRun/observation: only enqueue the follow_up command.
        $enqueues = array_values(array_filter($queued->postCommitActions, static fn (object $action): bool => $action instanceof EnqueueCommandDTO));
        $this->assertCount(1, $enqueues);
        self::getContainer()->get(\Ineersa\AgentCore\Contract\CommandStoreInterface::class)->enqueue($enqueues[0]->command);

        $duplicateCall = new ExecuteToolCall($parent, 1, $stepId, 1, hash('sha256', 'q2'), 'resume-q2', 'agent_resume', [
            'artifact_id' => $artifact,
            'task' => 'duplicate',
        ], 1, parentModel: 'test/model');
        try {
            $service->prepare($duplicateCall);
            $duplicate = null;
        } catch (\Throwable $error) {
            $duplicate = $error;
        }
        $this->assertInstanceOf(ToolCallException::class, $duplicate);
        $this->assertStringContainsString('Duplicate agent_resume', $duplicate->getMessage());
        $this->assertSame($winner->deferredId, self::getContainer()->get(DeferredSubagentChildRepository::class)->findByChildRunId($child)?->batchLifecycleId);
        $this->assertNull(self::getContainer()->get(DeferredSubagentBatchRepository::class)->findByParentRunAndToolCall($parent, 'resume-q2'));
        $this->assertCount(1, $commandBus->messages);
    }

    public function testMissingOwnerPreparationRefusesWithoutFollowUp(): void
    {
        $parent = $this->parentSession('missing-prep');
        $artifact = 'agent_missing_prep';
        $child = 'child-missing-prep';
        $this->seedTerminalChild($parent, $artifact, $child, latestInputTokens: 10, contextWindow: 200_000);
        $runner = $this->createMock(AgentRunnerInterface::class);
        $runner->expects($this->never())->method('followUp');
        $context = new StackToolExecutionContextAccessor();
        $service = $this->service($runner, $parent, $context);
        $stepId = 'missing-prep-step';
        TestToolBatchRegistration::register(
            new ToolBatchCollector(self::getContainer()->get(ToolBatchStoreInterface::class)),
            self::getContainer()->get(ToolBatchStoreInterface::class),
            $parent,
            1,
            $stepId,
            [new ExecuteToolCall($parent, 1, $stepId, 1, hash('sha256', 'missing'), 'resume-missing', 'agent_resume', [
                'artifact_id' => $artifact,
                'task' => 'continue',
            ], 0, parentModel: 'test/model')],
        );

        try {
            $context->with(
                new ToolContext($parent, 1, 'resume-missing', 'agent_resume', new NullCancellationToken(), 30, 0, stepId: $stepId, parentModel: 'test/model'),
                static fn () => $service->resume($parent, new AgentResumeArgumentsDTO(artifact_id: $artifact, task: 'continue')),
            );
            $error = null;
        } catch (\Throwable $caught) {
            $error = $caught;
        }

        $this->assertInstanceOf(ToolCallException::class, $error);
        $this->assertStringContainsString('Owner-prepared agent_resume reservation is missing', $error->getMessage());
    }

    private function parentSession(string $label): string
    {
        return self::getContainer()->get(HatfieldSessionStore::class)->createSession('agent-resume '.$label);
    }

    private function service(
        AgentRunnerInterface $agentRunner,
        string $parentRunId,
        ?StackToolExecutionContextAccessor $contextAccessor = null,
    ): AgentResumeExecutionService {
        return new AgentResumeExecutionService(
            artifactRegistry: $this->registry(),
            batchRepository: self::getContainer()->get(DeferredSubagentBatchRepository::class),
            childRepository: self::getContainer()->get(DeferredSubagentChildRepository::class),
            identityFactory: new DeferredSubagentBatchIdentityFactory(),
            agentRunner: $agentRunner,
            operationalStatusReader: $this->createConfiguredStub(RunOperationalStatusReaderInterface::class, [
                'findOperationalStatus' => new RunOperationalStatusDTO(RunStatus::Completed, 1),
            ]),
            eventStore: $this->createConfiguredStub(EventStoreInterface::class, [
                'latestSequenceFor' => 1,
            ]),
            runLockManager: self::getContainer()->get(RunLockManager::class),
            relationshipReader: StubRunRelationshipReader::topLevel($parentRunId),
            depthGuard: new AgentDepthGuard(),
            contextAccessor: $contextAccessor ?? new StackToolExecutionContextAccessor(),
            agentsConfig: new AgentsConfig(),
            logger: new NullLogger(),
            childLifecycle: self::getContainer()->get(ChildInvocationLifecycleService::class),
            toolBatches: self::getContainer()->get(ToolBatchStoreInterface::class),
            denormalizer: self::getContainer()->get(SerializerInterface::class),
            validator: self::getContainer()->get(ValidatorInterface::class),
        );
    }

    private function resume(
        string $parentRunId,
        AgentResumeArgumentsDTO $task,
        ?string $childRunId = null,
        RunStatus $runStatus = RunStatus::Completed,
        ?AgentRunnerInterface $agentRunner = null,
        string $toolCallId = 'tc-resume-1',
        ?TestLogger $logger = null,
        ?RunOperationalStatusReaderInterface $operationalStatusReader = null,
        ?EventStoreInterface $eventStore = null,
        ?\Ineersa\CodingAgent\Repository\RunRelationshipReaderInterface $relationshipReader = null,
    ): \Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome {
        $contextAccessor = new StackToolExecutionContextAccessor();
        if (null === $operationalStatusReader) {
            $operationalStatusReader = $this->createStub(RunOperationalStatusReaderInterface::class);
            $operationalStatusReader->method('findOperationalStatus')->willReturnCallback(
                static function (string $runId) use ($runStatus): RunOperationalStatusDTO {
                    return new RunOperationalStatusDTO($runStatus, 1);
                },
            );
        }

        if (null === $eventStore) {
            $eventStore = $this->createStub(EventStoreInterface::class);
            $eventStore->method('latestSequenceFor')->willReturn(1);
        }

        $service = new AgentResumeExecutionService(
            artifactRegistry: $this->registry(),
            batchRepository: self::getContainer()->get(DeferredSubagentBatchRepository::class),
            childRepository: self::getContainer()->get(DeferredSubagentChildRepository::class),
            identityFactory: new DeferredSubagentBatchIdentityFactory(),
            agentRunner: $agentRunner ?? $this->createStub(AgentRunnerInterface::class),
            operationalStatusReader: $operationalStatusReader,
            eventStore: $eventStore,
            runLockManager: self::getContainer()->get(RunLockManager::class),
            relationshipReader: $relationshipReader ?? StubRunRelationshipReader::topLevel($parentRunId),
            depthGuard: new AgentDepthGuard(),
            contextAccessor: $contextAccessor,
            agentsConfig: new AgentsConfig(),
            logger: $logger ?? new NullLogger(),
            childLifecycle: self::getContainer()->get(ChildInvocationLifecycleService::class),
            toolBatches: self::getContainer()->get(ToolBatchStoreInterface::class),
            denormalizer: self::getContainer()->get(SerializerInterface::class),
            validator: self::getContainer()->get(ValidatorInterface::class),
        );

        $stepId = 'resume-step-'.$toolCallId;
        $call = new ExecuteToolCall(
            runId: $parentRunId,
            turnNo: 1,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', $parentRunId.'|'.$stepId.'|'.$toolCallId),
            toolCallId: $toolCallId,
            toolName: 'agent_resume',
            args: array_filter([
                'task' => $task->task,
                'artifact_id' => $task->artifact_id,
                'agent_run_id' => $task->agent_run_id,
                'backgrounded' => $task->backgrounded,
            ], static fn (mixed $value): bool => null !== $value),
            orderIndex: 0,
            parentModel: 'test/model',
        );
        TestToolBatchRegistration::register(
            new ToolBatchCollector(self::getContainer()->get(ToolBatchStoreInterface::class)),
            self::getContainer()->get(ToolBatchStoreInterface::class),
            $parentRunId,
            1,
            $stepId,
            [$call],
        );

        $service->prepare($call);

        return $contextAccessor->with(
            new ToolContext(
                runId: $parentRunId,
                turnNo: 1,
                toolCallId: $toolCallId,
                toolName: 'agent_resume',
                cancellationToken: new NullCancellationToken(),
                timeoutSeconds: 30,
                orderIndex: 0,
                stepId: $stepId,
                parentModel: 'test/model',
            ),
            static function () use ($service, $parentRunId, $task): \Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome {
                return $service->resume($parentRunId, $task);
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
