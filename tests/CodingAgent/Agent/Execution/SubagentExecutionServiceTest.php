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
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\McpAgentModeEnum;
use Ineersa\CodingAgent\Agent\Definition\McpPolicyDTO;
use Ineersa\CodingAgent\Agent\Execution\AgentDepthGuard;
use Ineersa\CodingAgent\Agent\Execution\AgentPromptBuilder;
use Ineersa\CodingAgent\Agent\Execution\AgentToolPolicyResolver;
use Ineersa\CodingAgent\Agent\Execution\SubagentExecutionService;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SubagentExecutionService::class)]
final class SubagentExecutionServiceTest extends IsolatedKernelTestCase
{
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
            policyResolver: new AgentToolPolicyResolver(),
            promptBuilder: new AgentPromptBuilder(),
            artifactRegistry: $registry,
            agentRunner: $agentRunner,
            runStore: $runStore,
            parentRunStore: $parentRunStore,
            eventStore: $eventStore,
            metadataReader: $metadataReader,
            childRunDirectory: $directory,
            contextAccessor: self::getContainer()->get(\Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor::class),
            logger: self::getContainer()->get('logger'),
        );

        $result = $service->execute('parent-1', 'test-agent', 'Inspect Foo.php');

        self::assertStringContainsString('Handoff:', $result);

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
            policyResolver: new AgentToolPolicyResolver(),
            promptBuilder: new AgentPromptBuilder(),
            artifactRegistry: $registry,
            agentRunner: $agentRunner,
            runStore: $runStore,
            parentRunStore: $parentRunStore,
            eventStore: $eventStore,
            metadataReader: $metadataReader,
            childRunDirectory: $directory,
            contextAccessor: self::getContainer()->get(\Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor::class),
            logger: self::getContainer()->get('logger'),
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

    public function testWaitingHumanFinalizesAsFailedUnsupportedInteraction(): void
    {
        $waitingState = new RunState(
            runId: 'child-waiting',
            status: RunStatus::WaitingHuman,
            version: 2,
            messages: [
                new AgentMessage(
                    role: 'assistant',
                    content: [
                        ['type' => 'text', 'text' => 'Would you like me to delete Foo.php?'],
                    ],
                ),
            ],
        );

        $runStore = $this->createStub(RunStoreInterface::class);
        $runStore->method('get')->willReturn($waitingState);

        $parentRunStore = $this->createStub(RunStoreInterface::class);

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects(self::once())->method('start');
        $agentRunner->expects(self::once())->method('cancel')
            ->with(
                self::callback(fn (mixed $id): bool => \is_string($id)),
                self::stringContains('WaitingHuman'),
            );

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
            policyResolver: new AgentToolPolicyResolver(),
            promptBuilder: new AgentPromptBuilder(),
            artifactRegistry: $registry,
            agentRunner: $agentRunner,
            runStore: $runStore,
            parentRunStore: $parentRunStore,
            eventStore: $eventStore,
            metadataReader: $metadataReader,
            childRunDirectory: $directory,
            contextAccessor: self::getContainer()->get(\Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor::class),
            logger: self::getContainer()->get('logger'),
        );

        $result = $service->execute('parent-3', 'asker', 'Should I delete Foo.php?');

        self::assertStringContainsString('unsupported human interaction', $result);
        self::assertStringContainsString('Artifact:', $result);

        // Verify artifact finalized as Failed.
        $entries = $registry->list('parent-3');
        self::assertCount(1, $entries);
        self::assertSame(AgentArtifactStatusEnum::Failed, $entries[0]->status);
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
            policyResolver: new AgentToolPolicyResolver(),
            promptBuilder: new AgentPromptBuilder(),
            artifactRegistry: $registry,
            agentRunner: $agentRunner,
            runStore: $runStore,
            parentRunStore: $parentRunStore,
            eventStore: $eventStore,
            metadataReader: $metadataReader,
            childRunDirectory: $directory,
            contextAccessor: self::getContainer()->get(\Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor::class),
            logger: self::getContainer()->get('logger'),
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
            policyResolver: new AgentToolPolicyResolver(),
            promptBuilder: new AgentPromptBuilder(),
            artifactRegistry: $registry,
            agentRunner: $this->createStub(AgentRunnerInterface::class),
            runStore: $this->createStub(RunStoreInterface::class),
            parentRunStore: $this->createStub(RunStoreInterface::class),
            eventStore: $eventStore,
            metadataReader: new SubagentRunMetadataReader($eventStore),
            childRunDirectory: $directory,
            contextAccessor: self::getContainer()->get(\Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor::class),
            logger: self::getContainer()->get('logger'),
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
            policyResolver: new AgentToolPolicyResolver(),
            promptBuilder: new AgentPromptBuilder(),
            artifactRegistry: $registry,
            agentRunner: $this->createStub(AgentRunnerInterface::class),
            runStore: $this->createStub(RunStoreInterface::class),
            parentRunStore: $this->createStub(RunStoreInterface::class),
            eventStore: $eventStore,
            metadataReader: new SubagentRunMetadataReader($eventStore),
            childRunDirectory: $directory,
            contextAccessor: self::getContainer()->get(\Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor::class),
            logger: self::getContainer()->get('logger'),
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
            policyResolver: new AgentToolPolicyResolver(),
            promptBuilder: new AgentPromptBuilder(),
            artifactRegistry: $registry,
            agentRunner: $agentRunner,
            runStore: $runStore,
            parentRunStore: $parentRunStore,
            eventStore: $eventStore,
            metadataReader: $metadataReader,
            childRunDirectory: $directory,
            contextAccessor: $contextAccessor,
            logger: self::getContainer()->get('logger'),
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
}
