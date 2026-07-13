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
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
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
use Ineersa\CodingAgent\Agent\Execution\SubagentTaskDTO;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Entity\DeferredSingleSubagentLaunchRepository;
use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Ineersa\CodingAgent\Session\CommittedRunEventAppender;
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Ineersa\CodingAgent\Skills\SkillContextRenderer;
use Ineersa\CodingAgent\Skills\SkillDiscovery;
use Ineersa\CodingAgent\Skills\SkillsConfig;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Ineersa\CodingAgent\SystemPrompt\SystemPromptBuilder;
use Ineersa\CodingAgent\Tests\Agent\Execution\Support\SubagentExecutionServiceFactory;
use Ineersa\CodingAgent\Tests\Support\Mcp\TestMcpConfigLoaderFactory;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
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

        $service->execute('parent-child-run', 'nested', 'Go deeper');
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

        $service->execute('parent-4', 'nonexistent-agent', 'Do something');
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

        $service->execute('parent-5', 'background-only', 'Task');
    }

    /**
     * Regression: progress events are submitted with unallocated seq 0; the committed
     * store allocates monotonic persisted sequences above the parent high-water mark and
     * CommittedRunEventAppender synchronizes parent RunState.lastSeq.
     */
    public function testExecuteParallelCompletesDistinctArtifacts(): void
    {
        /** @var array<string, string> $handoffByRunId */
        $handoffByRunId = [];

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->exactly(2))
            ->method('start')
            ->willReturnCallback(static function (StartRunInput $input) use (&$handoffByRunId): string {
                $agentName = (string) ($input->metadata?->session['agent_name'] ?? 'unknown');
                $handoffByRunId[(string) $input->runId] = 'parallel-a' === $agentName ? 'A_OK' : 'B_OK';

                return (string) $input->runId;
            });

        $runStore = $this->createStub(RunStoreInterface::class);
        $runStore->method('get')->willReturnCallback(static function (string $runId) use (&$handoffByRunId): ?RunState {
            if (!isset($handoffByRunId[$runId])) {
                return null;
            }

            return new RunState(
                runId: $runId,
                status: RunStatus::Completed,
                version: 1,
                messages: [new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => $handoffByRunId[$runId]]])],
            );
        });

        $def = static fn (string $name) => new AgentDefinitionDTO(
            name: $name,
            description: $name,
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'Do task.',
            parallelAllowed: true,
        );

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $parentRunStore = new InMemoryRunStore();
        $parentRunStore->compareAndSwap(new RunState(runId: 'parent-parallel-ok', status: RunStatus::Running, version: 1, lastSeq: 0), 0);
        $eventStore = new InMemoryEventStore();
        $sequencedAppender = new CommittedRunEventAppender($eventStore, $parentRunStore, new \Psr\Log\NullLogger());
        $contextAccessor = new StackToolExecutionContextAccessor();
        $toolContext = new ToolContext(
            runId: 'parent-parallel-ok',
            turnNo: 1,
            toolCallId: 'tc-parallel-ok',
            toolName: 'subagent',
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 120,
        );
        $service = $this->makeService([
            'catalog' => new AgentDefinitionCatalog([$def('parallel-a'), $def('parallel-b')]),
            'agentRunner' => $agentRunner,
            'runStore' => $runStore,
            'parentRunStore' => $parentRunStore,
            'eventStore' => $eventStore,
            'committedRunEventAppender' => $sequencedAppender,
            'contextAccessor' => $contextAccessor,
        ]);

        $result = $contextAccessor->with($toolContext, static fn (): string => $service->executeParallel('parent-parallel-ok', [
            new SubagentTaskDTO(agent: 'parallel-a', task: 'Task A'),
            new SubagentTaskDTO(agent: 'parallel-b', task: 'Task B'),
        ]));

        $this->assertStringContainsString('Parallel subagents completed', $result);
        $this->assertStringContainsString('A_OK', $result);
        $this->assertStringContainsString('B_OK', $result);
        $this->assertMatchesRegularExpression('/Artifact: agent_[0-9a-f]{16}/', $result);

        $entries = $registry->list('parent-parallel-ok');
        $this->assertCount(2, $entries);
        foreach ($entries as $entry) {
            $this->assertSame(AgentArtifactStatusEnum::Completed, $entry->status);
        }

        $progressEvents = array_values(array_filter(
            $eventStore->allFor('parent-parallel-ok'),
            static fn (RunEvent $e): bool => RunEventTypeEnum::ToolExecutionUpdate->value === $e->type,
        ));
        $this->assertNotEmpty($progressEvents);
        $this->assertSame(
            'completed',
            $progressEvents[\count($progressEvents) - 1]->payload['subagent_progress']['status'] ?? null,
            'Final parallel progress payload must use origin/main aggregate status completed when all children succeed.',
        );
    }

    public function testExecuteParallelLongChildMessageNotTruncated(): void
    {
        /** @var array<string, string> $handoffByRunId */
        $handoffByRunId = [];

        // Build a handoff message well over the old 240-char truncation limit.
        $longMessage = '# Report'."\n\n".'Step 1: Load configuration from config.yaml and validate all required keys are present.'."\n";
        $longMessage .= 'Step 2: Connect to the remote API endpoint at https://api.example.com/v3/agents with timeout 30000ms.'."\n";
        $longMessage .= 'Step 3: Authenticate using the service account credentials from the vault and obtain a bearer token.'."\n";
        $longMessage .= 'Step 4: Invoke the agent\'s execute method with parameters: action=scan, depth=full, scope=recursive.'."\n";
        $longMessage .= 'Step 5: Collect all artifacts from the response and store them in the temporary working directory.'."\n";
        $longMessage .= 'Step 6: Parse the output using the StructuredDataParser and extract the relevant metrics table.'."\n";
        $longMessage .= 'Step 7: Generate a summary report in Markdown format and include all critical findings.'."\n";
        $longMessage .= 'Conclusion: All 7 steps completed successfully. No errors detected. Ready for handoff.';
        $this->assertGreaterThan(300, \strlen($longMessage), 'Test message must exceed old 240-char truncation limit');

        $expectedTail = 'Ready for handoff.';

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())
            ->method('start')
            ->willReturnCallback(static function (StartRunInput $input) use (&$handoffByRunId, $longMessage): string {
                $handoffByRunId[(string) $input->runId] = $longMessage;

                return (string) $input->runId;
            });

        $runStore = $this->createStub(RunStoreInterface::class);
        $runStore->method('get')->willReturnCallback(static function (string $runId) use (&$handoffByRunId): ?RunState {
            if (!isset($handoffByRunId[$runId])) {
                return null;
            }

            return new RunState(
                runId: $runId,
                status: RunStatus::Completed,
                version: 1,
                messages: [new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => $handoffByRunId[$runId]]])],
            );
        });

        $def = static fn (string $name) => new AgentDefinitionDTO(
            name: $name,
            description: $name,
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'Do task.',
            parallelAllowed: true,
        );

        $service = $this->makeService([
            'catalog' => new AgentDefinitionCatalog([$def('long-msg-agent')]),
            'agentRunner' => $agentRunner,
            'runStore' => $runStore,
        ]);

        $result = $service->executeParallel('parent-parallel-long-msg', [
            new SubagentTaskDTO(agent: 'long-msg-agent', task: 'Long task'),
        ]);

        // Old truncation pattern must NOT appear.
        $this->assertStringNotContainsString('...', $result, 'Old substr+... truncation must not be present');
        // Full message tail must be present, proving no 240-char cut.
        $this->assertStringContainsString($expectedTail, $result, 'Long message tail must survive without truncation');
        // The message must be present in full.
        $this->assertStringContainsString('Step 1: Load', $result);
        $this->assertStringContainsString('Step 7: Generate', $result);
        $this->assertStringContainsString('All 7 steps completed successfully', $result);
    }

    public function testExecuteParallelFailFastWhenExceedingMaxAgents(): void
    {
        $def = new AgentDefinitionDTO(
            name: 'parallel-cap',
            description: 'cap',
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'x',
            parallelAllowed: true,
        );
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->never())->method('start');

        $service = $this->makeService([
            'catalog' => new AgentDefinitionCatalog([$def]),
            'agentRunner' => $agentRunner,
            'agentsConfig' => new AgentsConfig(maxAgents: 2),
        ]);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('at most 2 agents');

        try {
            $service->executeParallel('parent-cap', [
                new SubagentTaskDTO(agent: 'parallel-cap', task: '1'),
                new SubagentTaskDTO(agent: 'parallel-cap', task: '2'),
                new SubagentTaskDTO(agent: 'parallel-cap', task: '3'),
            ]);
        } finally {
            $this->assertCount(0, $registry->list('parent-cap'));
        }
    }

    public function testExecuteParallelPartialFailureThrowsWithArtifactReport(): void
    {
        /** @var array<string, RunStatus> $statusByRunId */
        $statusByRunId = [];

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->exactly(2))
            ->method('start')
            ->willReturnCallback(static function (StartRunInput $input) use (&$statusByRunId): string {
                $agentName = (string) ($input->metadata?->session['agent_name'] ?? '');
                $statusByRunId[(string) $input->runId] = 'ok-agent' === $agentName ? RunStatus::Completed : RunStatus::Failed;

                return (string) $input->runId;
            });

        $runStore = $this->createStub(RunStoreInterface::class);
        $runStore->method('get')->willReturnCallback(static function (string $runId) use (&$statusByRunId): ?RunState {
            if (!isset($statusByRunId[$runId])) {
                return null;
            }

            if (RunStatus::Completed === $statusByRunId[$runId]) {
                return new RunState(
                    runId: $runId,
                    status: RunStatus::Completed,
                    version: 1,
                    messages: [new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'OK_HANDOFF']])],
                );
            }

            return new RunState(
                runId: $runId,
                status: RunStatus::Failed,
                version: 1,
                errorMessage: 'boom',
                messages: [],
            );
        });

        $def = static fn (string $name) => new AgentDefinitionDTO(
            name: $name,
            description: $name,
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'x',
            parallelAllowed: true,
        );

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $service = $this->makeService([
            'catalog' => new AgentDefinitionCatalog([$def('ok-agent'), $def('fail-agent')]),
            'agentRunner' => $agentRunner,
            'runStore' => $runStore,
        ]);

        try {
            $service->executeParallel('parent-partial', [
                new SubagentTaskDTO(agent: 'ok-agent', task: 'ok'),
                new SubagentTaskDTO(agent: 'fail-agent', task: 'fail'),
            ]);
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('failed for one or more children', $e->getMessage());
            $this->assertStringContainsString('Artifact:', $e->getMessage());
            $this->assertStringContainsString('boom', $e->getMessage());
            $this->assertStringContainsString('OK_HANDOFF', $e->getMessage());
        }

        $entries = $registry->list('parent-partial');
        $this->assertCount(2, $entries);
        $statuses = array_map(static fn ($e) => $e->status, $entries);
        $this->assertContains(AgentArtifactStatusEnum::Completed, $statuses);
        $this->assertContains(AgentArtifactStatusEnum::Failed, $statuses);
    }

    public function testExecuteParallelStartFailureCleansUpStartedChildren(): void
    {
        $startCalls = 0;

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->exactly(2))
            ->method('start')
            ->willReturnCallback(static function (StartRunInput $input) use (&$startCalls): string {
                ++$startCalls;
                if (2 === $startCalls) {
                    throw new \RuntimeException('second child start blew up');
                }

                return (string) $input->runId;
            });
        $agentRunner->expects($this->once())
            ->method('cancel')
            ->with($this->callback(static fn (string $runId): bool => '' !== $runId), $this->anything());

        $runStore = $this->createStub(RunStoreInterface::class);
        $runStore->method('get')->willReturn(new RunState(
            runId: 'unused',
            status: RunStatus::Running,
            version: 1,
            messages: [],
        ));

        $def = static fn (string $name) => new AgentDefinitionDTO(
            name: $name,
            description: $name,
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'x',
            parallelAllowed: true,
        );

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $childRunDirectory = self::getContainer()->get(AgentChildRunDirectory::class);
        $service = $this->makeService([
            'catalog' => new AgentDefinitionCatalog([
                $def('first-agent'),
                $def('second-agent'),
                $def('third-agent'),
            ]),
            'agentRunner' => $agentRunner,
            'runStore' => $runStore,
            'childRunDirectory' => $childRunDirectory,
        ]);

        try {
            $service->executeParallel('parent-launch-fail', [
                new SubagentTaskDTO(agent: 'first-agent', task: 'ok'),
                new SubagentTaskDTO(agent: 'second-agent', task: 'boom'),
                new SubagentTaskDTO(agent: 'third-agent', task: 'never'),
            ]);
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('Parallel subagent launch failed', $e->getMessage());
            $this->assertStringContainsString('second child start blew up', $e->getMessage());
            $this->assertStringContainsString('Artifact:', $e->getMessage());
            $this->assertStringContainsString('first-agent', $e->getMessage());
            $this->assertStringContainsString('second-agent', $e->getMessage());
            $this->assertStringContainsString('third-agent', $e->getMessage());
            $this->assertStringContainsString('Child run was not launched after a parallel launch failure.', $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            $this->assertStringContainsString('second child start blew up', (string) $e->getPrevious()?->getMessage());

            if (!preg_match('/#3 third-agent — failed\s+Artifact: (agent_[0-9a-f]{16})/', $e->getMessage(), $matches)) {
                $this->fail('Expected third-agent failed artifact line in aggregate report');
            }
            $thirdArtifactId = $matches[1];
            $this->assertNull($registry->get('parent-launch-fail', $thirdArtifactId));

            $pathResolver = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathResolver::class);
            $this->assertDirectoryDoesNotExist($pathResolver->resolveArtifactDir('parent-launch-fail', $thirdArtifactId));
            $artifactDirs = glob($pathResolver->resolveArtifactsBasePath('parent-launch-fail').'/*', \GLOB_ONLYDIR) ?: [];
            $this->assertCount(2, $artifactDirs, 'Aborted never-launched child must not leave a reserved artifact directory.');
        }

        $entries = $registry->list('parent-launch-fail');
        $this->assertCount(2, $entries);
        foreach ($entries as $entry) {
            $this->assertNotSame(AgentArtifactStatusEnum::Running, $entry->status);
            $this->assertSame(AgentArtifactStatusEnum::Failed, $entry->status);
        }
    }

    public function testExecuteParallelPreparationFailurePreservesOriginAbortSemantics(): void
    {
        $parentRunStore = $this->createStub(RunStoreInterface::class);
        $parentRunStore->method('get')->willReturnCallback(static function (string $runId): RunState {
            static $calls = 0;
            ++$calls;
            if ($calls > 1) {
                throw new \RuntimeException('second child context blew up');
            }

            return new RunState(runId: $runId, status: RunStatus::Running, version: 1, messages: []);
        });

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->never())->method('start');
        $agentRunner->expects($this->never())->method('cancel');

        $def = static fn (string $name) => new AgentDefinitionDTO(
            name: $name,
            description: $name,
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'x',
            parallelAllowed: true,
        );

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $pathResolver = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathResolver::class);
        $service = $this->makeService([
            'catalog' => new AgentDefinitionCatalog([
                $def('first-agent'),
                $def('second-agent'),
                $def('third-agent'),
            ]),
            'parentRunStore' => $parentRunStore,
            'agentRunner' => $agentRunner,
        ]);

        try {
            $service->executeParallel('parent-prep-fail', [
                new SubagentTaskDTO(agent: 'first-agent', task: 'ok'),
                new SubagentTaskDTO(agent: 'second-agent', task: 'boom'),
                new SubagentTaskDTO(agent: 'third-agent', task: 'never'),
            ]);
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('Parallel subagent launch failed', $message);
            $this->assertStringContainsString('second child context blew up', $message);
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            $this->assertStringContainsString('second child context blew up', (string) $e->getPrevious()?->getMessage());

            $this->assertMatchesRegularExpression(
                '/#1 first-agent — failed\s+Artifact: agent_[0-9a-f]{16}\s+Cancelled after parallel launch failure\./s',
                $message,
            );
            $this->assertMatchesRegularExpression(
                '/#2 second-agent — failed\s+Artifact: agent_[0-9a-f]{16}\s+Child run failed to start\./s',
                $message,
            );
            $this->assertMatchesRegularExpression(
                '/#3 third-agent — failed\s+Artifact: agent_[0-9a-f]{16}\s+Child run was not launched after a parallel launch failure\./s',
                $message,
            );

            if (!preg_match('/#3 third-agent — failed\s+Artifact: (agent_[0-9a-f]{16})/', $message, $matches)) {
                $this->fail('Expected third-agent artifact line in aggregate report');
            }
            $thirdArtifactId = $matches[1];
            $this->assertNull($registry->get('parent-prep-fail', $thirdArtifactId));
            $this->assertDirectoryDoesNotExist($pathResolver->resolveArtifactDir('parent-prep-fail', $thirdArtifactId));
        }

        $entries = $registry->list('parent-prep-fail');
        $this->assertCount(2, $entries);
        foreach ($entries as $entry) {
            $this->assertSame(AgentArtifactStatusEnum::Failed, $entry->status);
            $this->assertSame('second child context blew up', $entry->failureReason);
        }
    }

    public function testParentCancellationParallelReportsLaunchedChildArtifacts(): void
    {
        $running = new RunState(runId: 'child-p1', status: RunStatus::Running, version: 1, turnNo: 1);
        $runStore = $this->createStub(RunStoreInterface::class);
        $runStore->method('get')->willReturn($running);

        $started = [];
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->method('start')->willReturnCallback(static function (StartRunInput $input) use (&$started): string {
            $started[] = $input->runId;

            return $input->runId;
        });
        $agentRunner->expects($this->atLeastOnce())->method('cancel');

        $def = static fn (string $name): AgentDefinitionDTO => new AgentDefinitionDTO(
            name: $name,
            description: $name,
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'x',
            parallelAllowed: true,
        );

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $contextAccessor = new StackToolExecutionContextAccessor();
        $cancelToken = new class implements \Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface {
            public function isCancellationRequested(): bool
            {
                return true;
            }
        };
        $toolContext = new ToolContext(
            runId: 'parent-cancel-parallel',
            turnNo: 2,
            toolCallId: 'tc-cancel-parallel',
            toolName: 'subagent',
            cancellationToken: $cancelToken,
            timeoutSeconds: 120,
        );

        $service = $this->makeService([
            'catalog' => new AgentDefinitionCatalog([$def('scout-a'), $def('scout-b')]),
            'agentRunner' => $agentRunner,
            'runStore' => $runStore,
            'contextAccessor' => $contextAccessor,
        ]);

        try {
            $contextAccessor->with($toolContext, static fn (): string => $service->executeParallel('parent-cancel-parallel', [
                new SubagentTaskDTO(agent: 'scout-a', task: 'A'),
                new SubagentTaskDTO(agent: 'scout-b', task: 'B'),
            ]));
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('Parallel subagent tool cancelled by parent run.', $e->getMessage());
            $this->assertStringContainsString('Artifact: agent_', $e->getMessage());
            $this->assertStringContainsString('cancelled', $e->getMessage());
            $this->assertStringContainsString('agent_retrieve', $e->getMessage());
        }

        $entries = $registry->list('parent-cancel-parallel');
        $this->assertCount(2, $entries);
        foreach ($entries as $entry) {
            $this->assertSame(AgentArtifactStatusEnum::Cancelled, $entry->status);
        }
    }

    public function testParallelProgressSignatureIncludesChildToolActivityWithinSameTurn(): void
    {
        $parentRunId = 'parent-parallel-signature';
        $childRunId = 'child-parallel-signature';
        $artifactId = 'agent_'.bin2hex(random_bytes(4));

        $projectDir = TestDirectoryIsolation::createOsTempDir('hatfield-parallel-signature');
        TestDirectoryIsolation::createHatfieldTree($projectDir, withSessions: true);
        try {
            $hatfieldSessionStore = new \Ineersa\CodingAgent\Session\HatfieldSessionStore(
                appConfig: new AppConfig(tui: new TuiConfig(theme: 'default'), logging: new LoggingConfig(), cwd: $projectDir),
                entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
            );
            $pathResolver = new \Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathResolver(new \Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver($hatfieldSessionStore));
            $childEventStore = new \Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStore(
                pathResolver: $pathResolver,
                eventPayloadNormalizer: new \Ineersa\AgentCore\Schema\EventPayloadNormalizer(),
                lockFactory: new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\FlockStore()),
                logger: new \Psr\Log\NullLogger(),
                sequenceAllocator: new FileRunSequenceAllocator(),
                parentRunId: $parentRunId,
                agentRunId: $childRunId,
                artifactId: $artifactId,
            );
            $childEventStore->append(new RunEvent($childRunId, 1, 0, RunEventTypeEnum::RunStarted->value, [
                'step_id' => 's0',
                'payload' => ['metadata' => ['model' => 'test/model']],
            ]));
            $childEventStore->append(new RunEvent($childRunId, 2, 1, RunEventTypeEnum::LlmStepCompleted->value, [
                'step_id' => 's1',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Starting.']],
                    'tool_calls' => [[
                        'id' => 'tc_read',
                        'name' => 'read',
                        'arguments' => ['path' => 'README.md'],
                    ]],
                ],
            ]));

            $readPendingState = new RunState(
                runId: $childRunId,
                status: RunStatus::Running,
                version: 1,
                turnNo: 1,
                lastSeq: 2,
                messages: [],
            );

            $bashPendingState = new RunState(
                runId: $childRunId,
                status: RunStatus::Running,
                version: 2,
                turnNo: 1,
                lastSeq: 4,
                messages: [],
            );

            $childFactory = new AgentChildRunEventStoreFactory(
                $pathResolver,
                new \Ineersa\AgentCore\Schema\EventPayloadNormalizer(),
                new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\FlockStore()),
                new \Psr\Log\NullLogger(),
                new FileRunSequenceAllocator(),
            );

            $runStore = $this->createStub(RunStoreInterface::class);
            $useBashState = false;
            $runStore->method('get')->willReturnCallback(static function (string $runId) use ($childRunId, $readPendingState, $bashPendingState, &$useBashState): ?RunState {
                if ($childRunId !== $runId) {
                    return null;
                }

                return $useBashState ? $bashPendingState : $readPendingState;
            });

            $reports = [
                $childRunId => [
                    'index' => 1,
                    'agentName' => 'parallel-scout',
                    'task' => 'Sleep then report',
                    'artifactId' => $artifactId,
                    'agentRunId' => $childRunId,
                    'terminal' => false,
                    'status' => null,
                    'message' => '',
                ],
            ];
            $activeTurns = [$childRunId => 1];

            $progressEmitter = new \Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Progress\SubagentChildRunProgressEmitter(
                self::getContainer()->get(StackToolExecutionContextAccessor::class),
                new SubagentProgressEventAppender(self::getContainer()->get(CommittedRunEventAppender::class)),
                new \Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder(),
                new SubagentChildProgressSummaryBuilder($childFactory),
                $runStore,
            );

            $method = new \ReflectionMethod(\Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Progress\SubagentChildRunProgressEmitter::class, 'parallelProgressSignature');
            $signatureRead = $method->invoke($progressEmitter, $parentRunId, $reports, $activeTurns);

            $useBashState = true;
            $childEventStore->append(new RunEvent($childRunId, 3, 1, RunEventTypeEnum::ToolExecutionEnd->value, [
                'tool_call_id' => 'tc_read',
                'tool_name' => 'read',
            ]));
            $childEventStore->append(new RunEvent($childRunId, 4, 1, RunEventTypeEnum::LlmStepCompleted->value, [
                'step_id' => 's2',
                'usage' => ['input_tokens' => 20, 'output_tokens' => 8, 'total_tokens' => 28],
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Running bash.']],
                    'tool_calls' => [[
                        'id' => 'tc_bash',
                        'name' => 'bash',
                        'arguments' => ['command' => 'sleep 120'],
                    ]],
                ],
            ]));

            $signatureBash = $method->invoke($progressEmitter, $parentRunId, $reports, $activeTurns);

            $this->assertNotSame($signatureRead, $signatureBash);
            $this->assertStringContainsString('bash: command="sleep 120"', $signatureBash);
            $this->assertStringNotContainsString('bash: command="sleep 120"', $signatureRead);
        } finally {
            TestDirectoryIsolation::removeDirectory($projectDir);
        }
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
            'launchProjectionRepository' => self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class),
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
