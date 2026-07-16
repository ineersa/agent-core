<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork\Deferred;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Prelaunch\ForkDeferredPrelaunchPhaseEnum;
use Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Prelaunch\ForkDeferredPrelaunchPendingException;
use Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Prelaunch\ForkDeferredPrelaunchStagingService;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Session\SessionRunStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Slice B contract: fork deferred launch stages fork-local copy and invokes canonical AgentRunner::compact without mutating parent.
 */
#[CoversNothing]
final class ForkDeferredCanonicalCompactionPrelaunchTest extends IsolatedKernelTestCase
{
    public function testUnderThresholdForkLaunchReturnsDeferredOutcomeAndDispatchesCanonicalCompactOnCopy(): void
    {
        $container = self::getContainer();
        $recordingRunner = new RecordingCompactAgentRunner();
        $container->set(AgentRunnerInterface::class, $recordingRunner);

        $sessionStore = $container->get(HatfieldSessionStore::class);
        $runStore = $container->get(SessionRunStore::class);
        $batchRepository = $container->get(DeferredSubagentBatchRepository::class);

        $parentRunId = $sessionStore->createSession('Parent for fork prelaunch');
        $parentState = new RunState(
            runId: $parentRunId,
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 0,
            messages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Hello']]),
                new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Hi']]),
            ],
        );
        $this->assertTrue($runStore->compareAndSwap($parentState, 0));

        $parentStatePath = $sessionStore->resolveSessionsBasePath().'/'.$parentRunId.'/state.json';
        $parentBytesBefore = file_get_contents($parentStatePath);
        $this->assertNotFalse($parentBytesBefore);

        $toolCallId = 'fork-prelaunch-call-1';
        $lifecycleId = '11111111-1111-4111-8111-111111111111';
        $batchRepository->reserveBatch(
            lifecycleId: $lifecycleId,
            parentRunId: $parentRunId,
            parentTurnNo: 1,
            parentToolCallId: $toolCallId,
            parentOrderIndex: 0,
            executionMode: \Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum::Single,
            totalChildCount: 1,
            deadlineAt: new \DateTimeImmutable('+1 hour'),
            childIntents: [[
                'batchIndex' => 1,
                'childRunId' => '22222222-2222-4222-8222-222222222222',
                'artifactId' => 'agent_testartifact',
                'agentName' => 'fork',
                'task' => 'Explore repo layout',
                'definitionModel' => null,
                'artifactKind' => 'fork',
            ]],
        );

        $staging = $container->get(ForkDeferredPrelaunchStagingService::class);
        try {
            $staging->beginOrResume(
                parentRunId: $parentRunId,
                parentToolCallId: $toolCallId,
                batchLifecycleId: $lifecycleId,
                parentMessages: $parentState->messages,
            );
            $this->fail('Expected durable pre-launch pending exception.');
        } catch (ForkDeferredPrelaunchPendingException) {
        }

        $batch = $batchRepository->findByParentRunAndToolCall($parentRunId, $toolCallId);
        $this->assertNotNull($batch);
        $this->assertNotNull($batch->forkLocalRunId);
        $this->assertNotSame($parentRunId, $batch->forkLocalRunId);
        $this->assertSame(
            ForkDeferredPrelaunchPhaseEnum::CompactionDispatched->value,
            $batch->prelaunchPhase,
        );

        $this->assertCount(1, $recordingRunner->compactInvocations);
        $this->assertSame($batch->forkLocalRunId, $recordingRunner->compactInvocations[0]);
        $this->assertNotSame($parentRunId, $recordingRunner->compactInvocations[0]);

        $this->assertSame($parentBytesBefore, file_get_contents($parentStatePath));

        $copyService = $container->get(\Ineersa\CodingAgent\Session\Fork\ForkSessionCopyService::class);
        $copyService->removeForkLocalSession((string) $batch->forkLocalRunId);
    }
}

final class RecordingCompactAgentRunner implements AgentRunnerInterface
{
    /** @var list<string> */
    public array $compactInvocations = [];

    public function start(\Ineersa\AgentCore\Domain\Run\StartRunInput $input): string
    {
        throw new \LogicException('Not expected in fork prelaunch contract test.');
    }

    public function continue(string $runId): void {}

    public function steer(string $runId, AgentMessage $message): void {}

    public function followUp(string $runId, AgentMessage $message): void {}

    public function appendMessage(string $runId, AgentMessage $message): void {}

    public function cancel(string $runId, ?string $reason = null): void {}

    public function answerHuman(string $runId, string $questionId, mixed $answer): void {}

    public function compact(string $runId, ?string $customInstructions = null): void
    {
        $this->compactInvocations[] = $runId;
    }
}
