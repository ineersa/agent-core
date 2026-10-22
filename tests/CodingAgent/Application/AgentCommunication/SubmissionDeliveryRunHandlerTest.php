<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\AgentCommunication;

use Ineersa\AgentCore\Application\Pipeline\AdvanceRunHandler;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Extension\CommandCancellationOptions;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Run\CurrentOperationDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchChildIntentDTO;
use Ineersa\CodingAgent\Application\AgentCommunication\DTO\AcceptSubmissionDTO;
use Ineersa\CodingAgent\Application\AgentCommunication\DTO\DeliverSubmissionDTO;
use Ineersa\CodingAgent\Application\AgentCommunication\SubmissionDeliveryRunHandler;
use Ineersa\CodingAgent\Application\AgentCommunication\SubmissionRunHandler;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Session\Contract\RunHistorySourceProviderInterface;
use Ineersa\CodingAgent\Tests\Support\SubmissionFixture;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Assert;
use Symfony\Component\Filesystem\Filesystem;

final class SubmissionDeliveryRunHandlerTest extends IsolatedKernelTestCase
{
    public function testFullSharedMailboxRejectsNoticeWithoutEnqueueOrWakeAndKeepsDecision(): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c);
        $c->get(DeferredSubagentBatchRepository::class)->markBackgrounded($f->lifecycle, new \DateTimeImmutable());
        $f->status($f->parent, RunStatus::Completed);
        $delivery = $this->accept($f, true);
        $state = $f->parentState->with(['lastSeq' => 3, 'status' => RunStatus::Completed, 'pendingToolCalls' => [], 'activeStepId' => null]);
        $mailbox = $c->get(CommandStoreInterface::class);
        for ($i = 0; $i < 100; ++$i) {
            $mailbox->enqueue(new PendingCommand($f->parent, CoreCommandKind::AppendMessage, 'existing-'.$i, [], new CommandCancellationOptions()));
        }
        $handler = $c->get(SubmissionDeliveryRunHandler::class);
        $result = $handler->handle($delivery, $state);
        Assert::assertSame('agent_command_rejected', $result->events[0]->type);
        Assert::assertSame('Pending command mailbox cap (100) exceeded for run.', $result->events[0]->payload['reason']);
        Assert::assertSame($delivery->identity, $result->events[0]->payload['agent_communication']['identity']);
        Assert::assertSame([], $result->postCommitActions);
        Assert::assertSame([], $result->effects);
        Assert::assertSame([], $result->postCommitEffects);
        Assert::assertSame(100, $mailbox->countPending($f->parent));
        Assert::assertFalse($mailbox->has($f->parent, $delivery->identity));
        $this->commit($state, $result);
        $mailbox->markApplied($f->parent, 'existing-0');
        $duplicate = $handler->handle($delivery, $result->nextState);
        Assert::assertNull($duplicate->nextState);
        Assert::assertSame([], $duplicate->events);
        Assert::assertSame([], $duplicate->postCommitActions);
        Assert::assertSame(99, $mailbox->countPending($f->parent));
    }

    public function testAuthoredMessageCanUseTheLastSharedMailboxSlot(): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c);
        $delivery = $this->accept($f);
        $mailbox = $c->get(CommandStoreInterface::class);
        for ($i = 0; $i < 99; ++$i) {
            $mailbox->enqueue(new PendingCommand($f->child, CoreCommandKind::AppendMessage, 'existing-'.$i, [], new CommandCancellationOptions()));
        }
        $result = $c->get(SubmissionDeliveryRunHandler::class)->handle($delivery, $f->childState);
        Assert::assertSame('agent_command_queued', $result->events[0]->type);
        $this->commit($f->childState, $result);
        Assert::assertSame(100, $mailbox->countPending($f->child));
        Assert::assertTrue($mailbox->has($f->child, $delivery->identity));
    }

    public function testDeliveryAndSourceDuplicatesRemainNoOpsAfterMailboxConsumptionAndIndexRebuild(): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c);
        $delivery = $this->accept($f);
        $handler = $c->get(SubmissionDeliveryRunHandler::class);
        $result = $handler->handle($delivery, $f->childState);
        Assert::assertCount(1, $result->events);
        Assert::assertNull($c->get(RuntimeEventTranslator::class)->translate($result->events[0]));
        $this->commit($f->childState, $result);
        $state = $result->nextState;
        Assert::assertSame($f->childState->pendingToolCalls, $state->pendingToolCalls);
        $boundary = $c->get(CommandMailboxPolicy::class)->applyPendingTurnStartCommands($state);
        Assert::assertSame('agent_communication', $boundary->state->messages[array_key_last($boundary->state->messages)]->role);
        Assert::assertStringContainsString('not a human request or runtime control', $boundary->eventSpecs[0]['payload']['text']);
        $advance = $c->get(AdvanceRunHandler::class)->handle(new AdvanceRun($f->child, 1, 'boundary', 1, 'boundary'), $state->with(['pendingToolCalls' => [], 'activeStepId' => null]));
        $this->commit($state, $advance);
        Assert::assertSame([], $c->get(CommandStoreInterface::class)->pending($f->child));
        $source = $c->get(RunHistorySourceProviderInterface::class)->historySource($f->child);
        (new Filesystem())->remove(\dirname($source->path).'/history-index.sqlite');
        Assert::assertSame([], $handler->handle($delivery, $advance->nextState)->events);
        $duplicate = $c->get(SubmissionRunHandler::class)->handle(new AcceptSubmissionDTO($f->parent, $delivery->identity), $f->parentState);
        Assert::assertSame([], $duplicate->events);
        Assert::assertSame([], $handler->handle($duplicate->postCommitEffects[1], $advance->nextState)->events);
    }

    public function testForeignRecipientCannotUseCanonicalSourceReceipt(): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c);
        $delivery = $this->accept($f);
        $this->expectException(\RuntimeException::class);
        $c->get(SubmissionDeliveryRunHandler::class)->handle(new DeliverSubmissionDTO($f->parent, $delivery->sourceRunId, $delivery->identity), $f->parentState);
    }

    public function testAdmissionDoesNotReplaceAnInFlightProviderOperation(): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c);
        $delivery = $this->accept($f);
        $state = $f->childState->with(['pendingToolCalls' => [], 'currentOperation' => new CurrentOperationDTO(1, 'provider-step', 1, 'provider-key')]);
        $result = $c->get(SubmissionDeliveryRunHandler::class)->handle($delivery, $state);
        Assert::assertSame($state->currentOperation, $result->nextState->currentOperation);
        Assert::assertSame($state->messages, $result->nextState->messages);
        Assert::assertSame([], $result->effects);
        Assert::assertCount(1, $result->postCommitActions);
    }

    public function testLaterResumeFencesAlreadyAcceptedSteeringAtApplication(): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c);
        $delivery = $this->accept($f);
        $result = $c->get(SubmissionDeliveryRunHandler::class)->handle($delivery, $f->childState);
        $this->commit($f->childState, $result);
        $c->get(DeferredSubagentBatchRepository::class)->reserveBatch('later-resume', $f->parent, 1, 'resume', 0, new \DateTimeImmutable('+1 hour'), new DeferredSubagentBatchChildIntentDTO(1, $f->child, $f->artifact, 'scout', 'new task', 'model', 'medium'));
        $boundary = $c->get(CommandMailboxPolicy::class)->applyPendingTurnStartCommands($result->nextState);
        Assert::assertSame('agent_command_rejected', $boundary->eventSpecs[0]['type']);
        Assert::assertSame($result->nextState->messages, $boundary->state->messages);
    }

    public function testHumanWaitAndCompactionDeferWithoutLosingTheOriginalContinuation(): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c);
        $f->status($f->child, RunStatus::Compacting);
        $delivery = $this->accept($f);
        $state = $f->childState->with(['status' => RunStatus::Compacting]);
        $result = $c->get(SubmissionDeliveryRunHandler::class)->handle($delivery, $state);
        Assert::assertCount(1, $result->postCommitActions); // enqueue only, no advance
        $this->commit($state, $result);
        $mailbox = $c->get(CommandMailboxPolicy::class);
        $compacting = $mailbox->applyPendingTurnStartCommands($result->nextState);
        Assert::assertSame([], $compacting->eventSpecs);
        Assert::assertEquals($result->nextState, $compacting->state);
        $waiting = $result->nextState->with(['status' => RunStatus::WaitingHuman, 'pendingHumanInputRequests' => ['approval' => ['request_id' => 'exact-request']]]);
        $human = $mailbox->applyPendingTurnStartCommands($waiting);
        Assert::assertSame([], $human->eventSpecs);
        Assert::assertSame($waiting->pendingHumanInputRequests, $human->state->pendingHumanInputRequests);
        $humanAdvance = $c->get(AdvanceRunHandler::class)->handle(new AdvanceRun($f->child, 1, 'human-boundary', 1, 'human-boundary'), $waiting->with(['pendingToolCalls' => []]));
        Assert::assertSame([], $humanAdvance->events);
        Assert::assertSame([], $humanAdvance->effects);
        Assert::assertCount(1, $c->get(CommandStoreInterface::class)->pending($f->child));
        $ready = $mailbox->applyPendingTurnStartCommands($waiting->with(['status' => RunStatus::Running, 'pendingHumanInputRequests' => []]));
        Assert::assertSame('agent_command_applied', $ready->eventSpecs[0]['type']);
    }

    public function testForegroundNoticeSurvivesNormalChildFinishWithoutResolvingItsParentCall(): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c);
        $delivery = $this->accept($f, true);
        $result = $c->get(SubmissionDeliveryRunHandler::class)->handle($delivery, $f->parentState);
        Assert::assertSame($f->parentState->pendingToolCalls, $result->nextState->pendingToolCalls);
        Assert::assertCount(1, $result->postCommitActions);
        $this->commit($f->parentState, $result);
        $f->status($f->child, RunStatus::Completed);
        $batches = $c->get(DeferredSubagentBatchRepository::class);
        $batch = $batches->findByLifecycleId($f->lifecycle);
        Assert::assertNotNull($batch);
        $batches->markTerminalCompletionEnqueued($f->lifecycle, new \DateTimeImmutable(), $batch->projectionVersion);
        $advance = $c->get(AdvanceRunHandler::class)->handle(new AdvanceRun($f->parent, 1, 'blocked', 1, 'blocked'), $result->nextState);
        Assert::assertSame([], $advance->events);
        $boundary = $c->get(CommandMailboxPolicy::class)->applyPendingTurnStartCommands($result->nextState->with(['pendingToolCalls' => []]));
        Assert::assertSame('agent_command_applied', $boundary->eventSpecs[0]['type']);
    }

    public function testCompletedMainWakeIsRevalidatedBeforeAdvancement(): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c);
        $batches = $c->get(DeferredSubagentBatchRepository::class);
        $batches->markBackgrounded($f->lifecycle, new \DateTimeImmutable());
        $f->status($f->parent, RunStatus::Completed);
        $delivery = $this->accept($f, true);
        $state = $f->parentState->with(['status' => RunStatus::Completed, 'pendingToolCalls' => [], 'activeStepId' => null]);
        foreach ([RunStatus::Cancelled, RunStatus::Failed] as $status) {
            $terminal = $c->get(SubmissionDeliveryRunHandler::class)->handle($delivery, $state->with(['status' => $status]));
            Assert::assertSame([], $terminal->postCommitActions);
            Assert::assertSame('agent_command_rejected', $terminal->events[0]->type);
        }
        $result = $c->get(SubmissionDeliveryRunHandler::class)->handle($delivery, $state);
        Assert::assertCount(2, $result->postCommitActions);
        // Persist only admission here; inspect the captured wake without dispatching it.
        $queued = new HandlerResult($result->nextState, $result->events, postCommitActions: [$result->postCommitActions[0]]);
        $this->commit($state, $queued);
        $wake = $result->postCommitActions[1]->message;
        $allowed = $c->get(AdvanceRunHandler::class)->handle($wake, $result->nextState);
        Assert::assertSame(RunStatus::Running, $allowed->nextState->status);
        $f->status($f->child, RunStatus::Completed);
        $refused = $c->get(AdvanceRunHandler::class)->handle($wake, $result->nextState);
        Assert::assertSame(RunStatus::Completed, $refused->nextState->status);
        Assert::assertSame([], $refused->effects);
        Assert::assertSame('agent_command_rejected', $refused->events[0]->type);
    }

    public function testTerminalChildCannotReceiveSteeringAndHistoryDiscardRetiresAcceptedGuidance(): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c);
        $delivery = $this->accept($f);
        $handler = $c->get(SubmissionDeliveryRunHandler::class);
        $terminal = $handler->handle($delivery, $f->childState->with(['status' => RunStatus::Completed]));
        Assert::assertSame('agent_command_rejected', $terminal->events[0]->type);
        Assert::assertSame([], $terminal->postCommitActions);
        $result = $handler->handle($delivery, $f->childState);
        $this->commit($f->childState, $result);
        $f->append($f->parent, 5, 0, 'history_position_set', ['position_turn_no' => 0, 'reason' => 'history_select']);
        $boundary = $c->get(CommandMailboxPolicy::class)->applyPendingTurnStartCommands($result->nextState);
        Assert::assertSame('agent_command_rejected', $boundary->eventSpecs[0]['type']);
        Assert::assertSame($result->nextState->messages, $boundary->state->messages);
    }

    private function accept(SubmissionFixture $f, bool $notice = false): DeliverSubmissionDTO
    {
        $receipt = $f->prepare($notice);
        $f->register($receipt);
        $state = $notice ? $f->childState : $f->parentState;
        $result = self::getContainer()->get(SubmissionRunHandler::class)->handle(new AcceptSubmissionDTO($state->runId, $receipt->identity), $state);
        $this->commit($state, $result);
        Assert::assertInstanceOf(DeliverSubmissionDTO::class, $result->postCommitEffects[1]);

        return $result->postCommitEffects[1];
    }

    private function commit(RunState $state, HandlerResult $result): void
    {
        $c = self::getContainer();
        $c->get(PreparedTransitionEventStoreInterface::class)->appendTransition($result->events, ['run_id' => $state->runId, 'predecessor_seq' => $state->lastSeq, 'actions' => $result->postCommitActions]);
        $c->get(PendingTransitionRecovery::class)->recover($state->runId);
    }
}
