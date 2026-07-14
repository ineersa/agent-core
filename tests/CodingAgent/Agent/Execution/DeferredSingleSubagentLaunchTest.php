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
use Ineersa\CodingAgent\Agent\Execution\DeferredSingleSubagentIdentityFactory;
use Ineersa\CodingAgent\Agent\Execution\DeferredSingleSubagentLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\SubagentExecutionService;
use Ineersa\CodingAgent\Entity\DeferredSingleSubagentLaunchRepository;
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
        $identityFactory = new DeferredSingleSubagentIdentityFactory();
        $ids = $identityFactory->forParentToolCall($parentRunId, $toolCallId);

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

        /** @var DeferredSingleSubagentLaunchRepository $repo */
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $projection = $repo->findByParentRunAndToolCall($parentRunId, $toolCallId);
        $this->assertNotNull($projection);
        $this->assertSame($ids['childRunId'], $projection->childRunId);
        $this->assertSame($ids['artifactId'], $projection->artifactId);
        $this->assertSame(DeferredSingleSubagentLaunchStatusEnum::Launched, $projection->launchStatus);

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $entry = $registry->get($parentRunId, $ids['artifactId']);
        $this->assertNotNull($entry);
        $this->assertSame(AgentArtifactStatusEnum::Running, $entry->status);
    }

    public function testReinvokeReturnsMarkerWithoutSecondLaunch(): void
    {
        $parentRunId = 'parent-deferred-2';
        $toolCallId = 'call-deferred-2';
        $identityFactory = new DeferredSingleSubagentIdentityFactory();
        $ids = $identityFactory->forParentToolCall($parentRunId, $toolCallId);

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start');

        $runStore = $this->createStub(RunStoreInterface::class);
        $def = $this->foregroundDefinition('worker-retry');
        $service = $this->buildService($agentRunner, $runStore, $def);

        $first = $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->execute($parentRunId, 'worker-retry', 'Same task'));
        $second = $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->execute($parentRunId, 'worker-retry', 'Same task'));

        $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $first);
        $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $second);

        /** @var DeferredSingleSubagentLaunchRepository $repo */
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $projection = $repo->findByParentRunAndToolCall($parentRunId, $toolCallId);
        $this->assertNotNull($projection);
        $this->assertSame($ids['childRunId'], $projection->childRunId);
        $this->assertSame($ids['artifactId'], $projection->artifactId);
    }

    public function testReservedCrashWindowRetryDispatchesStartWithoutNewChildIdentity(): void
    {
        $parentRunId = 'parent-deferred-3';
        $toolCallId = 'call-deferred-3';
        $identityFactory = new DeferredSingleSubagentIdentityFactory();
        $ids = $identityFactory->forParentToolCall($parentRunId, $toolCallId);

        /** @var DeferredSingleSubagentLaunchRepository $repo */
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $deadline = (new \DateTimeImmutable())->modify('+120 seconds');
        $repo->reserve(
            parentRunId: $parentRunId,
            parentTurnNo: 2,
            parentToolCallId: $toolCallId,
            parentOrderIndex: 0,
            childRunId: $ids['childRunId'],
            artifactId: $ids['artifactId'],
            agentName: 'worker-reserved',
            task: 'Finish launch',
            definitionModel: null,
            deadlineAt: $deadline,
        );

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start');

        $runStore = $this->createStub(RunStoreInterface::class);
        $def = $this->foregroundDefinition('worker-reserved');
        $service = $this->buildService($agentRunner, $runStore, $def);

        $outcome = $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->execute($parentRunId, 'worker-reserved', 'Finish launch'));

        $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $outcome);
        $projection = $repo->findByParentRunAndToolCall($parentRunId, $toolCallId);
        $this->assertNotNull($projection);
        $this->assertSame(DeferredSingleSubagentLaunchStatusEnum::Launched, $projection->launchStatus);
        $this->assertSame($ids['childRunId'], $projection->childRunId);
    }

    public function testConcurrentReservationConvergesOnSingleArtifact(): void
    {
        $parentRunId = 'parent-deferred-race';
        $toolCallId = 'call-deferred-race';
        $identityFactory = new DeferredSingleSubagentIdentityFactory();
        $ids = $identityFactory->forParentToolCall($parentRunId, $toolCallId);
        $deadline = (new \DateTimeImmutable())->modify('+120 seconds');

        /** @var DeferredSingleSubagentLaunchRepository $repo */
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $first = $repo->reserve(
            parentRunId: $parentRunId,
            parentTurnNo: 2,
            parentToolCallId: $toolCallId,
            parentOrderIndex: 0,
            childRunId: $ids['childRunId'],
            artifactId: $ids['artifactId'],
            agentName: 'worker-race',
            task: 'Race task',
            definitionModel: null,
            deadlineAt: $deadline,
        );
        $second = $repo->reserve(
            parentRunId: $parentRunId,
            parentTurnNo: 2,
            parentToolCallId: $toolCallId,
            parentOrderIndex: 0,
            childRunId: $ids['childRunId'],
            artifactId: $ids['artifactId'],
            agentName: 'worker-race',
            task: 'Race task',
            definitionModel: null,
            deadlineAt: $deadline,
        );

        $this->assertSame($first->lifecycleId, $second->lifecycleId);
        $this->assertSame($ids['childRunId'], $second->childRunId);

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
        $identityFactory = new DeferredSingleSubagentIdentityFactory();
        $ids = $identityFactory->forParentToolCall($parentRunId, $toolCallId);
        $deadline = (new \DateTimeImmutable())->modify('+120 seconds');

        /** @var DeferredSingleSubagentLaunchRepository $repo */
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $repo->reserve(
            parentRunId: $parentRunId,
            parentTurnNo: 2,
            parentToolCallId: $toolCallId,
            parentOrderIndex: 0,
            childRunId: $ids['childRunId'],
            artifactId: $ids['artifactId'],
            agentName: 'worker-mismatch',
            task: 'Original task',
            definitionModel: null,
            deadlineAt: $deadline,
        );

        $this->expectException(\Ineersa\AgentCore\Contract\Tool\ToolCallException::class);
        $this->expectExceptionMessage('different agent or task');

        $repo->reserve(
            parentRunId: $parentRunId,
            parentTurnNo: 2,
            parentToolCallId: $toolCallId,
            parentOrderIndex: 0,
            childRunId: $ids['childRunId'],
            artifactId: $ids['artifactId'],
            agentName: 'worker-mismatch',
            task: 'Different task',
            definitionModel: null,
            deadlineAt: $deadline,
        );
    }

    public function testAlreadyRunningArtifactRetrySkipsSecondStart(): void
    {
        $parentRunId = 'parent-deferred-running';
        $toolCallId = 'call-deferred-running';
        $identityFactory = new DeferredSingleSubagentIdentityFactory();
        $ids = $identityFactory->forParentToolCall($parentRunId, $toolCallId);

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $registry->create($parentRunId, $ids['artifactId'], $ids['childRunId'], 'worker-running', \Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum::Subagent);
        $registry->update($parentRunId, $ids['artifactId'], status: AgentArtifactStatusEnum::Running, startedAt: new \DateTimeImmutable());

        /** @var DeferredSingleSubagentLaunchRepository $repo */
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $deadline = (new \DateTimeImmutable())->modify('+120 seconds');
        $repo->reserve(
            parentRunId: $parentRunId,
            parentTurnNo: 2,
            parentToolCallId: $toolCallId,
            parentOrderIndex: 0,
            childRunId: $ids['childRunId'],
            artifactId: $ids['artifactId'],
            agentName: 'worker-running',
            task: 'Running retry',
            definitionModel: null,
            deadlineAt: $deadline,
        );

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->never())->method('start');

        $def = $this->foregroundDefinition('worker-running');
        $service = $this->buildService($agentRunner, $this->createStub(RunStoreInterface::class), $def);

        $outcome = $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->execute($parentRunId, 'worker-running', 'Running retry'));

        $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $outcome);
        $entry = $registry->get($parentRunId, $ids['artifactId']);
        $this->assertSame(AgentArtifactStatusEnum::Running, $entry->status);
        $projection = $repo->findByParentRunAndToolCall($parentRunId, $toolCallId);
        $this->assertSame(DeferredSingleSubagentLaunchStatusEnum::Launched, $projection?->launchStatus);
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
            $this->assertStringContainsString('Subagent child launch failed', $e->getMessage());
            $this->assertStringNotContainsString('Parallel subagent', $e->getMessage());
        }

        /** @var DeferredSingleSubagentLaunchRepository $repo */
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $projection = $repo->findByParentRunAndToolCall($parentRunId, $toolCallId);
        $this->assertSame(DeferredSingleSubagentLaunchStatusEnum::Failed, $projection?->launchStatus);
    }

    public function testPostStartPersistenceFailureReturnsDeferredMarker(): void
    {
        $parentRunId = 'parent-deferred-post-start';
        $toolCallId = 'call-deferred-post-start';
        $identityFactory = new DeferredSingleSubagentIdentityFactory();
        $ids = $identityFactory->forParentToolCall($parentRunId, $toolCallId);

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

        /** @var DeferredSingleSubagentLaunchRepository $repo */
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $projection = $repo->findByParentRunAndToolCall($parentRunId, $toolCallId);
        $this->assertNotNull($projection);
        $this->assertNotSame(DeferredSingleSubagentLaunchStatusEnum::Failed, $projection->launchStatus);

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $entry = $registry->get($parentRunId, $ids['artifactId']);
        $this->assertNotNull($entry);
        $this->assertSame(AgentArtifactStatusEnum::Pending, $entry->status);

        $warning = null;
        foreach ($logger->records as $record) {
            if (($record['context']['event_type'] ?? '') === 'deferred_single_subagent.artifact_running_persist_failed') {
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
        $identityFactory = new DeferredSingleSubagentIdentityFactory();
        $ids = $identityFactory->forParentToolCall($parentRunId, $toolCallId);
        $deadline = (new \DateTimeImmutable())->modify('+120 seconds');

        /** @var DeferredSingleSubagentLaunchRepository $repo */
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $repo->reserve(
            parentRunId: $parentRunId,
            parentTurnNo: 2,
            parentToolCallId: $toolCallId,
            parentOrderIndex: 0,
            childRunId: $ids['childRunId'],
            artifactId: $ids['artifactId'],
            agentName: 'worker-launched',
            task: 'Original launched task',
            definitionModel: null,
            deadlineAt: $deadline,
        );
        $repo->markLaunched($parentRunId, $toolCallId, new \DateTimeImmutable());

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->never())->method('start');

        $def = $this->foregroundDefinition('worker-launched');
        $service = $this->buildService($agentRunner, $this->createStub(RunStoreInterface::class), $def);

        $this->expectException(\Ineersa\AgentCore\Contract\Tool\ToolCallException::class);
        $this->expectExceptionMessage('different agent or task');

        $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->execute($parentRunId, 'worker-launched', 'Different launched task'));
    }

    public function testPromoteToRunningForwardOnlyDoesNotRegressCompletedArtifact(): void
    {
        $parentRunId = 'parent-deferred-terminal';
        $identityFactory = new DeferredSingleSubagentIdentityFactory();
        $ids = $identityFactory->forParentToolCall($parentRunId, 'call-terminal');

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
            'launchProjectionRepository' => self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class),
            'deferredBatchLaunch' => self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchLaunchService::class),
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
