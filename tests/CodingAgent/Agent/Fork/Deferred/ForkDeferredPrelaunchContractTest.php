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
use Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Prelaunch\ForkDeferredPrelaunchPendingException;
use Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Prelaunch\ForkDeferredPrelaunchStagingService;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Session\Fork\ForkSessionCopyService;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Session\SessionRunStore;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Uid\Uuid;

#[CoversNothing]
final class ForkDeferredPrelaunchContractTest extends PerMethodIsolatedKernelTestCase
{
    public function testStructuralNoOpPersistsGenericRunMessagesReplacedNotSyntheticContextCompacted(): void
    {
        $container = self::getContainer();
        $container->set(AgentRunnerInterface::class, new ContractNoOpCompactRunner());

        $sessionStore = $container->get(HatfieldSessionStore::class);
        $runStore = $container->get(SessionRunStore::class);
        $eventStore = $container->get(SessionRunEventStore::class);
        $rebuilder = $container->get(RunStateRebuilderInterface::class);
        $batchRepository = $container->get(DeferredSubagentBatchRepository::class);
        $staging = $container->get(ForkDeferredPrelaunchStagingService::class);

        $parentRunId = $sessionStore->createSession('Parent structural no-op contract');
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

        $lifecycleId = (string) Uuid::v4();
        $toolCallId = 'fork-contract-sanitize-' . bin2hex(random_bytes(4));
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
                'childRunId' => (string) Uuid::v4(),
                'artifactId' => 'agent_contract_' . bin2hex(random_bytes(4)),
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

        $events = $eventStore->allFor($forkLocalRunId);
        $this->assertTrue($this->eventLogContainsType($events, 'run_messages_replaced'));
        $this->assertFalse($this->eventLogContainsSyntheticForkSanitizeCompaction($events));

        $replay = $rebuilder->rebuildIfStale(new RunState(
            runId: $forkLocalRunId,
            status: RunStatus::Running,
            version: 0,
            turnNo: 1,
            lastSeq: 0,
            messages: [],
        ), $forkLocalRunId);
        $this->assertNotNull($replay->rebuiltState);
        $this->assertFalse($this->messagesContainForkToolCall($replay->rebuiltState->messages));

        $container->get(ForkSessionCopyService::class)->removeForkLocalSession($forkLocalRunId);
    }

    public function testForkReasoningOverrideSurvivesReserveThroughProjectionForContinueHandler(): void
    {
        $batchRepository = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $parentRunId = self::getContainer()->get(HatfieldSessionStore::class)->createSession('Parent reasoning contract');
        $lifecycleId = (string) Uuid::v4();
        $toolCallId = 'fork-contract-reasoning-' . bin2hex(random_bytes(4));

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
                'childRunId' => (string) Uuid::v4(),
                'artifactId' => 'agent_reason_' . bin2hex(random_bytes(4)),
                'agentName' => 'fork',
                'task' => 'Task with reasoning',
                'definitionModel' => 'openai/gpt-test',
                'artifactKind' => 'fork',
                'reasoningOverride' => 'high',
            ]],
        );

        $projection = $batchRepository->findProjectionByLifecycleId($lifecycleId);
        $this->assertNotNull($projection);
        $child = $projection->children[0] ?? null;
        $this->assertNotNull($child);
        $this->assertSame('high', $child->reasoningOverride);
    }

    /** @param list<\Ineersa\AgentCore\Domain\Event\RunEvent> $events */
    private function eventLogContainsType(array $events, string $type): bool
    {
        foreach ($events as $event) {
            if ($type === $event->type) {
                return true;
            }
        }

        return false;
    }

    /** @param list<\Ineersa\AgentCore\Domain\Event\RunEvent> $events */
    private function eventLogContainsSyntheticForkSanitizeCompaction(array $events): bool
    {
        foreach ($events as $event) {
            if (RunEventTypeEnum::ContextCompacted->value !== $event->type) {
                continue;
            }
            if ('fork_prelaunch_sanitize' === ($event->payload['trigger'] ?? null)) {
                return true;
            }
        }

        return false;
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

final class ContractNoOpCompactRunner implements AgentRunnerInterface
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
