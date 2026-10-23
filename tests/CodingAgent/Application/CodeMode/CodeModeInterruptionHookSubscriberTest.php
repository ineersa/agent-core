<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\CodeMode;

use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Message\ToolPayloadMessage;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\DTO\ProcessedToolResultDTO;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\RunCancellationToken;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeAcknowledgementHandler;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeExecutionService;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeInterruptionHookSubscriber;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeProcessedResultSubscriber;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeResultArtifactStore;
use Ineersa\CodingAgent\Application\CodeMode\DTO\CodeModeOwnerDTO;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Runtime\Controller\CodeModeControllerSessionLifecycleListener;
use Ineersa\CodingAgent\Session\Event\ControllerSessionShutdownEvent;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\CodeModeBackendFixture;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class CodeModeInterruptionHookSubscriberTest extends IsolatedKernelTestCase
{
    public function testStartupFailureBeforeReadinessSettlesTheOriginalCaller(): void
    {
        [$f, $id] = $this->begin();
        $result = new ToolCallResult($f->call->runId(), 1, 'step', 1, $f->call->idempotencyKey(), 'cm', 0, ['tool_name' => 'code_mode', 'content' => [['type' => 'text', 'text' => 'Host failed before readiness.']]], true);
        $c = self::getContainer();
        $c->get(CodeModeExecutionService::class)->preserve($id, null, new \RuntimeException('Host failed before readiness.'));
        $publication = new ProcessedToolResultDTO($result, $f->call);
        $c->get(CodeModeProcessedResultSubscriber::class)($publication);
        $this->assertTrue($publication->handled);
        $transport = $c->get('messenger.transport.run_control');
        $bus = $c->get('agent.command.bus');
        $seen = 0;
        for ($i = 0; $i < 8; ++$i) {
            $sent = $transport->getSent();
            if ($seen === \count($sent)) {
                break;
            }
            for (; $seen < \count($sent); ++$seen) {
                $envelope = $sent[$seen];
                if ($envelope->getMessage() instanceof ToolPayloadMessage || $envelope->getMessage() instanceof ToolCallResult) {
                    $bus->dispatch($envelope->with(new ReceivedStamp('run_control')));
                }
            }
        }
        $events = $c->get(PreparedTransitionEventStoreInterface::class)->allFor($f->state->runId);
        $terminal = array_values(array_filter($events, static fn (RunEvent $event): bool => 'tool_execution_end' === $event->type));
        $this->assertCount(1, $terminal);
        $this->assertTrue($terminal[0]->payload['tool_result']['is_error']);
        $this->assertSame('completed', $c->get(DeferredToolCompletionRepositoryInterface::class)->status($id));
        $this->assertFalse((bool) $c->get(CodeModeExecutionRepository::class)->invocation($f->state->runId, 'cm')['ready']);
    }

    public function testCapturedCancellationSurvivesParentRevivalWithoutPollingTheCancellingState(): void
    {
        [$f, $id] = $this->begin();
        $c = self::getContainer();
        $service = $c->get(CodeModeExecutionService::class);
        $token = $service->cancellationToken($id, new RunCancellationToken($c->get(RunOperationalStatusReaderInterface::class), $f->state->runId));
        $this->assertFalse($token->isCancellationRequested());
        $cancelled = $f->state->with(['status' => RunStatus::Cancelling]);
        $hook = $c->get(CodeModeInterruptionHookSubscriber::class);
        $actions = $hook->prepareAfterTurnCommit(AfterTurnCommitHookContext::fromRunState($cancelled, [], 0), $f->state->lastSeq);
        $this->assertSame([$id], $actions[0]->message->identities);
        $f->commit($f->state, new HandlerResult($cancelled, postCommitActions: $actions));
        $c->get(RunOperationalProjectionRepository::class)->replace($f->state->with(['turnNo' => 2]));
        $this->assertTrue($token->isCancellationRequested());
        $this->assertSame('cancelled', $c->get(CodeModeExecutionRepository::class)->invocation($f->state->runId, 'cm')['interruption_reason']);
        $this->assertSame([], $c->get(CodeModeAcknowledgementHandler::class)->handle(new CodeModeOwnerDTO($f->state->runId, $id, 'cm', true), $f->state)->events);
    }

    public function testNavigationPreservesHostsAndDiscardCapturesOnlyAbandonedTurns(): void
    {
        [$f, $id] = $this->begin();
        $hook = self::getContainer()->get(CodeModeInterruptionHookSubscriber::class);
        $navigation = new RunEvent($f->state->runId, 4, 0, 'history_position_set', ['position_turn_no' => 0], new \DateTimeImmutable());
        $this->assertSame([], $hook->prepareAfterTurnCommit(AfterTurnCommitHookContext::fromRunState($f->state, [$navigation], 0), 3));
        $retained = new RunEvent($f->state->runId, 4, 1, 'history_tail_discarded', ['after_turn_no' => 1], new \DateTimeImmutable());
        $this->assertSame([], $hook->prepareAfterTurnCommit(AfterTurnCommitHookContext::fromRunState($f->state, [$retained], 0), 3));
        $selected = $f->state->with(['turnNo' => 0, 'lastSeq' => 4]);
        $f->commit($f->state, new HandlerResult($selected, [$navigation]));
        $this->assertTrue(self::getContainer()->get(CodeModeExecutionRepository::class)->isActive($id));
        $discard = new RunEvent($f->state->runId, 5, 0, 'history_tail_discarded', ['after_turn_no' => 0], new \DateTimeImmutable());
        $actions = $hook->prepareAfterTurnCommit(AfterTurnCommitHookContext::fromRunState($selected, [$discard], 0), 4);
        $this->assertSame([$id], $actions[0]->message->identities);
        $f->commit($selected, new HandlerResult(events: [$discard], postCommitActions: $actions));
        $this->assertSame('history_discarded', self::getContainer()->get(CodeModeExecutionRepository::class)->invocation($f->state->runId, 'cm')['interruption_reason']);
    }

    public function testRestartFencesReadyAcknowledgementAndLateTerminalButRetainsRawEvidence(): void
    {
        [$f, $id] = $this->begin();
        $c = self::getContainer();
        $service = $c->get(CodeModeExecutionService::class);
        $service->ready($id);
        $ack = $c->get(CodeModeAcknowledgementHandler::class)->handle(new CodeModeOwnerDTO($f->state->runId, $id, 'cm', true), $f->state);
        $f->commit($f->state, $ack);
        $service->preserve($id, 42, null);
        $c->get(CodeModeControllerSessionLifecycleListener::class)->starting(new ControllerSessionStartingEvent($f->state->runId));
        $row = $c->get(CodeModeExecutionRepository::class)->invocation($f->state->runId, 'cm');
        $this->assertSame('controller_restarted', $row['interruption_reason']);
        $this->assertSame([], $c->get(CodeModeAcknowledgementHandler::class)->handle(new CodeModeOwnerDTO($f->state->runId, $id, 'cm', true), $f->state)->events);
        $event = new ProcessedToolResultDTO(new ToolCallResult($f->call->runId(), 1, 'step', 1, $f->call->idempotencyKey(), 'cm', 0, ['tool_name' => 'code_mode', 'content' => []]), $f->call);
        $c->get(CodeModeProcessedResultSubscriber::class)($event);
        $this->assertTrue($event->handled);
        $this->assertSame('completed', $c->get(DeferredToolCompletionRepositoryInterface::class)->status($id));
        $this->assertNull($c->get(ToolBatchStoreInterface::class)->loadSchedule($f->state->runId, 1, 'step'));
        $this->assertFileExists($c->get(CodeModeResultArtifactStore::class)->path($service->resultReference($row['raw_reference'])));
        $this->expectException(\RuntimeException::class);
        $service->ready($id);
    }

    public function testDeletionRollsBackNativeMetadataAndPreservesArtifacts(): void
    {
        [$f, $id] = $this->begin();
        $c = self::getContainer();
        $service = $c->get(CodeModeExecutionService::class);
        $service->preserve($id, 42, null);
        $row = $c->get(CodeModeExecutionRepository::class)->invocation($f->state->runId, 'cm');
        $path = $c->get(CodeModeResultArtifactStore::class)->path($service->resultReference($row['raw_reference']));
        $dispatcher = $c->get(EventDispatcherInterface::class);
        $fail = static function (ControllerSessionShutdownEvent $event): void {
            if ($event->permanentDeletion) {
                throw new \RuntimeException('Rollback deletion.');
            }
        };
        $dispatcher->addListener(ControllerSessionShutdownEvent::class, $fail, -2048);
        try {
            $c->get(HatfieldSessionStore::class)->deleteSession($f->state->runId);
            $this->fail('Deletion must fail.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Rollback deletion.', $error->getMessage());
        } finally {
            $dispatcher->removeListener(ControllerSessionShutdownEvent::class, $fail);
        }
        $this->assertNotNull($c->get(CodeModeExecutionRepository::class)->invocation($f->state->runId, 'cm'));
        $this->assertFileExists($path);
    }

    public function testPermanentDeletionRemovesNativeMetadataAndRetainedArtifacts(): void
    {
        [$f, $id] = $this->begin();
        $c = self::getContainer();
        $service = $c->get(CodeModeExecutionService::class);
        $service->preserve($id, 42, null);
        $row = $c->get(CodeModeExecutionRepository::class)->invocation($f->state->runId, 'cm');
        $path = $c->get(CodeModeResultArtifactStore::class)->path($service->resultReference($row['raw_reference']));
        $c->get(HatfieldSessionStore::class)->deleteSession($f->state->runId);
        $this->assertNull($c->get(CodeModeExecutionRepository::class)->invocation($f->state->runId, 'cm'));
        $this->assertNull($c->get(DeferredToolCompletionRepositoryInterface::class)->findMetadataByDeferredId($id));
        $service->preserve($id, 'late value after deletion', null);
        $this->assertFileDoesNotExist($path);
        $this->assertDirectoryDoesNotExist(\dirname($path));
        $this->assertTrue($service->cancellationToken($id, new NullCancellationToken())->isCancellationRequested());
    }

    /** @return array{CodeModeBackendFixture, string} */
    private function begin(): array
    {
        $c = self::getContainer();
        $f = new CodeModeBackendFixture($c, 'return 42;');
        $id = $c->get(StackToolExecutionContextAccessor::class)->with(new ToolContext($f->state->runId, 1, 'cm', 'code_mode', new NullCancellationToken(), 8, 0, null, 'step'), static fn () => $c->get(CodeModeExecutionService::class)->begin());

        return [$f, $id];
    }
}
