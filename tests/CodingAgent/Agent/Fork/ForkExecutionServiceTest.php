<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\Compaction\CompactionServiceInterface;
use Ineersa\AgentCore\Contract\Compaction\MessageSnapshotCompactionResult;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Fork\ForkExecutionService;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('db')]
final class ForkExecutionServiceTest extends PerMethodIsolatedKernelTestCase
{
    public function testForkExecutionEntersDeferredSingleChildLifecycleOnce(): void
    {
        $parentRunId = 'parent-fork-int-1';
        $toolCallId = 'call-fork-int-1';

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start')->willReturnCallback(
            static fn (StartRunInput $input): string => $input->runId,
        );

        $compaction = $this->createStub(CompactionServiceInterface::class);
        $compaction->method('compactMessages')->willReturnCallback(
            static fn (string $runId, int $turnNo, array $messages): MessageSnapshotCompactionResult => MessageSnapshotCompactionResult::structuralNoOp($messages, 'too_few_messages'),
        );

        $container = self::getContainer();
        $container->set(AgentRunnerInterface::class, $agentRunner);
        $container->set(CompactionServiceInterface::class, $compaction);

        $forkExecution = $container->get(ForkExecutionService::class);

        $outcome = $this->withToolContext($parentRunId, $toolCallId, static fn () => $forkExecution->execute(
            $parentRunId,
            'Delegated integration task',
        ));

        $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $outcome);

        $retry = $this->withToolContext($parentRunId, $toolCallId, static fn () => $forkExecution->execute(
            $parentRunId,
            'Delegated integration task',
        ));
        $this->assertSame($outcome->deferredId, $retry->deferredId);
    }

    public function testNestedForkRejectedBeforeReservation(): void
    {
        $childRunId = 'child-fork-nested-1';
        $eventStore = self::getContainer()->get(\Ineersa\AgentCore\Contract\EventStoreInterface::class);
        $eventStore->append(new \Ineersa\AgentCore\Domain\Event\RunEvent(
            runId: $childRunId,
            seq: 1,
            turnNo: 1,
            type: \Ineersa\AgentCore\Domain\Event\RunEventTypeEnum::RunStarted->value,
            payload: [
                'payload' => [
                    'metadata' => [
                        'session' => ['kind' => 'agent_child', 'parent_run_id' => 'parent-1'],
                    ],
                ],
            ],
        ));

        // Stub compaction so ForkExecutionService can resolve without PlatformInterface providers.
        $compaction = $this->createStub(CompactionServiceInterface::class);
        $compaction->method('compactMessages')->willThrowException(new \LogicException('compactMessages must not run for nested fork'));
        self::getContainer()->set(CompactionServiceInterface::class, $compaction);

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
