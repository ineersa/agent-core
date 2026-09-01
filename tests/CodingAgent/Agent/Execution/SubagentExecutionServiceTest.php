<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Execution\AgentDepthGuard;
use Ineersa\CodingAgent\Agent\Execution\AgentMcpToolsResolver;
use Ineersa\CodingAgent\Agent\Execution\AgentPromptBuilder;
use Ineersa\CodingAgent\Agent\Execution\AgentToolPolicyResolver;
use Ineersa\CodingAgent\Agent\Execution\RunStartedMetadataReader;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Progress\SubagentProgressEventAppender;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder;
use Ineersa\CodingAgent\Agent\Execution\SubagentExecutionService;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Ineersa\CodingAgent\SystemPrompt\SystemPromptBuilder;
use Ineersa\CodingAgent\Tests\Agent\Execution\Support\SubagentExecutionServiceFactory;
use Ineersa\CodingAgent\Tests\Support\Mcp\TestMcpConfigLoaderFactory;
use Ineersa\CodingAgent\Tests\Support\StubRunRelationshipReader;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Clock\NativeClock;

#[CoversClass(SubagentExecutionService::class)]
final class SubagentExecutionServiceTest extends IsolatedKernelTestCase
{
    public function testNestedSubagentLaunchBlockedWhenParentIsAgentChild(): void
    {
        $def = new AgentDefinitionDTO(
            name: 'nested',
            description: 'Nested',
            tools: ['read'],
            instructions: 'Nested agent.',
        );

        $catalog = new AgentDefinitionCatalog([$def]);
        $directory = self::getContainer()->get(AgentChildRunDirectory::class);
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);

        $agentRunner = $this->createStub(AgentRunnerInterface::class);

        // Depth gate is operational-relationship based; EventStore must not be consulted.
        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->never())->method('firstFor');
        $metadataReader = new RunStartedMetadataReader($eventStore, AttributeSerializerValidatorTestFactory::denormalizer());

        $service = $this->makeService([
            'catalog' => $catalog,
            'depthGuard' => new AgentDepthGuard(),
            'policyResolver' => $this->defaultPolicyResolver(),
            'promptBuilder' => new AgentPromptBuilder(self::getContainer()->get(SystemPromptBuilder::class)),
            'skillsContextBuilder' => self::getContainer()->get(SkillsContextBuilder::class),
            'artifactRegistry' => $registry,
            'agentRunner' => $agentRunner,
            'eventStore' => $eventStore,
            'committedRunEventAppender' => self::getContainer()->get(SubagentProgressEventAppender::class),
            'metadataReader' => $metadataReader,
            'relationshipReader' => StubRunRelationshipReader::child('parent-child-run', 'grandparent'),
            'childRunDirectory' => $directory,
            'contextAccessor' => self::getContainer()->get(StackToolExecutionContextAccessor::class),
            'logger' => self::getContainer()->get('logger'),
            'agentsConfig' => new AgentsConfig(),
            'progressSnapshotBuilder' => new \Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder(),
            'childProgressSummaryBuilder' => new SubagentChildProgressSummaryBuilder(),
            'appConfig' => self::getContainer()->get(AppConfig::class),
            'modelResolver' => self::getContainer()->get(\Ineersa\CodingAgent\Config\ModelResolver::class),
        ]);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('is an agent child; nested launches are not supported');

        $this->withToolContext('parent-child-run', 'call-nested', static fn () => $service->execute('parent-child-run', 'nested', 'Go deeper'));
    }

    public function testMissingAgentDefinitionThrowsNonRetryable(): void
    {
        $catalog = new AgentDefinitionCatalog([]);
        $directory = self::getContainer()->get(AgentChildRunDirectory::class);
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $eventStore = $this->createStub(EventStoreInterface::class);

        $service = $this->makeService([
            'catalog' => $catalog,
            'depthGuard' => new AgentDepthGuard(),
            'policyResolver' => $this->defaultPolicyResolver(),
            'promptBuilder' => new AgentPromptBuilder(self::getContainer()->get(SystemPromptBuilder::class)),
            'skillsContextBuilder' => self::getContainer()->get(SkillsContextBuilder::class),
            'artifactRegistry' => $registry,
            'agentRunner' => $this->createStub(AgentRunnerInterface::class),
            'eventStore' => $eventStore,
            'committedRunEventAppender' => self::getContainer()->get(SubagentProgressEventAppender::class),
            'metadataReader' => new RunStartedMetadataReader($eventStore, AttributeSerializerValidatorTestFactory::denormalizer()),
            'relationshipReader' => StubRunRelationshipReader::topLevel('parent-4'),
            'childRunDirectory' => $directory,
            'contextAccessor' => self::getContainer()->get(StackToolExecutionContextAccessor::class),
            'logger' => self::getContainer()->get('logger'),
            'agentsConfig' => new AgentsConfig(),
            'progressSnapshotBuilder' => new \Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder(),
            'childProgressSummaryBuilder' => new SubagentChildProgressSummaryBuilder(),
            'appConfig' => self::getContainer()->get(AppConfig::class),
        ]);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not available');

        $this->withToolContext('parent-4', 'call-missing', static fn () => $service->execute('parent-4', 'nonexistent-agent', 'Do something'));
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

    private function defaultPolicyResolver(): AgentToolPolicyResolver
    {
        $registry = $this->createStub(ToolRegistryInterface::class);
        $registry->method('activeToolNames')->willReturn(['read']);

        return new AgentToolPolicyResolver($registry, $this->emptyMcpToolsResolver(), new AgentsConfig());
    }

    private function emptyMcpToolsResolver(): AgentMcpToolsResolver
    {
        $catalogStore = $this->createStub(McpToolCatalogStoreInterface::class);
        $catalogStore->method('read')->willReturn(null);
        $loader = TestMcpConfigLoaderFactory::loaderForServers([]);

        return new AgentMcpToolsResolver($catalogStore, $loader);
    }

    private function makeService(array $overrides): SubagentExecutionService
    {
        $defaults = [
            'catalog' => new AgentDefinitionCatalog([]),
            'depthGuard' => new AgentDepthGuard(),
            'policyResolver' => $this->defaultPolicyResolver(),
            'promptBuilder' => new AgentPromptBuilder(self::getContainer()->get(SystemPromptBuilder::class)),
            'skillsContextBuilder' => self::getContainer()->get(SkillsContextBuilder::class),
            'artifactRegistry' => self::getContainer()->get(AgentArtifactRegistry::class),
            'agentRunner' => $this->createStub(AgentRunnerInterface::class),
            'runStateRebuilder' => self::getContainer()->get(RunStateRebuilderInterface::class),
            'eventStore' => $this->createStub(EventStoreInterface::class),
            'committedRunEventAppender' => self::getContainer()->get(SubagentProgressEventAppender::class),
            'metadataReader' => new RunStartedMetadataReader($this->createStub(EventStoreInterface::class), AttributeSerializerValidatorTestFactory::denormalizer()),
            'relationshipReader' => StubRunRelationshipReader::empty(),
            'childRunDirectory' => self::getContainer()->get(AgentChildRunDirectory::class),
            'contextAccessor' => self::getContainer()->get(StackToolExecutionContextAccessor::class),
            'logger' => self::getContainer()->get('logger'),
            'agentsConfig' => new AgentsConfig(maxAgents: 8),
            'progressSnapshotBuilder' => new \Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder(),
            'childProgressSummaryBuilder' => new SubagentChildProgressSummaryBuilder(),
            'appConfig' => self::getContainer()->get(AppConfig::class),
            'modelResolver' => self::getContainer()->get(\Ineersa\CodingAgent\Config\ModelResolver::class),
            'clock' => new NativeClock(),
            'batchRepository' => self::getContainer()->get(\Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository::class),
            'lifecycleListener' => self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\SubagentChildRunBatchLifecycleListener::class),
            'forkLaunchInputBuilder' => self::getContainer()->get(\Ineersa\CodingAgent\Agent\Fork\ForkChildLaunchInputBuilder::class),
            'forkToolPolicyResolver' => self::getContainer()->get(\Ineersa\CodingAgent\Agent\Fork\ForkToolPolicyResolver::class),
            'childExtensionSelection' => self::getContainer()->get(\Ineersa\CodingAgent\Agent\ChildExtensionSelectionService::class),
            'toolRegistry' => self::getContainer()->get(ToolRegistryInterface::class),
        ];

        return SubagentExecutionServiceFactory::build(array_merge($defaults, $overrides));
    }
}

/**
 * Records RunEvent inputs before delegating allocation to InMemoryEventStore.
 */
final class ProgressAppendInputRecordingEventStore implements EventStoreInterface
{
    /** @var list<RunEvent> */
    public function __construct(private readonly InMemoryEventStore $inner)
    {
    }

    public function append(RunEvent $event): RunEvent
    {
        return $this->inner->append($event);
    }

    public function appendMany(array $events): array
    {
        $out = [];
        foreach ($events as $event) {
            $out[] = $this->append($event);
        }

        return $out;
    }

    public function latestSequenceFor(string $runId): ?int
    {
        $events = $this->allFor($runId);

        return [] === $events ? null : $events[array_key_last($events)]->seq;
    }

    public function firstFor(string $runId): ?RunEvent
    {
        $events = $this->allFor($runId);

        return $events[0] ?? null;
    }

    public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable
    {
        return $this->inner->rangeFor($runId, $startSeq, $endSeq);
    }

    public function reverseFor(string $runId): iterable
    {
        return [];
    }

    public function allFor(string $runId): array
    {
        return $this->inner->allFor($runId);
    }
}
