<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\Compaction\CompactionServiceInterface;
use Ineersa\AgentCore\Contract\Compaction\MessageSnapshotCompactionResult;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Fork\ForkExecutionService;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * FINAL CONTROLLING PLAN theses for fork snapshot compaction:
 *
 * 1. Ordering/message handoff: sanitized snapshot is synchronously compacted
 *    before DeferredSubagentBatchLaunchService::launch(), and the compacted
 *    messages—not a second parent-store re-read—reach fork child preparation;
 *    parent RunStore remains unchanged.
 * 2. Failure/no-op: structural no-op still launches; hard compaction failure
 *    launches/reserves nothing and surfaces immediately as ToolCallException.
 * 3. Generic compaction reuse: ForkExecutionService calls the existing
 *    CompactionServiceInterface::compactMessages (no fork-specific compactor).
 */
#[Group('db')]
final class ForkSnapshotCompactionBeforeLaunchTest extends PerMethodIsolatedKernelTestCase
{
    public function testSanitizedSnapshotIsCompactedBeforeLaunchParentUnchangedAndHandoffUsesCompactedMessages(): void
    {
        $parentRunId = 'parent-fork-snapshot-order-1';
        $toolCallId = 'call-fork-snapshot-order-1';
        $marker = 'FORK_SNAPSHOT_COMPACTED_SUMMARY_MARKER';

        $parentMessages = [
            new AgentMessage(role: 'user-context', content: [['type' => 'text', 'text' => 'AGENTS']], metadata: ['source' => 'agents_context']),
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'old context that must not leak after compact']]),
            new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'prior assistant']]),
            new AgentMessage(
                role: 'assistant',
                content: [],
                metadata: ['tool_calls' => [['name' => 'fork', 'id' => $toolCallId, 'arguments' => '{"task":"x"}']]],
            ),
        ];

        $runStore = self::getContainer()->get(RunStoreInterface::class);
        $runStore->compareAndSwap(new RunState(
            runId: $parentRunId,
            status: RunStatus::Running,
            version: 0,
            messages: $parentMessages,
            turnNo: 3,
        ), 0);

        $parentBefore = $runStore->get($parentRunId);
        $this->assertNotNull($parentBefore);
        $parentHashBefore = $this->hashMessages($parentBefore->messages);

        $compactCalls = 0;
        $compactedMessages = [
            new AgentMessage(
                role: 'user',
                content: [['type' => 'text', 'text' => $marker]],
                metadata: ['compact_summary' => true],
            ),
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'retained tail']]),
        ];

        $compaction = $this->createMock(CompactionServiceInterface::class);
        $compaction->expects($this->once())
            ->method('compactMessages')
            ->willReturnCallback(function (
                string $runId,
                int $turnNo,
                array $messages,
                string $trigger = 'manual',
                ?string $customInstructions = null,
            ) use (&$compactCalls, $parentRunId, $compactedMessages, $toolCallId): MessageSnapshotCompactionResult {
                ++$compactCalls;
                $this->assertSame($parentRunId, $runId);
                $this->assertSame(3, $turnNo);
                $this->assertSame('fork', $trigger);
                foreach ($messages as $message) {
                    $calls = $message->metadata['tool_calls'] ?? null;
                    if (!\is_array($calls)) {
                        continue;
                    }
                    foreach ($calls as $call) {
                        $this->assertNotSame($toolCallId, $call['id'] ?? null, 'In-flight fork tool call must be sanitized before compact');
                    }
                }

                return MessageSnapshotCompactionResult::compacted($compactedMessages);
            });
        // Other interface methods may be used by unrelated services; leave defaults.

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start')->willReturnCallback(
            static function (StartRunInput $input) use ($marker, &$compactCalls): string {
                if (0 === $compactCalls) {
                    throw new \RuntimeException('launch/start reached before compactMessages');
                }
                $found = false;
                foreach ($input->messages as $message) {
                    foreach ($message->content as $block) {
                        if (('text' === ($block['type'] ?? '')) && str_contains((string) ($block['text'] ?? ''), $marker)) {
                            $found = true;
                        }
                        if (('text' === ($block['type'] ?? '')) && str_contains((string) ($block['text'] ?? ''), 'old context that must not leak')) {
                            throw new \RuntimeException('Parent pre-compaction text leaked into child StartRunInput');
                        }
                    }
                }
                if (!$found) {
                    throw new \RuntimeException('Compacted summary marker missing from child StartRunInput messages');
                }

                return $input->runId;
            },
        );

        $container = self::getContainer();
        $container->set(CompactionServiceInterface::class, $compaction);
        $container->set(AgentRunnerInterface::class, $agentRunner);

        /** @var ForkExecutionService $forkExecution */
        $forkExecution = $container->get(ForkExecutionService::class);

        $outcome = $this->withToolContext($parentRunId, $toolCallId, static fn () => $forkExecution->execute(
            $parentRunId,
            'Delegated snapshot task',
        ));

        $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $outcome);
        $this->assertSame(1, $compactCalls);

        $parentAfter = $runStore->get($parentRunId);
        $this->assertNotNull($parentAfter);
        $this->assertSame($parentHashBefore, $this->hashMessages($parentAfter->messages), 'Parent RunStore messages must be byte-stable');
        $this->assertSame(RunStatus::Running, $parentAfter->status);
        $this->assertSame(3, $parentAfter->turnNo);
    }

    public function testHardCompactionFailureDoesNotReserveBatchAndSurfacesImmediately(): void
    {
        $parentRunId = 'parent-fork-snapshot-fail-1';
        $toolCallId = 'call-fork-snapshot-fail-1';

        $runStore = self::getContainer()->get(RunStoreInterface::class);
        $runStore->compareAndSwap(new RunState(
            runId: $parentRunId,
            status: RunStatus::Running,
            version: 0,
            messages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'hello']]),
            ],
            turnNo: 1,
        ), 0);

        $compaction = $this->createMock(CompactionServiceInterface::class);
        $compaction->expects($this->once())
            ->method('compactMessages')
            ->willReturn(MessageSnapshotCompactionResult::failed(
                'model_error',
                'Summarization model call failed.',
            ));

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->never())->method('start');

        $container = self::getContainer();
        $container->set(CompactionServiceInterface::class, $compaction);
        $container->set(AgentRunnerInterface::class, $agentRunner);

        /** @var ForkExecutionService $forkExecution */
        $forkExecution = $container->get(ForkExecutionService::class);

        try {
            $this->withToolContext($parentRunId, $toolCallId, static fn () => $forkExecution->execute(
                $parentRunId,
                'Should not launch',
            ));
            $this->fail('Expected ToolCallException on hard compaction failure');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('Fork compaction failed', $e->getMessage());
            $this->assertStringContainsString('Summarization model call failed.', $e->getMessage());
        }

        $batchRepository = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $this->assertNull(
            $batchRepository->findByParentRunAndToolCall($parentRunId, $toolCallId),
            'Hard compaction failure must reserve zero deferred batches',
        );
    }

    public function testStructuralNoOpStillLaunchesViaOrdinaryDeferredPath(): void
    {
        $parentRunId = 'parent-fork-snapshot-noop-1';
        $toolCallId = 'call-fork-snapshot-noop-1';

        $runStore = self::getContainer()->get(RunStoreInterface::class);
        $runStore->compareAndSwap(new RunState(
            runId: $parentRunId,
            status: RunStatus::Running,
            version: 0,
            messages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'only one']]),
            ],
            turnNo: 1,
        ), 0);

        $compaction = $this->createMock(CompactionServiceInterface::class);
        $compaction->expects($this->once())
            ->method('compactMessages')
            ->willReturnCallback(static function (
                string $runId,
                int $turnNo,
                array $messages,
                string $trigger = 'manual',
                ?string $customInstructions = null,
            ): MessageSnapshotCompactionResult {
                return MessageSnapshotCompactionResult::structuralNoOp($messages, 'too_few_messages');
            });

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start')->willReturnCallback(
            static fn (StartRunInput $input): string => $input->runId,
        );

        $container = self::getContainer();
        $container->set(CompactionServiceInterface::class, $compaction);
        $container->set(AgentRunnerInterface::class, $agentRunner);

        /** @var ForkExecutionService $forkExecution */
        $forkExecution = $container->get(ForkExecutionService::class);

        $outcome = $this->withToolContext($parentRunId, $toolCallId, static fn () => $forkExecution->execute(
            $parentRunId,
            'noop task',
        ));

        $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $outcome);
    }

    /**
     * @param list<AgentMessage> $messages
     */
    private function hashMessages(array $messages): string
    {
        return hash('sha256', serialize(array_map(
            static fn (AgentMessage $m): array => $m->toArray(),
            $messages,
        )));
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
