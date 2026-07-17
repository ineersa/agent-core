<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
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
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('db')]
final class ForkExecutionServiceTest extends PerMethodIsolatedKernelTestCase
{
    public function testForkExecutionEntersDeferredSingleChildLifecycleOnce(): void
    {
        $parentRunId = 'parent-fork-int-1';
        $toolCallId = 'call-fork-int-1';

        $runStore = self::getContainer()->get(RunStoreInterface::class);
        $runStore->compareAndSwap(new RunState(
            runId: $parentRunId,
            status: RunStatus::Running,
            version: 0,
            messages: [new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'seed']])],
        ), 0);

        $compactedLocalIds = [];
        $startCalls = 0;

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->atLeastOnce())->method('compact')->willReturnCallback(
            static function (string $runId) use (&$compactedLocalIds): void {
                $compactedLocalIds[] = $runId;
            },
        );
        $agentRunner->expects($this->once())->method('start')->willReturnCallback(
            static function (StartRunInput $input) use (&$startCalls): string {
                ++$startCalls;

                return $input->runId;
            },
        );

        $container = self::getContainer();
        $container->set(AgentRunnerInterface::class, $agentRunner);

        $forkExecution = $container->get(ForkExecutionService::class);

        $outcome = $this->withToolContext($parentRunId, $toolCallId, static fn () => $forkExecution->execute(
            $parentRunId,
            'Delegated integration task',
        ));

        $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $outcome);
        $this->assertNotEmpty($compactedLocalIds);
        $localRunId = $compactedLocalIds[0];

        // Structural terminal continues reserved batch → child start exactly once.
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
        if (0 === $startCalls) {
            $container->get(ContinueForkAfterCompactionHandler::class)(
                new ContinueForkAfterCompactionMessage($localRunId, success: true),
            );
        }
        $this->assertSame(1, $startCalls);

        $retry = $this->withToolContext($parentRunId, $toolCallId, static fn () => $forkExecution->execute(
            $parentRunId,
            'Delegated integration task',
        ));
        $this->assertSame($outcome->deferredId, $retry->deferredId);
    }

    public function testNestedForkRejectedBeforeReservation(): void
    {
        $childRunId = 'child-fork-nested-1';
        $eventStore = self::getContainer()->get(EventStoreInterface::class);
        $eventStore->append(new \Ineersa\AgentCore\Domain\Event\RunEvent(
            runId: $childRunId,
            seq: 1,
            turnNo: 1,
            type: RunEventTypeEnum::RunStarted->value,
            payload: [
                'payload' => [
                    'metadata' => [
                        'session' => ['kind' => 'agent_child', 'parent_run_id' => 'parent-1'],
                    ],
                ],
            ],
        ));

        $forkExecution = self::getContainer()->get(ForkExecutionService::class);

        try {
            $this->withToolContext($childRunId, 'call-nested', static fn () => $forkExecution->execute($childRunId, 'nested'));
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('Nested fork', $e->getMessage());
        }
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
