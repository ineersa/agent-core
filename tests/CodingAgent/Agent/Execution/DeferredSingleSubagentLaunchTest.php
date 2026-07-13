<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
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

        $first = $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->execute($parentRunId, 'worker-retry', 'Once'));
        $second = $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->execute($parentRunId, 'worker-retry', 'Twice'));

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
            task: 'Reserved only',
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

    private function buildService(AgentRunnerInterface $agentRunner, RunStoreInterface $runStore, AgentDefinitionDTO $def): SubagentExecutionService
    {
        return SubagentExecutionServiceFactory::build([
            'catalog' => new AgentDefinitionCatalog([$def]),
            'agentRunner' => $agentRunner,
            'runStore' => $runStore,
            'launchProjectionRepository' => self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class),
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
            'logger' => self::getContainer()->get('logger'),
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
