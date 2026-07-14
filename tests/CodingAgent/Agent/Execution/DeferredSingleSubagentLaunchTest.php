<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\McpAgentModeEnum;
use Ineersa\CodingAgent\Agent\Definition\McpPolicyDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\SubagentExecutionService;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Tests\Agent\Execution\Support\SubagentExecutionServiceFactory;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Piece 3A: single execute returns deferred marker, durable idempotent launch, no child RunStore polling.
 */
#[CoversClass(SubagentExecutionService::class)]
#[Group('db')]
final class DeferredSingleSubagentLaunchTest extends IsolatedKernelTestCase
{
    public function testExecuteReturnsDeferredMarkerAndLaunchesOneDeterministicChildWithoutChildRunStorePolling(): void
    {
        $parentRunId = 'parent-deferred-1';
        $toolCallId = 'call-deferred-1';
        $identityFactory = new DeferredSubagentBatchIdentityFactory();
        $ids = $identityFactory->childIdentity($parentRunId, $toolCallId, 1);

        $childRunStoreGetCount = 0;
        $runStore = $this->createMock(RunStoreInterface::class);
        $runStore->expects($this->never())->method('get')->willReturnCallback(static function () use (&$childRunStoreGetCount) {
            ++$childRunStoreGetCount;

            return null;
        });

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start');

        $def = $this->foregroundDefinition('worker-deferred');
        $service = $this->buildService($agentRunner, $runStore, $def);

        $outcome = $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->execute($parentRunId, 'worker-deferred', 'Do task'));

        $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $outcome);
        $this->assertSame(0, $childRunStoreGetCount);

        /** @var DeferredSubagentBatchRepository $batchRepo */
        $batchRepo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $batch = $batchRepo->findByParentRunAndToolCall($parentRunId, $toolCallId);
        $this->assertNotNull($batch);
        $this->assertSame($ids['childRunId'], $batch->children[0]->childRunId);
        $this->assertSame($ids['artifactId'], $batch->children[0]->artifactId);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Launched, $batch->launchStatus);

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $entry = $registry->get($parentRunId, $ids['artifactId']);
        $this->assertNotNull($entry);
        $this->assertSame(AgentArtifactStatusEnum::Running, $entry->status);
    }

    public function testReinvokeReturnsMarkerWithoutSecondLaunch(): void
    {
        $parentRunId = 'parent-deferred-2';
        $toolCallId = 'call-deferred-2';
        $identityFactory = new DeferredSubagentBatchIdentityFactory();
        $ids = $identityFactory->childIdentity($parentRunId, $toolCallId, 1);

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start');

        $runStore = $this->createStub(RunStoreInterface::class);
        $def = $this->foregroundDefinition('worker-retry');
        $service = $this->buildService($agentRunner, $runStore, $def);

        $first = $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->execute($parentRunId, 'worker-retry', 'Same task'));
        $second = $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->execute($parentRunId, 'worker-retry', 'Same task'));

        $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $first);
        $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $second);

        /** @var DeferredSubagentBatchRepository $batchRepo */
        $batchRepo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $batch = $batchRepo->findByParentRunAndToolCall($parentRunId, $toolCallId);
        $this->assertNotNull($batch);
        $this->assertSame($ids['childRunId'], $batch->children[0]->childRunId);
        $this->assertSame($ids['artifactId'], $batch->children[0]->artifactId);
    }

    public function testReservedCrashWindowRetryDispatchesStartWithoutNewChildIdentity(): void
    {
        $parentRunId = 'parent-deferred-3';
        $toolCallId = 'call-deferred-3';
        $identityFactory = new DeferredSubagentBatchIdentityFactory();
        $ids = $identityFactory->childIdentity($parentRunId, $toolCallId, 1);

        /** @var DeferredSubagentBatchRepository $batchRepo */
        $batchRepo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $this->reserveSingleBatch($batchRepo, $identityFactory, $parentRunId, $toolCallId, 'worker-reserved', 'Finish launch');

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start');

        $runStore = $this->createStub(RunStoreInterface::class);
        $def = $this->foregroundDefinition('worker-reserved');
        $service = $this->buildService($agentRunner, $runStore, $def);

        $outcome = $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->execute($parentRunId, 'worker-reserved', 'Finish launch'));

        $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $outcome);
        $batch = $batchRepo->findByParentRunAndToolCall($parentRunId, $toolCallId);
        $this->assertNotNull($batch);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Launched, $batch->launchStatus);
        $this->assertSame($ids['childRunId'], $batch->children[0]->childRunId);
    }

    public function testConcurrentReservationConvergesOnSingleArtifact(): void
    {
        $parentRunId = 'parent-deferred-race';
        $toolCallId = 'call-deferred-race';
        $identityFactory = new DeferredSubagentBatchIdentityFactory();
        $ids = $identityFactory->childIdentity($parentRunId, $toolCallId, 1);
        $deadline = (new \DateTimeImmutable())->modify('+120 seconds');

        /** @var DeferredSubagentBatchRepository $batchRepo */
        $batchRepo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $first = $this->reserveSingleBatch($batchRepo, $identityFactory, $parentRunId, $toolCallId, 'worker-race', 'Race task');
        $second = $this->reserveSingleBatch($batchRepo, $identityFactory, $parentRunId, $toolCallId, 'worker-race', 'Race task');

        $this->assertSame($first->lifecycleId, $second->lifecycleId);
        $this->assertSame($ids['childRunId'], $second->children[0]->childRunId);

        $identity = new \Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: $ids['childRunId'],
            artifactId: $ids['artifactId'],
            displayName: 'worker-race',
            taskSummary: 'Race task',
            definitionModel: null,
            artifactKind: \Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum::Subagent,
        );
        $lifecycle = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService::class);
        $lifecycle->ensureReservedPending($identity);
        $lifecycle->ensureReservedPending($identity);

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $entry = $registry->get($parentRunId, $ids['artifactId']);
        $this->assertNotNull($entry);
        $this->assertSame($ids['childRunId'], $entry->agentRunId);
    }

    public function testReserveRejectsDifferentTaskForSameToolCall(): void
    {
        $parentRunId = 'parent-deferred-mismatch';
        $toolCallId = 'call-deferred-mismatch';
        $identityFactory = new DeferredSubagentBatchIdentityFactory();
        $ids = $identityFactory->childIdentity($parentRunId, $toolCallId, 1);
        $deadline = (new \DateTimeImmutable())->modify('+120 seconds');

        /** @var DeferredSubagentBatchRepository $batchRepo */
        $batchRepo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $this->reserveSingleBatch($batchRepo, $identityFactory, $parentRunId, $toolCallId, 'worker-mismatch', 'Original task');

        $this->expectException(\Ineersa\AgentCore\Contract\Tool\ToolCallException::class);
        $this->expectExceptionMessage('child intent does not match');

        $this->reserveSingleBatch($batchRepo, $identityFactory, $parentRunId, $toolCallId, 'worker-mismatch', 'Different task');
    }

    public function testAlreadyRunningArtifactRetrySkipsSecondStart(): void
    {
        $parentRunId = 'parent-deferred-running';
        $toolCallId = 'call-deferred-running';
        $identityFactory = new DeferredSubagentBatchIdentityFactory();
        $ids = $identityFactory->childIdentity($parentRunId, $toolCallId, 1);

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $registry->create($parentRunId, $ids['artifactId'], $ids['childRunId'], 'worker-running', \Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum::Subagent);
        $registry->update($parentRunId, $ids['artifactId'], status: AgentArtifactStatusEnum::Running, startedAt: new \DateTimeImmutable());

        /** @var DeferredSubagentBatchRepository $batchRepo */
        $batchRepo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $this->reserveSingleBatch($batchRepo, $identityFactory, $parentRunId, $toolCallId, 'worker-running', 'Running retry');

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->never())->method('start');

        $def = $this->foregroundDefinition('worker-running');
        $service = $this->buildService($agentRunner, $this->createStub(RunStoreInterface::class), $def);

        $outcome = $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->execute($parentRunId, 'worker-running', 'Running retry'));

        $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $outcome);
        $entry = $registry->get($parentRunId, $ids['artifactId']);
        $this->assertSame(AgentArtifactStatusEnum::Running, $entry->status);
        $batch = $batchRepo->findByParentRunAndToolCall($parentRunId, $toolCallId);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Launched, $batch?->launchStatus);
    }

    public function testStartFailureAbortsAndMarksProjectionFailed(): void
    {
        $parentRunId = 'parent-deferred-start-fail';
        $toolCallId = 'call-deferred-start-fail';

        $agentRunner = $this->createStub(AgentRunnerInterface::class);
        $agentRunner->method('start')->willThrowException(new \RuntimeException('dispatch refused'));

        $def = $this->foregroundDefinition('worker-start-fail');
        $service = $this->buildService($agentRunner, $this->createStub(RunStoreInterface::class), $def);

        try {
            $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->execute($parentRunId, 'worker-start-fail', 'Fail start'));
            $this->fail('Expected ToolCallException');
        } catch (\Ineersa\AgentCore\Contract\Tool\ToolCallException $e) {
            $this->assertStringContainsString('Subagent batch launch failed', $e->getMessage());
            $this->assertStringNotContainsString('Parallel subagent', $e->getMessage());
        }

        /** @var DeferredSubagentBatchRepository $batchRepo */
        $batchRepo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $batch = $batchRepo->findByParentRunAndToolCall($parentRunId, $toolCallId);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Failed, $batch?->launchStatus);
    }

    public function testPostStartPersistenceFailureReturnsDeferredMarker(): void
    {
        $parentRunId = 'parent-deferred-post-start';
        $toolCallId = 'call-deferred-post-start';
        $identityFactory = new DeferredSubagentBatchIdentityFactory();
        $ids = $identityFactory->childIdentity($parentRunId, $toolCallId, 1);

        $resolver = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathResolver::class);
        $agentsDir = \dirname($resolver->registryPath($parentRunId));

        $logger = new TestLogger();
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start')->willReturnCallback(static function ($input) use ($agentsDir): string {
            if (is_dir($agentsDir)) {
                chmod($agentsDir, 0o555);
            }

            return $input->runId;
        });

        $def = $this->foregroundDefinition('worker-post-start');
        $service = $this->buildService($agentRunner, $this->createStub(RunStoreInterface::class), $def, $logger);

        try {
            $outcome = $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->execute($parentRunId, 'worker-post-start', 'Post start'));
            $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $outcome);
        } finally {
            if (is_dir($agentsDir)) {
                chmod($agentsDir, 0o755);
            }
        }

        /** @var DeferredSubagentBatchRepository $batchRepo */
        $batchRepo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $batch = $batchRepo->findByParentRunAndToolCall($parentRunId, $toolCallId);
        $this->assertNotNull($batch);
        $this->assertNotSame(DeferredSubagentBatchLaunchStatusEnum::Failed, $batch->launchStatus);

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $entry = $registry->get($parentRunId, $ids['artifactId']);
        $this->assertNotNull($entry);
        $this->assertSame(AgentArtifactStatusEnum::Pending, $entry->status);

        $warning = null;
        foreach ($logger->records as $record) {
            if (($record['context']['event_type'] ?? '') === 'deferred_subagent_batch.artifact_running_persist_failed') {
                $warning = $record;
                break;
            }
        }
        $this->assertNotNull($warning);
        $this->assertSame($parentRunId, $warning['context']['run_id']);
        $this->assertSame($toolCallId, $warning['context']['tool_call_id']);
        $this->assertSame($ids['childRunId'], $warning['context']['child_run_id']);
        $this->assertSame($ids['artifactId'], $warning['context']['artifact_id']);
    }

    public function testLaunchedProjectionRejectsDifferentTaskOnExecute(): void
    {
        $parentRunId = 'parent-deferred-launched-mismatch';
        $toolCallId = 'call-deferred-launched-mismatch';
        $identityFactory = new DeferredSubagentBatchIdentityFactory();
        $ids = $identityFactory->childIdentity($parentRunId, $toolCallId, 1);
        $deadline = (new \DateTimeImmutable())->modify('+120 seconds');

        /** @var DeferredSubagentBatchRepository $batchRepo */
        $batchRepo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $this->reserveSingleBatch($batchRepo, $identityFactory, $parentRunId, $toolCallId, 'worker-launched', 'Original launched task');
        $batchRepo->markLaunched($parentRunId, $toolCallId, new \DateTimeImmutable());

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->never())->method('start');

        $def = $this->foregroundDefinition('worker-launched');
        $service = $this->buildService($agentRunner, $this->createStub(RunStoreInterface::class), $def);

        $this->expectException(\Ineersa\AgentCore\Contract\Tool\ToolCallException::class);
        $this->expectExceptionMessage('child intent does not match');

        $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->execute($parentRunId, 'worker-launched', 'Different launched task'));
    }

    public function testPromoteToRunningForwardOnlyDoesNotRegressCompletedArtifact(): void
    {
        $parentRunId = 'parent-deferred-terminal';
        $identityFactory = new DeferredSubagentBatchIdentityFactory();
        $ids = $identityFactory->childIdentity($parentRunId, 'call-terminal', 1);

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $registry->create($parentRunId, $ids['artifactId'], $ids['childRunId'], 'worker-terminal', \Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum::Subagent);
        $registry->update($parentRunId, $ids['artifactId'], status: AgentArtifactStatusEnum::Completed, completedAt: new \DateTimeImmutable());

        $identity = new \Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: $ids['childRunId'],
            artifactId: $ids['artifactId'],
            displayName: 'worker-terminal',
            taskSummary: 'Done',
            definitionModel: null,
            artifactKind: \Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum::Subagent,
        );
        $registry->promoteToRunningForwardOnly($parentRunId, $ids['artifactId'], new \DateTimeImmutable());

        $entry = $registry->get($parentRunId, $ids['artifactId']);
        $this->assertSame(AgentArtifactStatusEnum::Completed, $entry->status);
    }

    private function reserveSingleBatch(
        DeferredSubagentBatchRepository $batchRepo,
        DeferredSubagentBatchIdentityFactory $identityFactory,
        string $parentRunId,
        string $toolCallId,
        string $agentName,
        string $task,
    ): \Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchProjectionDTO {
        $lifecycleId = $identityFactory->batchLifecycleId($parentRunId, $toolCallId);
        $ids = $identityFactory->childIdentity($parentRunId, $toolCallId, 1);
        $deadline = (new \DateTimeImmutable())->modify('+120 seconds');

        return $batchRepo->reserveBatch(
            lifecycleId: $lifecycleId,
            parentRunId: $parentRunId,
            parentTurnNo: 2,
            parentToolCallId: $toolCallId,
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Single,
            totalChildCount: 1,
            deadlineAt: $deadline,
            childIntents: [[
                'batchIndex' => 1,
                'childRunId' => $ids['childRunId'],
                'artifactId' => $ids['artifactId'],
                'agentName' => $agentName,
                'task' => $task,
                'definitionModel' => null,
            ]],
        );
    }

    private function foregroundDefinition(string $name): AgentDefinitionDTO
    {
        return new AgentDefinitionDTO(
            name: $name,
            description: 'Deferred test agent',
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'Test.',
            foregroundAllowed: true,
        );
    }

    private function buildService(AgentRunnerInterface $agentRunner, RunStoreInterface $runStore, AgentDefinitionDTO $def, ?\Psr\Log\LoggerInterface $logger = null): SubagentExecutionService
    {
        return SubagentExecutionServiceFactory::build([
            'catalog' => new AgentDefinitionCatalog([$def]),
            'agentRunner' => $agentRunner,
            'runStore' => $runStore,
            'batchRepository' => self::getContainer()->get(DeferredSubagentBatchRepository::class),
            'lifecycleListener' => self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\SubagentChildRunBatchLifecycleListener::class),
            'policyResolver' => self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\AgentToolPolicyResolver::class),
            'promptBuilder' => self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\AgentPromptBuilder::class),
            'skillsContextBuilder' => self::getContainer()->get(\Ineersa\CodingAgent\Skills\SkillsContextBuilder::class),
            'agentsContextBuilder' => self::getContainer()->get(\Ineersa\CodingAgent\Agent\Context\AgentsContextBuilder::class),
            'artifactRegistry' => self::getContainer()->get(AgentArtifactRegistry::class),
            'parentRunStore' => self::getContainer()->get(RunStoreInterface::class),
            'eventStore' => self::getContainer()->get(\Ineersa\AgentCore\Contract\EventStoreInterface::class),
            'committedRunEventAppender' => self::getContainer()->get(\Ineersa\CodingAgent\Session\CommittedRunEventAppender::class),
            'metadataReader' => self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader::class),
            'childRunDirectory' => self::getContainer()->get(\Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory::class),
            'contextAccessor' => self::getContainer()->get(StackToolExecutionContextAccessor::class),
            'logger' => $logger ?? self::getContainer()->get('logger'),
            'childProgressSummaryBuilder' => self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder::class),
            'appConfig' => self::getContainer()->get(\Ineersa\CodingAgent\Config\AppConfig::class),
        ]);
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
            toolName: 'subagent',
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 120,
            orderIndex: 0,
        );

        return $accessor->with($context, $callback);
    }
}
