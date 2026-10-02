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
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\AgentCore\Domain\Tool\ToolLaunchContextDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchService;
use Ineersa\CodingAgent\Agent\Fork\ForkExecutionService;
use Ineersa\CodingAgent\Agent\Fork\ForkSnapshotSanitizer;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Repository\RunRelationshipReader;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * FINAL CONTROLLING PLAN theses for fork snapshot compaction:
 *
 * 1. Ordering/message handoff: sanitized owner-prepared snapshot is synchronously
 *    compacted before DeferredSubagentBatchLaunchService::launch(), and the compacted
 *    messages—not a parent archive replay—reach fork child preparation;
 *    the immutable launch snapshot remains unchanged.
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
        $parentHashBefore = $this->hashMessages($parentMessages);

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
                ?string $activeModel = null,
            ) use (&$compactCalls, $parentRunId, $compactedMessages, $toolCallId): MessageSnapshotCompactionResult {
                ++$compactCalls;
                $this->assertSame($parentRunId, $runId);
                $this->assertSame(3, $turnNo);
                $this->assertSame('fork', $trigger);
                $this->assertSame('test-model', $activeModel);
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

        $forkExecution = new ForkExecutionService(
            $container->get(DeferredSubagentBatchLaunchService::class),
            $container->get(RunRelationshipReader::class),
            $container->get(ForkSnapshotSanitizer::class),
            $compaction,
        );

        $launchContext = new ToolLaunchContextDTO(
            kind: ToolLaunchContextDTO::KIND_FORK,
            producingRunId: $parentRunId,
            producingTurnNo: 3,
            producingModel: 'test-model',
            agentsContext: 'AGENTS',
            forkMessages: $parentMessages,
        );

        $outcome = $this->withToolContext($parentRunId, $toolCallId, $launchContext, static fn () => $forkExecution->execute(
            $parentRunId,
            'Delegated snapshot task',
            $launchContext,
        ));

        $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $outcome);
        $this->assertSame(1, $compactCalls);
        $this->assertSame($parentHashBefore, $this->hashMessages($launchContext->forkMessages), 'Owner-prepared launch snapshot must be byte-stable');
    }

    public function testHardCompactionFailureDoesNotReserveBatchAndSurfacesImmediately(): void
    {
        $parentRunId = 'parent-fork-snapshot-fail-1';
        $toolCallId = 'call-fork-snapshot-fail-1';

        $this->appendCanonicalParentRun($parentRunId, 'test-model', [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'hello']]),
        ]);

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

        $launchContext = new ToolLaunchContextDTO(
            kind: ToolLaunchContextDTO::KIND_FORK,
            producingRunId: $parentRunId,
            producingTurnNo: 1,
            producingModel: 'test-model',
            forkMessages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'hello']]),
            ],
        );

        try {
            $this->withToolContext($parentRunId, $toolCallId, $launchContext, static fn () => $forkExecution->execute(
                $parentRunId,
                'Should not launch',
                $launchContext,
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

        $this->appendCanonicalParentRun($parentRunId, 'test-model', [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'only one']]),
        ]);

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
                return MessageSnapshotCompactionResult::structuralNoOp($messages);
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

        $launchContext = new ToolLaunchContextDTO(
            kind: ToolLaunchContextDTO::KIND_FORK,
            producingRunId: $parentRunId,
            producingTurnNo: 1,
            producingModel: 'test-model',
            forkMessages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'only one']]),
            ],
        );

        $outcome = $this->withToolContext($parentRunId, $toolCallId, $launchContext, static fn () => $forkExecution->execute(
            $parentRunId,
            'noop task',
            $launchContext,
        ));

        $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $outcome);
    }

    /**
     * @param list<AgentMessage> $messages
     */
    private function appendCanonicalParentRun(string $runId, string $model, array $messages): void
    {
        $eventStore = self::getContainer()->get(\Ineersa\AgentCore\Contract\EventStoreInterface::class);
        $eventStore->append(new \Ineersa\AgentCore\Domain\Event\RunEvent(
            runId: $runId,
            seq: 1,
            turnNo: 0,
            type: \Ineersa\AgentCore\Domain\Event\RunEventTypeEnum::RunStarted->value,
            payload: [
                'payload' => [
                    'metadata' => ['session' => ['kind' => 'parent'], 'model' => $model],
                    'messages' => array_map(static fn (AgentMessage $message): array => $message->toArray(), $messages),
                ],
            ],
        ));
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
    private function withToolContext(
        string $parentRunId,
        string $toolCallId,
        ToolLaunchContextDTO $launchContext,
        callable $callback,
    ): mixed {
        self::getContainer()->get(\Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository::class)->replace(
            new RunState($parentRunId, RunStatus::Running),
        );
        $accessor = self::getContainer()->get(StackToolExecutionContextAccessor::class);
        $context = new ToolContext(
            runId: $parentRunId,
            turnNo: $launchContext->producingTurnNo,
            toolCallId: $toolCallId,
            toolName: 'fork',
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 120,
            orderIndex: 0,
            parentModel: $launchContext->producingModel,
            launchContext: $launchContext,
        );

        return $accessor->with($context, $callback);
    }
}
