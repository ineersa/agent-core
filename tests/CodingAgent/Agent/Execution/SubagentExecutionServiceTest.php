<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunLocator;
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
        $agentRunner->expects(self::once())->method('start');

        $def = new AgentDefinitionDTO(
            name: 'test-agent',
            description: 'Test agent',
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'Test instructions.',
        );

        $catalog = new AgentDefinitionCatalog([$def]);

        $locator = self::getContainer()->get(AgentChildRunLocator::class);
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
            childRunLocator: $locator,
            contextAccessor: self::getContainer()->get(\Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor::class),
            logger: self::getContainer()->get('logger'),
        );

        $result = $service->execute('parent-1', 'test-agent', 'Inspect Foo.php');

        self::assertStringContainsString('Handoff:', $result);

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
        $locator = self::getContainer()->get(AgentChildRunLocator::class);
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
            childRunLocator: $locator,
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

    public function testWaitingHumanBecomesNeedsClarification(): void
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
                self::stringContains('HITL'),
            );

        $def = new AgentDefinitionDTO(
            name: 'asker',
            description: 'Asking agent',
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'Ask questions.',
        );

        $catalog = new AgentDefinitionCatalog([$def]);
        $locator = self::getContainer()->get(AgentChildRunLocator::class);
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
            childRunLocator: $locator,
            contextAccessor: self::getContainer()->get(\Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor::class),
            logger: self::getContainer()->get('logger'),
        );

        $result = $service->execute('parent-3', 'asker', 'Should I delete Foo.php?');

        self::assertStringContainsString('needs clarification', $result);
        self::assertStringContainsString('delete Foo.php', $result);

        // Verify artifact finalized as NeedsClarification.
        $entries = $registry->list('parent-3');
        self::assertCount(1, $entries);
        self::assertSame(AgentArtifactStatusEnum::NeedsClarification, $entries[0]->status);
    }

    public function testMaxDepthOneBlocksGrandchildLaunch(): void
    {
        // Simulate a child agent run whose parent metadata reports
        // depth=1 and maxDepth=1.  This should block any further
        // nested launch.
        $parentDepth = 1;

        $def = new AgentDefinitionDTO(
            name: 'nested',
            description: 'Nested',
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'Nested agent.',
            maxDepth: 1,
        );

        $catalog = new AgentDefinitionCatalog([$def]);
        $locator = self::getContainer()->get(AgentChildRunLocator::class);
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);

        $runStore = $this->createStub(RunStoreInterface::class);
        $parentRunStore = $this->createStub(RunStoreInterface::class);

        $agentRunner = $this->createStub(AgentRunnerInterface::class);

        // EventStore returns RunStarted event with agent_depth=1.
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
                                    'agent_depth' => 1,
                                    'agent_max_depth' => 1,
                                    'agents_disabled' => false,
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
            childRunLocator: $locator,
            contextAccessor: self::getContainer()->get(\Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor::class),
            logger: self::getContainer()->get('logger'),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('depth');

        $service->execute('parent-child-run', 'nested', 'Go deeper');
    }

    public function testMissingAgentDefinitionThrowsNonRetryable(): void
    {
        $catalog = new AgentDefinitionCatalog([]);
        $locator = self::getContainer()->get(AgentChildRunLocator::class);
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
            childRunLocator: $locator,
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
        $locator = self::getContainer()->get(AgentChildRunLocator::class);
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
            childRunLocator: $locator,
            contextAccessor: self::getContainer()->get(\Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor::class),
            logger: self::getContainer()->get('logger'),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('does not allow foreground');

        $service->execute('parent-5', 'background-only', 'Task');
    }
}
