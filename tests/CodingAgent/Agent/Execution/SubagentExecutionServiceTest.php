<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStoreFactory;
use Ineersa\CodingAgent\Agent\Context\AgentsContextBuilder;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\McpAgentModeEnum;
use Ineersa\CodingAgent\Agent\Definition\McpPolicyDTO;
use Ineersa\CodingAgent\Agent\Execution\AgentDepthGuard;
use Ineersa\CodingAgent\Agent\Execution\AgentMcpToolsResolver;
use Ineersa\CodingAgent\Agent\Execution\AgentPromptBuilder;
use Ineersa\CodingAgent\Agent\Execution\AgentToolPolicyResolver;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Progress\SubagentProgressEventAppender;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder;
use Ineersa\CodingAgent\Agent\Execution\SubagentExecutionService;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Ineersa\CodingAgent\Session\CommittedRunEventAppender;
use Ineersa\CodingAgent\Skills\SkillContextRenderer;
use Ineersa\CodingAgent\Skills\SkillDiscovery;
use Ineersa\CodingAgent\Skills\SkillsConfig;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Ineersa\CodingAgent\SystemPrompt\SystemPromptBuilder;
use Ineersa\CodingAgent\Tests\Agent\Execution\Support\SubagentExecutionServiceFactory;
use Ineersa\CodingAgent\Tests\Support\Mcp\TestMcpConfigLoaderFactory;
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
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'Nested agent.',
        );

        $catalog = new AgentDefinitionCatalog([$def]);
        $directory = self::getContainer()->get(AgentChildRunDirectory::class);
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);

        $runStore = $this->createStub(RunStoreInterface::class);
        $parentRunStore = $this->createStub(RunStoreInterface::class);
        $agentRunner = $this->createStub(AgentRunnerInterface::class);

        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->once())
            ->method('allFor')
            ->with('parent-child-run')
            ->willReturn([
                new RunEvent(
                    runId: 'parent-child-run',
                    seq: 1,
                    turnNo: 0,
                    type: RunEventTypeEnum::RunStarted->value,
                    payload: [
                        'step_id' => 's',
                        'payload' => [
                            'metadata' => [
                                'session' => [
                                    'kind' => 'agent_child',
                                    'parent_run_id' => 'grandparent',
                                    'artifact_id' => 'agent_abc',
                                ],
                            ],
                        ],
                    ],
                ),
            ]);

        $metadataReader = new SubagentRunMetadataReader($eventStore);

        $service = $this->makeService([
            'catalog' => $catalog,
            'depthGuard' => new AgentDepthGuard(),
            'policyResolver' => $this->defaultPolicyResolver(),
            'promptBuilder' => new AgentPromptBuilder(self::getContainer()->get(SystemPromptBuilder::class)),
            'skillsContextBuilder' => self::getContainer()->get(SkillsContextBuilder::class),
            'artifactRegistry' => $registry,
            'agentRunner' => $agentRunner,
            'runStore' => $runStore,
            'parentRunStore' => $parentRunStore,
            'eventStore' => $eventStore,
            'committedRunEventAppender' => new SubagentProgressEventAppender(self::getContainer()->get(CommittedRunEventAppender::class)),
            'metadataReader' => $metadataReader,
            'childRunDirectory' => $directory,
            'contextAccessor' => self::getContainer()->get(StackToolExecutionContextAccessor::class),
            'logger' => self::getContainer()->get('logger'),
            'agentsConfig' => new AgentsConfig(),
            'progressSnapshotBuilder' => new \Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder(),
            'childProgressSummaryBuilder' => new SubagentChildProgressSummaryBuilder(self::getContainer()->get(AgentChildRunEventStoreFactory::class)),
            'agentsContextBuilder' => self::getContainer()->get(AgentsContextBuilder::class),
            'appConfig' => self::getContainer()->get(AppConfig::class),
        ]);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Nested subagent launches are not supported');

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
            'runStore' => $this->createStub(RunStoreInterface::class),
            'parentRunStore' => $this->createStub(RunStoreInterface::class),
            'eventStore' => $eventStore,
            'committedRunEventAppender' => new SubagentProgressEventAppender(self::getContainer()->get(CommittedRunEventAppender::class)),
            'metadataReader' => new SubagentRunMetadataReader($eventStore),
            'childRunDirectory' => $directory,
            'contextAccessor' => self::getContainer()->get(StackToolExecutionContextAccessor::class),
            'logger' => self::getContainer()->get('logger'),
            'agentsConfig' => new AgentsConfig(),
            'progressSnapshotBuilder' => new \Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder(),
            'childProgressSummaryBuilder' => new SubagentChildProgressSummaryBuilder(self::getContainer()->get(AgentChildRunEventStoreFactory::class)),
            'agentsContextBuilder' => self::getContainer()->get(AgentsContextBuilder::class),
            'appConfig' => self::getContainer()->get(AppConfig::class),
        ]);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not available');

        $this->withToolContext('parent-4', 'call-missing', static fn () => $service->execute('parent-4', 'nonexistent-agent', 'Do something'));
    }

    public function testForegroundNotAllowedThrowsNonRetryable(): void
    {
        $def = new AgentDefinitionDTO(
            name: 'background-only',
            description: 'bg only',
            tools: [],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'bg.',
            foregroundAllowed: false,
        );

        $catalog = new AgentDefinitionCatalog([$def]);
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
            'runStore' => $this->createStub(RunStoreInterface::class),
            'parentRunStore' => $this->createStub(RunStoreInterface::class),
            'eventStore' => $eventStore,
            'committedRunEventAppender' => new SubagentProgressEventAppender(self::getContainer()->get(CommittedRunEventAppender::class)),
            'metadataReader' => new SubagentRunMetadataReader($eventStore),
            'childRunDirectory' => $directory,
            'contextAccessor' => self::getContainer()->get(StackToolExecutionContextAccessor::class),
            'logger' => self::getContainer()->get('logger'),
            'agentsConfig' => new AgentsConfig(),
            'progressSnapshotBuilder' => new \Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder(),
            'childProgressSummaryBuilder' => new SubagentChildProgressSummaryBuilder(self::getContainer()->get(AgentChildRunEventStoreFactory::class)),
            'agentsContextBuilder' => self::getContainer()->get(AgentsContextBuilder::class),
            'appConfig' => self::getContainer()->get(AppConfig::class),
        ]);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('does not allow foreground');

        $this->withToolContext('parent-5', 'call-bg', static fn () => $service->execute('parent-5', 'background-only', 'Task'));
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

        return new AgentToolPolicyResolver($registry, $this->emptyMcpToolsResolver());
    }

    private function emptyMcpToolsResolver(): AgentMcpToolsResolver
    {
        $catalogStore = $this->createStub(McpToolCatalogStoreInterface::class);
        $catalogStore->method('read')->willReturn(null);
        $loader = TestMcpConfigLoaderFactory::loaderForServers([]);

        return new AgentMcpToolsResolver($catalogStore, $loader);
    }

    private function makeSkillsContextBuilder(string $cwd): SkillsContextBuilder
    {
        $homeDir = $cwd.'/home';
        if (!is_dir($homeDir)) {
            mkdir($homeDir, 0777, true);
        }
        $skillsConfig = new SkillsConfig(noSkills: false, skillsPaths: [], preloadSkills: []);

        $discovery = new SkillDiscovery(
            config: $skillsConfig,
            pathResolver: new SettingsPathResolver($cwd, $homeDir),
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'test'),
                logging: new LoggingConfig(),
                cwd: $cwd,
            ),
            extractor: new MarkdownFrontmatterExtractor(),
        );

        return new SkillsContextBuilder(
            discovery: $discovery,
            config: $skillsConfig,
            renderer: new SkillContextRenderer(),
            extractor: new MarkdownFrontmatterExtractor(),
        );
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if (false === $items) {
            return;
        }
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->rmdirRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function makeService(array $overrides): SubagentExecutionService
    {
        $defaults = [
            'catalog' => new AgentDefinitionCatalog([]),
            'depthGuard' => new AgentDepthGuard(),
            'policyResolver' => $this->defaultPolicyResolver(),
            'promptBuilder' => new AgentPromptBuilder(self::getContainer()->get(SystemPromptBuilder::class)),
            'skillsContextBuilder' => self::getContainer()->get(SkillsContextBuilder::class),
            'agentsContextBuilder' => self::getContainer()->get(AgentsContextBuilder::class),
            'artifactRegistry' => self::getContainer()->get(AgentArtifactRegistry::class),
            'agentRunner' => $this->createStub(AgentRunnerInterface::class),
            'runStore' => $this->createStub(RunStoreInterface::class),
            'parentRunStore' => $this->createStub(RunStoreInterface::class),
            'eventStore' => $this->createStub(EventStoreInterface::class),
            'committedRunEventAppender' => new SubagentProgressEventAppender(self::getContainer()->get(CommittedRunEventAppender::class)),
            'metadataReader' => new SubagentRunMetadataReader($this->createStub(EventStoreInterface::class)),
            'childRunDirectory' => self::getContainer()->get(AgentChildRunDirectory::class),
            'contextAccessor' => self::getContainer()->get(StackToolExecutionContextAccessor::class),
            'logger' => self::getContainer()->get('logger'),
            'agentsConfig' => new AgentsConfig(maxAgents: 8),
            'progressSnapshotBuilder' => new \Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder(),
            'childProgressSummaryBuilder' => new SubagentChildProgressSummaryBuilder(self::getContainer()->get(AgentChildRunEventStoreFactory::class)),
            'appConfig' => self::getContainer()->get(AppConfig::class),
            'clock' => new NativeClock(),
            'batchRepository' => self::getContainer()->get(\Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository::class),
            'lifecycleListener' => self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\ChildRun\AgentChildRunBatchLifecycleListener::class),
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
    public array $appendInputs = [];

    public function __construct(private readonly InMemoryEventStore $inner)
    {
    }

    public function append(RunEvent $event): RunEvent
    {
        $this->appendInputs[] = $event;

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

    public function allFor(string $runId): array
    {
        return $this->inner->allFor($runId);
    }
}
