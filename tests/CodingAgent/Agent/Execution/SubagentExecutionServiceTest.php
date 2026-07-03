<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;

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
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\McpAgentModeEnum;
use Ineersa\CodingAgent\Agent\Definition\McpPolicyDTO;
use Ineersa\CodingAgent\Agent\Execution\AgentDepthGuard;
use Ineersa\CodingAgent\Agent\Execution\AgentPromptBuilder;
use Ineersa\CodingAgent\SystemPrompt\SystemPromptBuilder;
use Ineersa\CodingAgent\Agent\Execution\AgentMcpToolsResolver;
use Ineersa\CodingAgent\Agent\Execution\AgentToolPolicyResolver;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Ineersa\CodingAgent\Mcp\Config\McpConfigDTO;
use Ineersa\CodingAgent\Mcp\Config\McpConfigLoader;
use Ineersa\CodingAgent\Tests\Support\Mcp\TestMcpConfigLoaderFactory;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStoreFactory;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder;
use Ineersa\CodingAgent\Agent\Execution\SubagentExecutionService;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;
use Ineersa\CodingAgent\Agent\Execution\SubagentTaskDTO;
use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\Skills\SkillContextRenderer;
use Ineersa\CodingAgent\Skills\SkillDiscovery;
use Ineersa\CodingAgent\Skills\SkillsConfig;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SubagentExecutionService::class)]
final class SubagentExecutionServiceTest extends IsolatedKernelTestCase
{

    public function testExecuteWithOmittedToolsStoresInheritedAllowedToolsInChildMetadata(): void
    {
        $completedState = new RunState(
            runId: 'child-uuid',
            status: RunStatus::Completed,
            version: 1,
            messages: [],
        );

        $runStore = $this->createStub(RunStoreInterface::class);
        $runStore->method('get')->willReturn($completedState);
        $parentRunStore = $this->createStub(RunStoreInterface::class);

        $capturedInput = null;
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects(self::once())
            ->method('start')
            ->willReturnCallback(function (StartRunInput $input) use (&$capturedInput): string {
                $capturedInput = $input;

                return 'child-uuid';
            });

        $def = new AgentDefinitionDTO(
            name: 'worker-like',
            description: 'Worker agent',
            tools: null,
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'Worker instructions.',
        );

        $registryStub = $this->createStub(ToolRegistryInterface::class);
        $registryStub->method('activeToolNames')->willReturn(['read', 'bash', 'write', 'subagent']);

        $service = $this->makeService([
            'catalog' => new AgentDefinitionCatalog([$def]),
            'agentRunner' => $agentRunner,
            'runStore' => $runStore,
            'parentRunStore' => $parentRunStore,
            'policyResolver' => new AgentToolPolicyResolver($registryStub, $this->emptyMcpToolsResolver()),
        ]);

        $service->execute('parent-inherit-tools', 'worker-like', 'Do work');

        self::assertNotNull($capturedInput);
        $allowed = $capturedInput->metadata->toolsScope['allowed_tools'] ?? null;
        self::assertIsArray($allowed);
        self::assertContains('read', $allowed);
        self::assertContains('bash', $allowed);
        self::assertContains('write', $allowed);
        self::assertNotContains('subagent', $allowed);
    }

    public function testExecuteCompletesChildRunAndReturnsHandoff(): void
    {
        $completedState = new RunState(
            runId: 'child-uuid',
            status: RunStatus::Completed,
            version: 1,
            messages: [
                new AgentMessage(
                    role: 'assistant',
                    content: [['type' => 'text', 'text' => 'Handoff: found the issue in Foo.php.']],
                ),
            ],
        );

        $runStore = $this->createStub(RunStoreInterface::class);
        $runStore->method('get')->willReturn($completedState);

        $parentRunStore = $this->createStub(RunStoreInterface::class);

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $capturedInput = null;
        $agentRunner->expects(self::once())
            ->method('start')
            ->willReturnCallback(function (StartRunInput $input) use (&$capturedInput): string {
                $capturedInput = $input;

                return 'child-uuid';
            });

        $def = new AgentDefinitionDTO(
            name: 'test-agent',
            description: 'Test agent',
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'Test instructions.',
        );

        $catalog = new AgentDefinitionCatalog([$def]);

        $directory = self::getContainer()->get(AgentChildRunDirectory::class);
        $eventStore = $this->createStub(EventStoreInterface::class);
        $metadataReader = new SubagentRunMetadataReader($eventStore);

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);

        $service = new SubagentExecutionService(
            catalog: $catalog,
            depthGuard: new AgentDepthGuard(),
            policyResolver: $this->defaultPolicyResolver(),
            promptBuilder: new AgentPromptBuilder(self::getContainer()->get(SystemPromptBuilder::class)),
            skillsContextBuilder: self::getContainer()->get(SkillsContextBuilder::class),
            artifactRegistry: $registry,
            agentRunner: $agentRunner,
            runStore: $runStore,
            parentRunStore: $parentRunStore,
            eventStore: $eventStore,
            metadataReader: $metadataReader,
            childRunDirectory: $directory,
            contextAccessor: self::getContainer()->get(\Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor::class),
            logger: self::getContainer()->get('logger'),
            agentsConfig: new AgentsConfig(),
            progressSnapshotBuilder: new \Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder(),
            childProgressSummaryBuilder: new SubagentChildProgressSummaryBuilder(self::getContainer()->get(AgentChildRunEventStoreFactory::class)),
        );

        $result = $service->execute('parent-1', 'test-agent', 'Inspect Foo.php');

        self::assertStringContainsString('Handoff:', $result);
        self::assertStringContainsString('Subagent test-agent completed.', $result);
        self::assertMatchesRegularExpression('/Artifact: agent_[0-9a-f]{16}\n/', $result);

        // Verify system prompt was included as the first LLM-visible message.
        self::assertNotNull($capturedInput, 'AgentRunner::start() should have been called.');
        self::assertNotEmpty($capturedInput->messages, 'Child messages should not be empty.');
        self::assertSame('system', $capturedInput->messages[0]->role, 'First message should be the system prompt.');
        $systemText = $capturedInput->messages[0]->content[0]['text'] ?? '';
        self::assertStringContainsString('Test instructions.', $systemText);

        // Verify artifact was finalized — use list() not get() with
        // the result text as a faux artifactId.
        $entries = $registry->list('parent-1');
        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertSame(AgentArtifactStatusEnum::Completed, $entry->status);
        self::assertNotNull($entry->summary);
        self::assertStringContainsString('Handoff:', $entry->summary ?? '');
    }

    public function testFailedChildRunReturnsErrorMessage(): void
    {
        $failedState = new RunState(
            runId: 'child-failed',
            status: RunStatus::Failed,
            version: 1,
            errorMessage: 'Tool call failed: file not found',
            messages: [],
        );

        $runStore = $this->createStub(RunStoreInterface::class);
        $runStore->method('get')->willReturn($failedState);

        $parentRunStore = $this->createStub(RunStoreInterface::class);

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects(self::once())->method('start');

        $def = new AgentDefinitionDTO(
            name: 'fail-agent',
            description: 'Fail agent',
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'You fail.',
        );

        $catalog = new AgentDefinitionCatalog([$def]);
        $directory = self::getContainer()->get(AgentChildRunDirectory::class);
        $eventStore = $this->createStub(EventStoreInterface::class);
        $metadataReader = new SubagentRunMetadataReader($eventStore);
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);

        $service = new SubagentExecutionService(
            catalog: $catalog,
            depthGuard: new AgentDepthGuard(),
            policyResolver: $this->defaultPolicyResolver(),
            promptBuilder: new AgentPromptBuilder(self::getContainer()->get(SystemPromptBuilder::class)),
            skillsContextBuilder: self::getContainer()->get(SkillsContextBuilder::class),
            artifactRegistry: $registry,
            agentRunner: $agentRunner,
            runStore: $runStore,
            parentRunStore: $parentRunStore,
            eventStore: $eventStore,
            metadataReader: $metadataReader,
            childRunDirectory: $directory,
            contextAccessor: self::getContainer()->get(\Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor::class),
            logger: self::getContainer()->get('logger'),
            agentsConfig: new AgentsConfig(),
            progressSnapshotBuilder: new \Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder(),
            childProgressSummaryBuilder: new SubagentChildProgressSummaryBuilder(self::getContainer()->get(AgentChildRunEventStoreFactory::class)),
        );

        $result = $service->execute('parent-2', 'fail-agent', 'Try to read nothing');

        self::assertStringContainsString('failed', $result);
        self::assertStringContainsString('file not found', $result);

        // Verify artifact finalized as Failed.
        $entries = $registry->list('parent-2');
        self::assertCount(1, $entries);
        self::assertSame(AgentArtifactStatusEnum::Failed, $entries[0]->status);
        self::assertSame('Tool call failed: file not found', $entries[0]->failureReason);
    }

    public function testWaitingHumanKeepsPollingUntilChildCompletes(): void
    {
        $waitingState = new RunState(
            runId: 'child-waiting',
            status: RunStatus::WaitingHuman,
            version: 2,
            messages: [],
        );
        $completedState = new RunState(
            runId: 'child-waiting',
            status: RunStatus::Completed,
            version: 3,
            messages: [
                new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Done after answer.']]),
            ],
        );

        $runStore = $this->createStub(RunStoreInterface::class);
        $runStore->method('get')->willReturnOnConsecutiveCalls($waitingState, $waitingState, $completedState);

        $parentRunStore = $this->createStub(RunStoreInterface::class);
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects(self::once())->method('start');
        $agentRunner->expects(self::never())->method('cancel');

        $def = new AgentDefinitionDTO(
            name: 'asker',
            description: 'Asking agent',
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'Ask questions.',
        );

        $catalog = new AgentDefinitionCatalog([$def]);
        $directory = self::getContainer()->get(AgentChildRunDirectory::class);
        $eventStore = $this->createStub(EventStoreInterface::class);
        $metadataReader = new SubagentRunMetadataReader($eventStore);
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);

        $service = new SubagentExecutionService(
            catalog: $catalog,
            depthGuard: new AgentDepthGuard(),
            policyResolver: $this->defaultPolicyResolver(),
            promptBuilder: new AgentPromptBuilder(self::getContainer()->get(SystemPromptBuilder::class)),
            skillsContextBuilder: self::getContainer()->get(SkillsContextBuilder::class),
            artifactRegistry: $registry,
            agentRunner: $agentRunner,
            runStore: $runStore,
            parentRunStore: $parentRunStore,
            eventStore: $eventStore,
            metadataReader: $metadataReader,
            childRunDirectory: $directory,
            contextAccessor: self::getContainer()->get(\Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor::class),
            logger: self::getContainer()->get('logger'),
            agentsConfig: new AgentsConfig(),
            progressSnapshotBuilder: new \Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder(),
            childProgressSummaryBuilder: new SubagentChildProgressSummaryBuilder(self::getContainer()->get(AgentChildRunEventStoreFactory::class)),
        );

        $result = $service->execute('parent-waiting', 'asker', 'Need clarification');

        self::assertStringContainsString('Done after answer', $result);
        $entries = $registry->list('parent-waiting');
        self::assertCount(1, $entries);
        self::assertSame(AgentArtifactStatusEnum::Completed, $entries[0]->status);
    }

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
        $eventStore->expects(self::once())
            ->method('allFor')
            ->with('parent-child-run')
            ->willReturn([
                new \Ineersa\AgentCore\Domain\Event\RunEvent(
                    runId: 'parent-child-run',
                    seq: 1,
                    turnNo: 0,
                    type: \Ineersa\AgentCore\Domain\Event\RunEventTypeEnum::RunStarted->value,
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

        $service = new SubagentExecutionService(
            catalog: $catalog,
            depthGuard: new AgentDepthGuard(),
            policyResolver: $this->defaultPolicyResolver(),
            promptBuilder: new AgentPromptBuilder(self::getContainer()->get(SystemPromptBuilder::class)),
            skillsContextBuilder: self::getContainer()->get(SkillsContextBuilder::class),
            artifactRegistry: $registry,
            agentRunner: $agentRunner,
            runStore: $runStore,
            parentRunStore: $parentRunStore,
            eventStore: $eventStore,
            metadataReader: $metadataReader,
            childRunDirectory: $directory,
            contextAccessor: self::getContainer()->get(\Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor::class),
            logger: self::getContainer()->get('logger'),
            agentsConfig: new AgentsConfig(),
            progressSnapshotBuilder: new \Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder(),
            childProgressSummaryBuilder: new SubagentChildProgressSummaryBuilder(self::getContainer()->get(AgentChildRunEventStoreFactory::class)),
        );

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

        $service = new SubagentExecutionService(
            catalog: $catalog,
            depthGuard: new AgentDepthGuard(),
            policyResolver: $this->defaultPolicyResolver(),
            promptBuilder: new AgentPromptBuilder(self::getContainer()->get(SystemPromptBuilder::class)),
            skillsContextBuilder: self::getContainer()->get(SkillsContextBuilder::class),
            artifactRegistry: $registry,
            agentRunner: $this->createStub(AgentRunnerInterface::class),
            runStore: $this->createStub(RunStoreInterface::class),
            parentRunStore: $this->createStub(RunStoreInterface::class),
            eventStore: $eventStore,
            metadataReader: new SubagentRunMetadataReader($eventStore),
            childRunDirectory: $directory,
            contextAccessor: self::getContainer()->get(\Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor::class),
            logger: self::getContainer()->get('logger'),
            agentsConfig: new AgentsConfig(),
            progressSnapshotBuilder: new \Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder(),
            childProgressSummaryBuilder: new SubagentChildProgressSummaryBuilder(self::getContainer()->get(AgentChildRunEventStoreFactory::class)),
        );

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

        $service = new SubagentExecutionService(
            catalog: $catalog,
            depthGuard: new AgentDepthGuard(),
            policyResolver: $this->defaultPolicyResolver(),
            promptBuilder: new AgentPromptBuilder(self::getContainer()->get(SystemPromptBuilder::class)),
            skillsContextBuilder: self::getContainer()->get(SkillsContextBuilder::class),
            artifactRegistry: $registry,
            agentRunner: $this->createStub(AgentRunnerInterface::class),
            runStore: $this->createStub(RunStoreInterface::class),
            parentRunStore: $this->createStub(RunStoreInterface::class),
            eventStore: $eventStore,
            metadataReader: new SubagentRunMetadataReader($eventStore),
            childRunDirectory: $directory,
            contextAccessor: self::getContainer()->get(\Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor::class),
            logger: self::getContainer()->get('logger'),
            agentsConfig: new AgentsConfig(),
            progressSnapshotBuilder: new \Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder(),
            childProgressSummaryBuilder: new SubagentChildProgressSummaryBuilder(self::getContainer()->get(AgentChildRunEventStoreFactory::class)),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('does not allow foreground');

        $service->execute('parent-5', 'background-only', 'Task');
    }

    /**
     * Prove that parent RunState.lastSeq is advanced after progress events
     * via compareAndSwap, preventing sequence collisions with later
     * ToolCallResultHandler-generated events.
     */
    public function testProgressUpdatesAdvanceParentSequence(): void
    {
        // Use real InMemoryRunStore so compareAndSwap works.
        $parentRunStore = new InMemoryRunStore();

        // Seed parent state so resolveNextProgressSeq has a starting point.
        $parentState = new RunState(
            runId: 'parent-seq',
            status: RunStatus::Running,
            version: 3,
            lastSeq: 5,
            messages: [],
        );
        $parentRunStore->compareAndSwap($parentState, 0);

        // Child polls: first Running, then Completed.
        $getCount = 0;
        $runningState = new RunState(
            runId: 'child-seq',
            status: RunStatus::Running,
            version: 1,
            turnNo: 2,
            messages: [],
        );
        $completedState = new RunState(
            runId: 'child-seq',
            status: RunStatus::Completed,
            version: 2,
            messages: [
                new AgentMessage(
                    role: 'assistant',
                    content: [['type' => 'text', 'text' => 'done']],
                ),
            ],
        );

        $runStore = $this->createStub(RunStoreInterface::class);
        $runStore->method('get')->willReturnCallback(
            function () use (&$getCount, $runningState, $completedState): ?RunState {
                $state = 0 === $getCount ? $runningState : $completedState;
                ++$getCount;

                return $state;
            },
        );

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects(self::once())
            ->method('start')
            ->willReturn('child-seq');

        $def = new AgentDefinitionDTO(
            name: 'seq-agent',
            description: 'Seq agent',
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'Seq test.',
        );

        $catalog = new AgentDefinitionCatalog([$def]);
        $directory = self::getContainer()->get(AgentChildRunDirectory::class);

        // Collecting event store that tracks appended events per runId.
        $appendedEvents = [];
        $eventStore = $this->createStub(EventStoreInterface::class);
        $eventStore->method('append')
            ->willReturnCallback(function (RunEvent $event) use (&$appendedEvents): void {
                $appendedEvents[] = $event;
            });
        $eventStore->method('allFor')
            ->willReturnCallback(function (string $runId) use (&$appendedEvents): array {
                return array_values(array_filter(
                    $appendedEvents,
                    fn(RunEvent $e): bool => $e->runId === $runId,
                ));
            });

        $metadataReader = new SubagentRunMetadataReader($eventStore);
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);

        // Push a ToolContext so emitProgressUpdate has an active context.
        $contextAccessor = new StackToolExecutionContextAccessor();
        $toolContext = new ToolContext(
            runId: 'parent-seq',
            turnNo: 0,
            toolCallId: 'tc-seq',
            toolName: 'subagent',
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 120,
        );

        $service = new SubagentExecutionService(
            catalog: $catalog,
            depthGuard: new AgentDepthGuard(),
            policyResolver: $this->defaultPolicyResolver(),
            promptBuilder: new AgentPromptBuilder(self::getContainer()->get(SystemPromptBuilder::class)),
            skillsContextBuilder: self::getContainer()->get(SkillsContextBuilder::class),
            artifactRegistry: $registry,
            agentRunner: $agentRunner,
            runStore: $runStore,
            parentRunStore: $parentRunStore,
            eventStore: $eventStore,
            metadataReader: $metadataReader,
            childRunDirectory: $directory,
            contextAccessor: $contextAccessor,
            logger: self::getContainer()->get('logger'),
            agentsConfig: new AgentsConfig(),
            progressSnapshotBuilder: new \Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder(),
            childProgressSummaryBuilder: new SubagentChildProgressSummaryBuilder(self::getContainer()->get(AgentChildRunEventStoreFactory::class)),
        );

        $result = $contextAccessor->with($toolContext, function () use ($service): string {
            return $service->execute('parent-seq', 'seq-agent', 'Do work');
        });

        self::assertStringContainsString('done', $result);

        // Parent lastSeq should have advanced past the initial seed.
        $finalParentState = $parentRunStore->get('parent-seq');
        self::assertNotNull($finalParentState);
        self::assertGreaterThan(
            5,
            $finalParentState->lastSeq,
            'Parent lastSeq should advance past initial seed (5) after progress events.',
        );

        // At least one progress event should have been emitted.
        $progressEvents = array_filter(
            $appendedEvents,
            fn(RunEvent $e): bool => RunEventTypeEnum::ToolExecutionUpdate->value === $e->type,
        );
        self::assertNotEmpty($progressEvents, 'At least one progress event should be emitted.');

        // Progress events should have unique sequences.
        $progressSeqs = array_map(fn(RunEvent $e): int => $e->seq, $progressEvents);
        self::assertSame(
            count($progressEvents),
            count(array_unique($progressSeqs)),
            'Progress events should have unique sequence numbers.',
        );
    }

    public function testCompactingChildRunTreatedAsActiveAndCompletes(): void
    {
        $parentRunStore = new InMemoryRunStore();
        $parentState = new RunState(
            runId: 'parent-compact',
            status: RunStatus::Running,
            version: 1,
            lastSeq: 2,
            messages: [],
        );
        $parentRunStore->compareAndSwap($parentState, 0);

        $getCount = 0;
        $compactingState = new RunState(
            runId: 'child-compact',
            status: RunStatus::Compacting,
            version: 1,
            turnNo: 1,
            messages: [],
        );
        $completedState = new RunState(
            runId: 'child-compact',
            status: RunStatus::Completed,
            version: 2,
            messages: [
                new AgentMessage(
                    role: 'assistant',
                    content: [['type' => 'text', 'text' => 'Handoff: compaction finished.']],
                ),
            ],
        );

        $runStore = $this->createStub(RunStoreInterface::class);
        $runStore->method('get')->willReturnCallback(
            function () use (&$getCount, $compactingState, $completedState): ?RunState {
                $state = 0 === $getCount ? $compactingState : $completedState;
                ++$getCount;

                return $state;
            },
        );

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects(self::once())
            ->method('start')
            ->willReturn('child-compact');

        $def = new AgentDefinitionDTO(
            name: 'compact-agent',
            description: 'Compact agent',
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'Compact test.',
        );

        $catalog = new AgentDefinitionCatalog([$def]);
        $directory = self::getContainer()->get(AgentChildRunDirectory::class);
        $eventStore = $this->createStub(EventStoreInterface::class);
        $metadataReader = new SubagentRunMetadataReader($eventStore);
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);

        $service = new SubagentExecutionService(
            catalog: $catalog,
            depthGuard: new AgentDepthGuard(),
            policyResolver: $this->defaultPolicyResolver(),
            promptBuilder: new AgentPromptBuilder(self::getContainer()->get(SystemPromptBuilder::class)),
            skillsContextBuilder: self::getContainer()->get(SkillsContextBuilder::class),
            artifactRegistry: $registry,
            agentRunner: $agentRunner,
            runStore: $runStore,
            parentRunStore: $parentRunStore,
            eventStore: $eventStore,
            metadataReader: $metadataReader,
            childRunDirectory: $directory,
            contextAccessor: self::getContainer()->get(StackToolExecutionContextAccessor::class),
            logger: self::getContainer()->get('logger'),
            agentsConfig: new AgentsConfig(),
            progressSnapshotBuilder: new \Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder(),
            childProgressSummaryBuilder: new SubagentChildProgressSummaryBuilder(self::getContainer()->get(AgentChildRunEventStoreFactory::class)),
        );

        $result = $service->execute('parent-compact', 'compact-agent', 'Compact then finish');

        self::assertStringContainsString('Handoff: compaction finished.', $result);
        self::assertStringContainsString('Artifact:', $result);

        $entries = $registry->list('parent-compact');
        self::assertCount(1, $entries);
        self::assertSame(AgentArtifactStatusEnum::Completed, $entries[0]->status);
    }
    public function testExecuteParallelCompletesDistinctArtifacts(): void
    {
        /** @var array<string, string> $handoffByRunId */
        $handoffByRunId = [];

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects(self::exactly(2))
            ->method('start')
            ->willReturnCallback(function (StartRunInput $input) use (&$handoffByRunId): string {
                $agentName = (string) ($input->metadata?->session['agent_name'] ?? 'unknown');
                $handoffByRunId[(string) $input->runId] = 'parallel-a' === $agentName ? 'A_OK' : 'B_OK';

                return (string) $input->runId;
            });

        $runStore = $this->createStub(RunStoreInterface::class);
        $runStore->method('get')->willReturnCallback(function (string $runId) use (&$handoffByRunId): ?RunState {
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

        $def = fn (string $name) => new AgentDefinitionDTO(
            name: $name,
            description: $name,
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'Do task.',
            parallelAllowed: true,
        );

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $service = $this->makeService([
            'catalog' => new AgentDefinitionCatalog([$def('parallel-a'), $def('parallel-b')]),
            'agentRunner' => $agentRunner,
            'runStore' => $runStore,
        ]);

        $result = $service->executeParallel('parent-parallel-ok', [
            new SubagentTaskDTO(agent: 'parallel-a', task: 'Task A'),
            new SubagentTaskDTO(agent: 'parallel-b', task: 'Task B'),
        ]);

        self::assertStringContainsString('Parallel subagents completed', $result);
        self::assertStringContainsString('A_OK', $result);
        self::assertStringContainsString('B_OK', $result);
        self::assertMatchesRegularExpression('/Artifact: agent_[0-9a-f]{16}/', $result);

        $entries = $registry->list('parent-parallel-ok');
        self::assertCount(2, $entries);
        foreach ($entries as $entry) {
            self::assertSame(AgentArtifactStatusEnum::Completed, $entry->status);
        }
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
        $agentRunner->expects(self::never())->method('start');

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
            self::assertCount(0, $registry->list('parent-cap'));
        }
    }

    public function testExecuteParallelPartialFailureThrowsWithArtifactReport(): void
    {
        /** @var array<string, RunStatus> $statusByRunId */
        $statusByRunId = [];

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects(self::exactly(2))
            ->method('start')
            ->willReturnCallback(function (StartRunInput $input) use (&$statusByRunId): string {
                $agentName = (string) ($input->metadata?->session['agent_name'] ?? '');
                $statusByRunId[(string) $input->runId] = 'ok-agent' === $agentName ? RunStatus::Completed : RunStatus::Failed;

                return (string) $input->runId;
            });

        $runStore = $this->createStub(RunStoreInterface::class);
        $runStore->method('get')->willReturnCallback(function (string $runId) use (&$statusByRunId): ?RunState {
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

        $def = fn (string $name) => new AgentDefinitionDTO(
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
            self::fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('failed for one or more children', $e->getMessage());
            self::assertStringContainsString('Artifact:', $e->getMessage());
            self::assertStringContainsString('boom', $e->getMessage());
            self::assertStringContainsString('OK_HANDOFF', $e->getMessage());
        }

        $entries = $registry->list('parent-partial');
        self::assertCount(2, $entries);
        $statuses = array_map(static fn ($e) => $e->status, $entries);
        self::assertContains(AgentArtifactStatusEnum::Completed, $statuses);
        self::assertContains(AgentArtifactStatusEnum::Failed, $statuses);
    }

    public function testExecuteParallelStartFailureCleansUpStartedChildren(): void
    {
        $startCalls = 0;

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects(self::exactly(2))
            ->method('start')
            ->willReturnCallback(function (StartRunInput $input) use (&$startCalls): string {
                ++$startCalls;
                if (2 === $startCalls) {
                    throw new \RuntimeException('second child start blew up');
                }

                return (string) $input->runId;
            });
        $agentRunner->expects(self::once())
            ->method('cancel')
            ->with(self::callback(fn (string $runId): bool => '' !== $runId), self::anything());

        $runStore = $this->createStub(RunStoreInterface::class);
        $runStore->method('get')->willReturn(new RunState(
            runId: 'unused',
            status: RunStatus::Running,
            version: 1,
            messages: [],
        ));

        $def = fn (string $name) => new AgentDefinitionDTO(
            name: $name,
            description: $name,
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'x',
            parallelAllowed: true,
        );

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $service = $this->makeService([
            'catalog' => new AgentDefinitionCatalog([
                $def('first-agent'),
                $def('second-agent'),
                $def('third-agent'),
            ]),
            'agentRunner' => $agentRunner,
            'runStore' => $runStore,
        ]);

        try {
            $service->executeParallel('parent-launch-fail', [
                new SubagentTaskDTO(agent: 'first-agent', task: 'ok'),
                new SubagentTaskDTO(agent: 'second-agent', task: 'boom'),
                new SubagentTaskDTO(agent: 'third-agent', task: 'never'),
            ]);
            self::fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('Parallel subagent launch failed', $e->getMessage());
            self::assertStringContainsString('second child start blew up', $e->getMessage());
            self::assertStringContainsString('Artifact:', $e->getMessage());
            self::assertStringContainsString('first-agent', $e->getMessage());
            self::assertStringContainsString('second-agent', $e->getMessage());
            self::assertStringContainsString('third-agent', $e->getMessage());
            self::assertStringContainsString('Child run was not launched after a parallel launch failure.', $e->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            self::assertStringContainsString('second child start blew up', (string) $e->getPrevious()?->getMessage());

            if (!preg_match('/#3 third-agent — failed\s+Artifact: (agent_[0-9a-f]{16})/', $e->getMessage(), $matches)) {
                self::fail('Expected third-agent failed artifact line in aggregate report');
            }
            $thirdArtifactId = $matches[1];
            self::assertNull($registry->get('parent-launch-fail', $thirdArtifactId));
        }

        $entries = $registry->list('parent-launch-fail');
        self::assertCount(2, $entries);
        foreach ($entries as $entry) {
            self::assertNotSame(AgentArtifactStatusEnum::Running, $entry->status);
            self::assertSame(AgentArtifactStatusEnum::Failed, $entry->status);
        }
    }


    public function testExecuteInjectsPreloadedSkillContentFromDefinition(): void
    {
        $tmpDir = sys_get_temp_dir().'/subagent_skill_'.bin2hex(random_bytes(6));
        mkdir($tmpDir.'/.hatfield/skills/child-skill', 0777, true);
        file_put_contents(
            $tmpDir.'/.hatfield/skills/child-skill/SKILL.md',
            "---
name: child-skill
description: Child skill
---

CHILD_SKILL_BODY_UNIQUE",
        );

        $completedState = new RunState(runId: 'child-uuid', status: RunStatus::Completed, version: 1, messages: []);
        $runStore = $this->createStub(RunStoreInterface::class);
        $runStore->method('get')->willReturn($completedState);
        $parentRunStore = $this->createStub(RunStoreInterface::class);

        $capturedInput = null;
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects(self::once())->method('start')->willReturnCallback(function (StartRunInput $input) use (&$capturedInput): string {
            $capturedInput = $input;
            return 'child-uuid';
        });

        $def = new AgentDefinitionDTO(
            name: 'skill-agent',
            description: 'd',
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            skills: ['child-skill'],
            instructions: 'Go.',
        );

        $skillsBuilder = $this->makeSkillsContextBuilder($tmpDir);

        $service = $this->makeService([
            'catalog' => new AgentDefinitionCatalog([$def]),
            'agentRunner' => $agentRunner,
            'runStore' => $runStore,
            'parentRunStore' => $parentRunStore,
            'skillsContextBuilder' => $skillsBuilder,
        ]);

        try {
            $service->execute('parent-skill', 'skill-agent', 'Task');
        } finally {
            $this->rmdirRecursive($tmpDir);
        }

        self::assertNotNull($capturedInput);
        $found = false;
        foreach ($capturedInput->messages as $message) {
            if ('user-context' !== $message->role) {
                continue;
            }
            if ('skills_context' !== ($message->metadata['source'] ?? null)) {
                continue;
            }
            $text = (string) ($message->content[0]['text'] ?? '');
            self::assertStringContainsString('CHILD_SKILL_BODY_UNIQUE', $text);
            $found = true;
        }
        self::assertTrue($found, 'Expected skills_context message with preloaded body');
    }

    public function testExecuteHonorsInheritAgentsMdFalse(): void
    {
        $parentState = new RunState(
            runId: 'parent-run',
            status: RunStatus::Running,
            version: 1,
            messages: [
                new AgentMessage(
                    role: 'user-context',
                    content: [['type' => 'text', 'text' => '<project_context>SHOULD_NOT_APPEAR</project_context>']],
                    metadata: ['source' => 'agents_context'],
                ),
            ],
        );

        $completedState = new RunState(runId: 'child-uuid', status: RunStatus::Completed, version: 1, messages: []);
        $runStore = $this->createStub(RunStoreInterface::class);
        $runStore->method('get')->willReturn($completedState);
        $parentRunStore = $this->createStub(RunStoreInterface::class);
        $parentRunStore->method('get')->willReturn($parentState);

        $capturedInput = null;
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects(self::once())->method('start')->willReturnCallback(function (StartRunInput $input) use (&$capturedInput): string {
            $capturedInput = $input;
            return 'child-uuid';
        });

        $def = new AgentDefinitionDTO(
            name: 'no-agents',
            description: 'd',
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            inheritAgentsMd: false,
            inheritProjectContext: false,
            instructions: 'Only instructions.',
        );

        $service = $this->makeService([
            'catalog' => new AgentDefinitionCatalog([$def]),
            'agentRunner' => $agentRunner,
            'runStore' => $runStore,
            'parentRunStore' => $parentRunStore,
        ]);

        $service->execute('parent-run', 'no-agents', 'Task');

        self::assertNotNull($capturedInput);
        self::assertStringNotContainsString('SHOULD_NOT_APPEAR', $capturedInput->systemPrompt);
    }

    public function testExecuteIncludesParentAgentsContextWhenInheritTrue(): void
    {
        $parentState = new RunState(
            runId: 'parent-run2',
            status: RunStatus::Running,
            version: 1,
            messages: [
                new AgentMessage(
                    role: 'user-context',
                    content: [['type' => 'text', 'text' => '<project_context>AGENTS_INHERIT_OK</project_context>']],
                    metadata: ['source' => 'agents_context'],
                ),
            ],
        );

        $completedState = new RunState(runId: 'child-uuid', status: RunStatus::Completed, version: 1, messages: []);
        $runStore = $this->createStub(RunStoreInterface::class);
        $runStore->method('get')->willReturn($completedState);
        $parentRunStore = $this->createStub(RunStoreInterface::class);
        $parentRunStore->method('get')->willReturn($parentState);

        $capturedInput = null;
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects(self::once())->method('start')->willReturnCallback(function (StartRunInput $input) use (&$capturedInput): string {
            $capturedInput = $input;
            return 'child-uuid';
        });

        $def = new AgentDefinitionDTO(
            name: 'inherit-agents',
            description: 'd',
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            inheritAgentsMd: true,
            inheritProjectContext: true,
            instructions: 'Child.',
        );

        $service = $this->makeService([
            'catalog' => new AgentDefinitionCatalog([$def]),
            'agentRunner' => $agentRunner,
            'runStore' => $runStore,
            'parentRunStore' => $parentRunStore,
        ]);

        $service->execute('parent-run2', 'inherit-agents', 'Task');

        self::assertNotNull($capturedInput);
        self::assertStringContainsString('AGENTS_INHERIT_OK', $capturedInput->systemPrompt);
    }

    /**
     * @param array<string, mixed> $overrides
     */

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


    public function testParentCancellationSingleSubagentThrowsRichMessageAndWritesCancelledHandoff(): void
    {
        $runningState = new RunState(
            runId: 'child-cancel-1',
            status: RunStatus::Running,
            version: 1,
            turnNo: 3,
            lastSeq: 12,
            messages: [
                new AgentMessage(
                    role: 'assistant',
                    content: [['type' => 'text', 'text' => 'Partial scout findings before cancel.']],
                ),
            ],
            pendingToolCalls: ['tc-read-1' => true],
        );

        $runStore = $this->createStub(RunStoreInterface::class);
        $runStore->method('get')->willReturn($runningState);

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects(self::once())
            ->method('start')
            ->willReturnCallback(static function (StartRunInput $input): string {
                return $input->runId;
            });
        $agentRunner->expects(self::once())
            ->method('cancel')
            ->with(self::anything(), 'Parent run cancelled subagent tool.');

        $def = new AgentDefinitionDTO(
            name: 'scout',
            description: 'Scout',
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'Scout instructions.',
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
            runId: 'parent-cancel-single',
            turnNo: 1,
            toolCallId: 'tc-cancel-single',
            toolName: 'subagent',
            cancellationToken: $cancelToken,
            timeoutSeconds: 120,
        );

        $appendedEvents = [];
        $eventStore = $this->createStub(EventStoreInterface::class);
        $eventStore->method('append')
            ->willReturnCallback(function (RunEvent $event) use (&$appendedEvents): void {
                $appendedEvents[] = $event;
            });
        $eventStore->method('allFor')
            ->willReturnCallback(static function (string $runId) use (&$appendedEvents): array {
                return array_values(array_filter(
                    $appendedEvents,
                    static fn (RunEvent $e): bool => $e->runId === $runId,
                ));
            });

        $service = $this->makeService([
            'catalog' => new AgentDefinitionCatalog([$def]),
            'agentRunner' => $agentRunner,
            'runStore' => $runStore,
            'contextAccessor' => $contextAccessor,
            'eventStore' => $eventStore,
        ]);

        try {
            $contextAccessor->with($toolContext, fn (): string => $service->execute('parent-cancel-single', 'scout', 'Inspect docs'));
            self::fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('Subagent scout cancelled by parent run.', $e->getMessage());
            self::assertStringContainsString('Artifact: agent_', $e->getMessage());
            self::assertStringContainsString('Status: cancelled', $e->getMessage());
            self::assertStringContainsString('agent_retrieve', $e->getMessage());
        }

        $entries = $registry->list('parent-cancel-single');
        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertSame(AgentArtifactStatusEnum::Cancelled, $entry->status);
        $handoff = $registry->readHandoff('parent-cancel-single', $entry->artifactId);
        self::assertStringContainsString('Status: cancelled', $handoff);
        self::assertStringContainsString('## Partial context', $handoff);
        self::assertStringContainsString('turn_no: 3', $handoff);
        self::assertStringContainsString('Partial scout findings before cancel.', $handoff);
        $progressEvents = array_values(array_filter(
            $appendedEvents,
            static fn (RunEvent $e): bool => RunEventTypeEnum::ToolExecutionUpdate->value === $e->type,
        ));
        self::assertCount(1, $progressEvents);
        self::assertSame('cancelled', $progressEvents[0]->payload['subagent_progress']['status'] ?? null);
    }

    public function testChildRunCancelledStatusIncludesPartialContextInHandoff(): void
    {
        $cancelledState = new RunState(
            runId: 'child-cancelled-2',
            status: RunStatus::Cancelled,
            version: 2,
            turnNo: 5,
            lastSeq: 40,
            messages: [
                new AgentMessage(
                    role: 'assistant',
                    content: [['type' => 'text', 'text' => 'Last committed assistant line.']],
                ),
            ],
        );

        $runStore = $this->createStub(RunStoreInterface::class);
        $runStore->method('get')->willReturn($cancelledState);

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects(self::once())->method('start')->willReturn('child-cancelled-2');

        $def = new AgentDefinitionDTO(
            name: 'worker',
            description: 'Worker',
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'Worker.',
        );

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $service = $this->makeService([
            'catalog' => new AgentDefinitionCatalog([$def]),
            'agentRunner' => $agentRunner,
            'runStore' => $runStore,
        ]);

        $result = $service->execute('parent-child-cancel', 'worker', 'Do task');
        self::assertStringContainsString('Subagent worker was cancelled.', $result);
        self::assertStringContainsString('Status: cancelled', $result);

        $entries = $registry->list('parent-child-cancel');
        self::assertCount(1, $entries);
        $handoff = $registry->readHandoff('parent-child-cancel', $entries[0]->artifactId);
        self::assertStringContainsString('Last committed assistant line.', $handoff);
        self::assertStringContainsString('message_count: 1', $handoff);
    }

    public function testParentCancellationParallelReportsLaunchedChildArtifacts(): void
    {
        $running = new RunState(runId: 'child-p1', status: RunStatus::Running, version: 1, turnNo: 1);
        $runStore = $this->createStub(RunStoreInterface::class);
        $runStore->method('get')->willReturn($running);

        $started = [];
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->method('start')->willReturnCallback(function (StartRunInput $input) use (&$started): string {
            $started[] = $input->runId;

            return $input->runId;
        });
        $agentRunner->expects(self::atLeastOnce())->method('cancel');

        $def = fn (string $name): AgentDefinitionDTO => new AgentDefinitionDTO(
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
            $contextAccessor->with($toolContext, fn (): string => $service->executeParallel('parent-cancel-parallel', [
                new SubagentTaskDTO(agent: 'scout-a', task: 'A'),
                new SubagentTaskDTO(agent: 'scout-b', task: 'B'),
            ]));
            self::fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('Parallel subagent tool cancelled by parent run.', $e->getMessage());
            self::assertStringContainsString('Artifact: agent_', $e->getMessage());
            self::assertStringContainsString('cancelled', $e->getMessage());
            self::assertStringContainsString('agent_retrieve', $e->getMessage());
        }

        $entries = $registry->list('parent-cancel-parallel');
        self::assertCount(2, $entries);
        foreach ($entries as $entry) {
            self::assertSame(AgentArtifactStatusEnum::Cancelled, $entry->status);
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
            $pathResolver = new \Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathResolver($hatfieldSessionStore);
            $childEventStore = new \Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStore(
                pathResolver: $pathResolver,
                eventPayloadNormalizer: new \Ineersa\AgentCore\Schema\EventPayloadNormalizer(),
                lockFactory: new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\FlockStore()),
                logger: new \Psr\Log\NullLogger(),
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
            );

            $runStore = $this->createStub(RunStoreInterface::class);
            $useBashState = false;
            $runStore->method('get')->willReturnCallback(function (string $runId) use ($childRunId, $readPendingState, $bashPendingState, &$useBashState): ?RunState {
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

            $service = $this->makeService([
                'runStore' => $runStore,
                'childProgressSummaryBuilder' => new SubagentChildProgressSummaryBuilder($childFactory),
            ]);

            $method = new \ReflectionMethod(SubagentExecutionService::class, 'parallelProgressSignature');
            $signatureRead = $method->invoke($service, $parentRunId, $reports, $activeTurns);

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

            $signatureBash = $method->invoke($service, $parentRunId, $reports, $activeTurns);

            self::assertNotSame($signatureRead, $signatureBash);
            self::assertStringContainsString('bash: command="sleep 120"', $signatureBash);
            self::assertStringNotContainsString('bash: command="sleep 120"', $signatureRead);
        } finally {
            TestDirectoryIsolation::removeDirectory($projectDir);
        }
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
            'runStore' => $this->createStub(RunStoreInterface::class),
            'parentRunStore' => $this->createStub(RunStoreInterface::class),
            'eventStore' => $this->createStub(EventStoreInterface::class),
            'metadataReader' => new SubagentRunMetadataReader($this->createStub(EventStoreInterface::class)),
            'childRunDirectory' => self::getContainer()->get(AgentChildRunDirectory::class),
            'contextAccessor' => self::getContainer()->get(StackToolExecutionContextAccessor::class),
            'logger' => self::getContainer()->get('logger'),
            'agentsConfig' => new AgentsConfig(maxAgents: 8),
            'progressSnapshotBuilder' => new \Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder(),
            'childProgressSummaryBuilder' => new SubagentChildProgressSummaryBuilder(self::getContainer()->get(AgentChildRunEventStoreFactory::class)),
        ];

        $args = array_merge($defaults, $overrides);

        return new SubagentExecutionService(
            catalog: $args['catalog'],
            depthGuard: $args['depthGuard'],
            policyResolver: $args['policyResolver'],
            promptBuilder: $args['promptBuilder'],
            skillsContextBuilder: $args['skillsContextBuilder'],
            artifactRegistry: $args['artifactRegistry'],
            agentRunner: $args['agentRunner'],
            runStore: $args['runStore'],
            parentRunStore: $args['parentRunStore'],
            eventStore: $args['eventStore'],
            metadataReader: $args['metadataReader'],
            childRunDirectory: $args['childRunDirectory'],
            contextAccessor: $args['contextAccessor'],
            logger: $args['logger'],
            agentsConfig: $args['agentsConfig'],
            progressSnapshotBuilder: $args['progressSnapshotBuilder'],
            childProgressSummaryBuilder: $args['childProgressSummaryBuilder'],
        );
    }

}
