<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Execution\ForkExecutionService;
use Ineersa\CodingAgent\Agent\Execution\ForkLocalCompactionTerminalHookSubscriber;
use Ineersa\CodingAgent\Agent\Execution\Messenger\ContinueForkAfterCompactionHandler;
use Ineersa\CodingAgent\Agent\Execution\Messenger\ContinueForkAfterCompactionMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * High-signal proof: fork reserves batch, seeds fork-local completed session, invokes
 * canonical compact(localId), structural terminal continues child start from local messages,
 * parent bytes unchanged, local temp cleaned.
 *
 * Per-method kernel so AgentRunnerInterface can be replaced via Container::set().
 */
#[Group('db')]
final class ForkLocalCompactionBridgeTest extends PerMethodIsolatedKernelTestCase
{
    public function testStructuralCompactionTerminalStartsChildFromLocalMessagesOnceAndCleansTemp(): void
    {
        $parentRunId = 'parent-fork-compact-bridge-1';
        $toolCallId = 'call-fork-compact-bridge-1';
        $marker = 'FORK_LOCAL_SOURCE_MARKER_'.bin2hex(random_bytes(4));

        $parentMessages = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => $marker]]),
            new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'prior assistant']]),
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'please fork']]),
            new AgentMessage(
                role: 'assistant',
                content: [['type' => 'text', 'text' => 'calling fork']],
                metadata: ['tool_calls' => [['name' => 'fork', 'id' => $toolCallId]]],
            ),
        ];

        /** @var RunStoreInterface $runStore */
        $runStore = self::getContainer()->get(RunStoreInterface::class);
        $runStore->compareAndSwap(new RunState(
            runId: $parentRunId,
            status: RunStatus::Running,
            version: 0,
            turnNo: 2,
            lastSeq: 4,
            messages: $parentMessages,
        ), 0);

        $parentBefore = $runStore->get($parentRunId);
        $this->assertNotNull($parentBefore);
        $parentMessagesHash = md5(serialize($parentBefore->messages));
        $parentStatus = $parentBefore->status;
        $parentVersion = $parentBefore->version;

        $eventStore = self::getContainer()->get(EventStoreInterface::class);
        $parentEventCountBefore = \count($eventStore->allFor($parentRunId));

        $compactedLocalIds = [];
        $startedInputs = [];

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())
            ->method('compact')
            ->willReturnCallback(static function (string $runId) use (&$compactedLocalIds, $runStore): void {
                $compactedLocalIds[] = $runId;
                $local = $runStore->get($runId);
                self::assertNotNull($local);
                self::assertSame(RunStatus::Completed, $local->status, 'fork-local seed must be Completed so compact applies immediately');
                self::assertGreaterThanOrEqual(1, \count($local->messages));
            });
        $agentRunner->expects($this->once())
            ->method('start')
            ->willReturnCallback(static function (StartRunInput $input) use (&$startedInputs): string {
                $startedInputs[] = $input;

                return $input->runId;
            });

        $container = self::getContainer();
        $container->set(AgentRunnerInterface::class, $agentRunner);

        /** @var ForkExecutionService $forkExecution */
        $forkExecution = $container->get(ForkExecutionService::class);

        $outcome = $this->withToolContext($parentRunId, $toolCallId, static fn () => $forkExecution->execute(
            $parentRunId,
            'Delegated compact-bridge task',
        ));
        $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $outcome);
        $this->assertCount(1, $compactedLocalIds);
        $localRunId = $compactedLocalIds[0];
        $this->assertNotSame($parentRunId, $localRunId);

        // Parent immutable after reserve+seed+compact dispatch.
        $parentAfterSeed = $runStore->get($parentRunId);
        $this->assertNotNull($parentAfterSeed);
        $this->assertSame($parentMessagesHash, md5(serialize($parentAfterSeed->messages)));
        $this->assertSame($parentStatus, $parentAfterSeed->status);
        $this->assertSame($parentVersion, $parentAfterSeed->version);
        $this->assertSame($parentEventCountBefore, \count($eventStore->allFor($parentRunId)));

        // Simulate structural no-op terminal (same path as hook → handler).
        $hook = $container->get(ForkLocalCompactionTerminalHookSubscriber::class);
        $hook->handleAfterTurnCommit(new AfterTurnCommitHookContext(
            runId: $localRunId,
            turnNo: 0,
            status: RunStatus::Completed->value,
            events: [
                new AfterTurnCommitEventSummary(
                    seq: 3,
                    type: RunEventTypeEnum::ContextCompactionFailed->value,
                    payload: ['reason' => 'too_few_messages'],
                ),
            ],
            effectsCount: 0,
        ));

        // With sync command bus handling, ContinueForkAfterCompactionMessage is handled immediately
        // when dispatched from the hook. If autowiring used a collecting bus, invoke handler explicitly.
        if ([] === $startedInputs) {
            $handler = $container->get(ContinueForkAfterCompactionHandler::class);
            $handler(new ContinueForkAfterCompactionMessage($localRunId, success: true));
        }

        $this->assertCount(1, $startedInputs);
        $started = $startedInputs[0];
        $roles = array_map(static fn (AgentMessage $m): string => $m->role, $started->messages);
        $this->assertSame('system', $roles[0]);
        $serialized = json_encode($started->messages, \JSON_THROW_ON_ERROR);
        $this->assertStringContainsString($marker, $serialized, 'child StartRunInput must inherit fork-local messages');
        $this->assertStringNotContainsString('calling fork', $serialized);

        $batch = $container->get(DeferredSubagentBatchRepository::class)
            ->findByParentRunAndToolCall($parentRunId, $toolCallId);
        $this->assertNotNull($batch);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Launched, $batch->launchStatus);

        // Local temp cleaned (DB row + directory).
        $sessionStore = $container->get(HatfieldSessionStore::class);
        $this->assertFalse($sessionStore->exists($localRunId));
        $localDir = $sessionStore->resolveSessionsBasePath().'/'.$localRunId;
        $this->assertDirectoryDoesNotExist($localDir);

        // Parent still unchanged after continuation.
        $parentFinal = $runStore->get($parentRunId);
        $this->assertNotNull($parentFinal);
        $this->assertSame($parentMessagesHash, md5(serialize($parentFinal->messages)));

        // Duplicate continue is idempotent (no second start).
        $handler = $container->get(ContinueForkAfterCompactionHandler::class);
        $handler(new ContinueForkAfterCompactionMessage($localRunId, success: true));
        $this->assertCount(1, $startedInputs);
    }

    public function testHardCompactionFailureMarksBatchFailedAndDispatchesErrorWhenRegistered(): void
    {
        $parentRunId = 'parent-fork-hard-fail-1';
        $toolCallId = 'call-fork-hard-fail-1';

        $runStore = self::getContainer()->get(RunStoreInterface::class);
        $runStore->compareAndSwap(new RunState(
            runId: $parentRunId,
            status: RunStatus::Running,
            version: 0,
            messages: [new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'x']])],
        ), 0);

        $localRunId = null;
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->method('compact')->willReturnCallback(static function (string $runId) use (&$localRunId): void {
            $localRunId = $runId;
        });
        $agentRunner->expects($this->never())->method('start');
        self::getContainer()->set(AgentRunnerInterface::class, $agentRunner);

        $forkExecution = self::getContainer()->get(ForkExecutionService::class);
        $outcome = $this->withToolContext($parentRunId, $toolCallId, static fn () => $forkExecution->execute(
            $parentRunId,
            'hard fail task',
        ));
        $this->assertNotNull($localRunId);

        // Register deferred completion so completion dispatcher can emit.
        $deferredRepo = self::getContainer()->get(\Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface::class);
        $deferredRepo->registerPending(new \Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation(
            deferredId: $outcome->deferredId,
            runId: $parentRunId,
            turnNo: 2,
            stepId: 'turn-2-tools-1',
            attempt: 1,
            idempotencyKey: 'idem-fork-hard-fail',
            toolCallId: $toolCallId,
            toolName: 'fork',
            arguments: [],
            orderIndex: 0,
        ));

        $handler = self::getContainer()->get(ContinueForkAfterCompactionHandler::class);
        $handler(new ContinueForkAfterCompactionMessage(
            forkLocalRunId: $localRunId,
            success: false,
            failureReason: 'model_error',
        ));

        $batch = self::getContainer()->get(DeferredSubagentBatchRepository::class)
            ->findByParentRunAndToolCall($parentRunId, $toolCallId);
        $this->assertNotNull($batch);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Failed, $batch->launchStatus);
        $this->assertNotNull($batch->terminalCompletionEnqueuedAt);

        $sessionStore = self::getContainer()->get(HatfieldSessionStore::class);
        $this->assertFalse($sessionStore->exists($localRunId));
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withToolContext(string $parentRunId, string $toolCallId, callable $callback): mixed
    {
        $accessor = self::getContainer()->get(StackToolExecutionContextAccessor::class);
        $context = new ToolContext(
            runId: $parentRunId,
            turnNo: 2,
            toolCallId: $toolCallId,
            toolName: 'fork',
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 120,
            orderIndex: 0,
        );

        return $accessor->with($context, $callback);
    }
}
