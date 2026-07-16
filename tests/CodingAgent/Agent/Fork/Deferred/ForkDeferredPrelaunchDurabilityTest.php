<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork\Deferred;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Prelaunch\ContinueForkDeferredPrelaunchMessage;
use Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Prelaunch\ForkDeferredPrelaunchPhaseEnum;
use Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Prelaunch\ForkDeferredPrelaunchPendingException;
use Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Prelaunch\ForkDeferredPrelaunchStagingService;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Session\Fork\ForkSessionCopyService;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunStore;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversNothing]
final class ForkDeferredPrelaunchDurabilityTest extends PerMethodIsolatedKernelTestCase
{
    public function testStructuralCompactionNoOpAfterReplayStillSanitizesForkLaunch(): void
    {
        $container = self::getContainer();
        $container->set(AgentRunnerInterface::class, new DurabilityNoOpCompactRunner());

        $sessionStore = $container->get(HatfieldSessionStore::class);
        $runStore = $container->get(SessionRunStore::class);
        $rebuilder = $container->get(RunStateRebuilderInterface::class);
        $batchRepository = $container->get(DeferredSubagentBatchRepository::class);
        $staging = $container->get(ForkDeferredPrelaunchStagingService::class);

        $parentRunId = $sessionStore->createSession('Parent with in-flight fork launch');
        $parentMessages = $this->parentMessagesWithInFlightForkLaunch();
        $this->assertTrue($runStore->compareAndSwap(new RunState(
            runId: $parentRunId,
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 3,
            pendingToolCalls: ['call_fork_live' => true],
            messages: $parentMessages,
        ), 0));

        $ids = $this->uniqueForkDurabilityIds('call-1');
        $toolCallId = $ids['toolCallId'];
        $lifecycleId = $ids['lifecycleId'];
        $batchRepository->reserveBatch(
            lifecycleId: $lifecycleId,
            parentRunId: $parentRunId,
            parentTurnNo: 1,
            parentToolCallId: $toolCallId,
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Single,
            totalChildCount: 1,
            deadlineAt: new \DateTimeImmutable('+1 hour'),
            childIntents: [[
                'batchIndex' => 1,
                'childRunId' => $ids['childRunId'],
                'artifactId' => $ids['artifactId'],
                'agentName' => 'fork',
                'task' => 'Explore layout',
                'definitionModel' => null,
                'artifactKind' => 'fork',
            ]],
        );

        try {
            $staging->beginOrResume($parentRunId, $toolCallId, $lifecycleId, $parentMessages);
            $this->fail('Expected pending.');
        } catch (ForkDeferredPrelaunchPendingException) {
        }

        $forkLocalRunId = (string) $batchRepository->findByParentRunAndToolCall($parentRunId, $toolCallId)->forkLocalRunId;
        $staging->handleForkLocalCompactionTerminal(
            $forkLocalRunId,
            RunEventTypeEnum::ContextCompactionFailed->value,
            ['reason' => 'below_keep_recent_tokens', 'messages_replaced' => false],
        );

        $forkState = $runStore->get($forkLocalRunId);
        $this->assertNotNull($forkState);
        $this->assertFalse($this->messagesContainForkToolCall($forkState->messages));
        $this->assertSame([], $forkState->pendingToolCalls);

        $replay = $rebuilder->rebuildIfStale(new RunState(
            runId: $forkLocalRunId,
            status: $forkState->status,
            version: $forkState->version,
            turnNo: $forkState->turnNo,
            lastSeq: 0,
            messages: [],
        ), $forkLocalRunId);
        $this->assertNotNull($replay->rebuiltState);
        $this->assertFalse($this->messagesContainForkToolCall($replay->rebuiltState->messages));
        $this->assertSame([], $replay->rebuiltState->pendingToolCalls);

        $container->get(ForkSessionCopyService::class)->removeForkLocalSession($forkLocalRunId);
    }

    public function testContinueDispatchFailureAllowsRetryWithoutAdvancingToReady(): void
    {
        $container = self::getContainer();
        $container->set(AgentRunnerInterface::class, new DurabilityNoOpCompactRunner());

        $sessionStore = $container->get(HatfieldSessionStore::class);
        $runStore = $container->get(SessionRunStore::class);
        $batchRepository = $container->get(DeferredSubagentBatchRepository::class);
        $staging = new ForkDeferredPrelaunchStagingService(
            $sessionStore,
            $container->get(ForkSessionCopyService::class),
            $container->get(\Ineersa\CodingAgent\Agent\Fork\ForkSnapshotSanitizer::class),
            $container->get(SessionRunStore::class),
            $container->get(AgentRunnerInterface::class),
            $batchRepository,
            new DurabilityFailingOnceContinueBus(),
            $container->get(\Ineersa\AgentCore\Domain\Event\EventFactory::class),
            $container->get(\Ineersa\CodingAgent\Session\CommittedRunEventAppender::class),
            $container->get(\Psr\Log\LoggerInterface::class),
        );

        $parentRunId = $sessionStore->createSession('Parent retry dispatch');
        $parentMessages = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Hello']]),
            new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Hi']]),
        ];
        $this->assertTrue($runStore->compareAndSwap(new RunState(
            runId: $parentRunId,
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 0,
            messages: $parentMessages,
        ), 0));

        $ids = $this->uniqueForkDurabilityIds('call-2');
        $toolCallId = $ids['toolCallId'];
        $lifecycleId = $ids['lifecycleId'];
        $batchRepository->reserveBatch(
            lifecycleId: $lifecycleId,
            parentRunId: $parentRunId,
            parentTurnNo: 1,
            parentToolCallId: $toolCallId,
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Single,
            totalChildCount: 1,
            deadlineAt: new \DateTimeImmutable('+1 hour'),
            childIntents: [[
                'batchIndex' => 1,
                'childRunId' => $ids['childRunId'],
                'artifactId' => $ids['artifactId'],
                'agentName' => 'fork',
                'task' => 'Task',
                'definitionModel' => 'openai/gpt-test',
                'artifactKind' => 'fork',
            ]],
        );

        try {
            $staging->beginOrResume($parentRunId, $toolCallId, $lifecycleId, $parentMessages);
            $this->fail('Expected pending.');
        } catch (ForkDeferredPrelaunchPendingException) {
        }

        $forkLocalRunId = (string) $batchRepository->findByParentRunAndToolCall($parentRunId, $toolCallId)->forkLocalRunId;
        $payload = ['reason' => 'below_keep_recent_tokens', 'messages_replaced' => false];

        try {
            $staging->handleForkLocalCompactionTerminal($forkLocalRunId, RunEventTypeEnum::ContextCompactionFailed->value, $payload);
            $this->fail('Expected continue dispatch failure to propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Simulated continue dispatch failure', $exception->getMessage());
        }

        $this->assertSame(
            ForkDeferredPrelaunchPhaseEnum::CompactionDispatched->value,
            $batchRepository->findByParentRunAndToolCall($parentRunId, $toolCallId)?->prelaunchPhase,
        );

        $staging->handleForkLocalCompactionTerminal($forkLocalRunId, RunEventTypeEnum::ContextCompactionFailed->value, $payload);
        $this->assertSame(
            ForkDeferredPrelaunchPhaseEnum::ReadyForChildLaunch->value,
            $batchRepository->findByParentRunAndToolCall($parentRunId, $toolCallId)?->prelaunchPhase,
        );

        $container->get(ForkSessionCopyService::class)->removeForkLocalSession($forkLocalRunId);
    }

    /**
     * @return array{toolCallId: string, lifecycleId: string, childRunId: string, artifactId: string}
     */
    private function uniqueForkDurabilityIds(string $suffix): array
    {
        return [
            'toolCallId' => 'fork-durability-' . $suffix . '-' . bin2hex(random_bytes(4)),
            'lifecycleId' => (string) \Symfony\Component\Uid\Uuid::v4(),
            'childRunId' => (string) \Symfony\Component\Uid\Uuid::v4(),
            'artifactId' => 'agent_forkdur_' . bin2hex(random_bytes(4)),
        ];
    }

    /** @return list<AgentMessage> */
    private function parentMessagesWithInFlightForkLaunch(): array
    {
        return [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Prior work']]),
            new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Done']]),
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Launch fork now']]),
            new AgentMessage(
                role: 'assistant',
                content: [['type' => 'text', 'text' => 'Calling fork']],
                metadata: ['tool_calls' => [['id' => 'call_fork_live', 'name' => 'fork', 'arguments' => ['task' => 'x']]]],
            ),
        ];
    }

    /** @param list<AgentMessage> $messages */
    private function messagesContainForkToolCall(array $messages): bool
    {
        foreach ($messages as $message) {
            if ('assistant' !== $message->role) {
                continue;
            }
            $toolCalls = $message->metadata['tool_calls'] ?? null;
            if (!\is_array($toolCalls)) {
                continue;
            }
            foreach ($toolCalls as $toolCall) {
                if (\is_array($toolCall) && 'fork' === ($toolCall['name'] ?? null)) {
                    return true;
                }
            }
        }

        return false;
    }
}

final class DurabilityNoOpCompactRunner implements AgentRunnerInterface
{
    public function start(\Ineersa\AgentCore\Domain\Run\StartRunInput $input): string
    {
        throw new \LogicException('Not expected.');
    }

    public function continue(string $runId): void {}

    public function steer(string $runId, AgentMessage $message): void {}

    public function followUp(string $runId, AgentMessage $message): void {}

    public function appendMessage(string $runId, AgentMessage $message): void {}

    public function cancel(string $runId, ?string $reason = null): void {}

    public function answerHuman(string $runId, string $questionId, mixed $answer): void {}

    public function compact(string $runId, ?string $customInstructions = null): void {}
}

final class DurabilityFailingOnceContinueBus implements MessageBusInterface
{
    private bool $failed = false;

    public function dispatch(object $message, array $stamps = []): \Symfony\Component\Messenger\Envelope
    {
        if ($message instanceof ContinueForkDeferredPrelaunchMessage && !$this->failed) {
            $this->failed = true;
            throw new \RuntimeException('Simulated continue dispatch failure.');
        }

        return new \Symfony\Component\Messenger\Envelope($message, $stamps);
    }
}
