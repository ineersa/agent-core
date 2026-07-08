<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Context\AgentsContextBuilder;
use Ineersa\CodingAgent\Agent\Execution\ForkExecutionService;
use Ineersa\CodingAgent\Config\SessionMetadataStore;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Clock\MockClock;

#[CoversClass(ForkExecutionService::class)]
final class ForkExecutionServiceTest extends IsolatedKernelTestCase
{
    public function testExecuteLaunchesForkChildWithToolPolicyAndForkKind(): void
    {
        $parentRunId = 'parent-fork-1';
        $childRunId = 'child-fork-uuid';

        $parentRunStore = new InMemoryRunStore();
        $parentRunStore->compareAndSwap(new RunState(
            runId: $parentRunId,
            status: RunStatus::Running,
            version: 1,
            messages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'hello parent']]),
            ],
        ), 0);

        $completedChild = new RunState(
            runId: $childRunId,
            status: RunStatus::Completed,
            version: 2,
            messages: [
                new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Fork handoff body']]),
            ],
        );

        $childRunStore = $this->createStub(RunStoreInterface::class);
        $childRunStore->method('get')->willReturn($completedChild);

        $captured = null;
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start')->willReturnCallback(
            static function (StartRunInput $input) use (&$captured, $childRunId): string {
                $captured = $input;

                return $childRunId;
            },
        );

        $registryStub = $this->createStub(ToolRegistryInterface::class);
        $registryStub->method('activeToolNames')->willReturn(['read', 'bash', 'subagent', 'fork']);
        $handler = $this->createStub(ToolHandlerInterface::class);
        $registryStub->method('toolDefinition')->willReturnCallback(static function (string $name) use ($handler) {
            return new ToolDefinitionDTO(
                name: $name,
                description: $name.' tool',
                parametersJsonSchema: ['type' => 'object'],
                handler: $handler,
                promptLine: 'fork' === $name ? 'fork task="..." — launch fork child with inherited history' : $name,
            );
        });

        $contextAccessor = new StackToolExecutionContextAccessor();
        $toolContext = new ToolContext(
            runId: $parentRunId,
            turnNo: 1,
            toolCallId: 'call_fork',
            toolName: 'fork',
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 120,
        );

        $container = self::getContainer();
        $service = new ForkExecutionService(
            forkContextBuilder: $container->get(\Ineersa\CodingAgent\Agent\Fork\ForkContextBuilder::class),
            messageComposer: $container->get(\Ineersa\CodingAgent\Agent\Fork\ForkChildMessageComposer::class),
            artifactRegistry: $container->get(AgentArtifactRegistry::class),
            agentRunner: $agentRunner,
            runStore: $childRunStore,
            parentRunStore: $parentRunStore,
            eventStore: $this->createStub(EventStoreInterface::class),
            metadataReader: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader::class),
            childRunDirectory: $container->get(\Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory::class),
            contextAccessor: $contextAccessor,
            toolRegistry: $registryStub,
            mcpToolsResolver: $container->get(\Ineersa\CodingAgent\Agent\Execution\AgentMcpToolsResolver::class),
            agentsContextBuilder: $container->get(AgentsContextBuilder::class),
            skillsContextBuilder: $container->get(SkillsContextBuilder::class),
            agentsConfig: $container->get(\Ineersa\CodingAgent\Config\AgentsConfig::class),
            modelResolver: $container->get(\Ineersa\CodingAgent\Config\ModelResolver::class),
            progressSnapshotBuilder: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder::class),
            childProgressSummaryBuilder: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder::class),
            clock: new MockClock(),
        );

        $result = $contextAccessor->with($toolContext, static fn (): string => $service->execute($parentRunId, 'Do fork task'));

        $this->assertNotNull($captured);
        $this->assertStringContainsString('delegated child agent', $captured->systemPrompt);
        $this->assertStringNotContainsString('fork task=', $captured->systemPrompt);
        $this->assertStringNotContainsString('launch fork child', strtolower($captured->systemPrompt));
        $this->assertStringContainsString('Fork handoff body', $result);

        $allowed = $captured->metadata->toolsScope['allowed_tools'] ?? [];
        $this->assertContains('subagent', $allowed);
        $this->assertNotContains('fork', $allowed);
        $this->assertSame('fork', $captured->metadata->session['agent_name'] ?? null);
        $this->assertSame('fork', $captured->metadata->session['child_kind'] ?? null);

        $entries = $container->get(AgentArtifactRegistry::class)->list($parentRunId);
        $this->assertNotEmpty($entries);
        $this->assertSame(AgentArtifactKindEnum::Fork, $entries[0]->kind);
    }

    public function testExecuteResetsArtifactStatusFromNeedsClarificationWhenChildResumes(): void
    {
        $parentRunId = 'parent-fork-hitl';
        $childRunId = 'child-fork-hitl';

        $parentRunStore = new InMemoryRunStore();
        $parentRunStore->compareAndSwap(new RunState(
            runId: $parentRunId,
            status: RunStatus::Running,
            version: 1,
            messages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'parent']]),
            ],
        ), 0);

        $waitingState = new RunState(
            runId: $childRunId,
            status: RunStatus::WaitingHuman,
            version: 2,
            lastSeq: 1,
            turnNo: 1,
            messages: [],
        );
        $runningState = new RunState(
            runId: $childRunId,
            status: RunStatus::Running,
            version: 3,
            lastSeq: 2,
            turnNo: 2,
            messages: [],
        );
        $completedState = new RunState(
            runId: $childRunId,
            status: RunStatus::Completed,
            version: 4,
            lastSeq: 3,
            turnNo: 2,
            messages: [
                new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Resumed handoff']]),
            ],
        );

        $container = self::getContainer();
        $artifactRegistry = $container->get(AgentArtifactRegistry::class);
        $artifactIdHolder = ['id' => null];
        $pollCount = 0;

        $childRunStore = $this->createStub(RunStoreInterface::class);
        $childRunStore->method('get')->willReturnCallback(
            function () use (
                &$pollCount,
                $waitingState,
                $runningState,
                $completedState,
                $artifactRegistry,
                $parentRunId,
                &$artifactIdHolder,
            ): RunState {
                ++$pollCount;
                if (1 === $pollCount) {
                    return $waitingState;
                }
                if (2 === $pollCount) {
                    $entries = $artifactRegistry->list($parentRunId);
                    $this->assertNotEmpty($entries);
                    $artifactIdHolder['id'] = $entries[0]->artifactId;
                    $this->assertSame(AgentArtifactStatusEnum::NeedsClarification, $entries[0]->status);

                    return $waitingState;
                }
                if (3 === $pollCount) {
                    return $runningState;
                }
                if (4 === $pollCount) {
                    $entry = $artifactRegistry->get($parentRunId, (string) $artifactIdHolder['id']);
                    $this->assertNotNull($entry);
                    $this->assertSame(AgentArtifactStatusEnum::Running, $entry->status);

                    return $runningState;
                }

                return $completedState;
            },
        );

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start')->willReturn($childRunId);
        $agentRunner->expects($this->never())->method('cancel');

        $registryStub = $this->createStub(ToolRegistryInterface::class);
        $registryStub->method('activeToolNames')->willReturn(['read', 'subagent', 'fork']);

        $contextAccessor = new StackToolExecutionContextAccessor();
        $toolContext = new ToolContext(
            runId: $parentRunId,
            turnNo: 1,
            toolCallId: 'call_fork_hitl',
            toolName: 'fork',
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 120,
        );

        $service = new ForkExecutionService(
            forkContextBuilder: $container->get(\Ineersa\CodingAgent\Agent\Fork\ForkContextBuilder::class),
            messageComposer: $container->get(\Ineersa\CodingAgent\Agent\Fork\ForkChildMessageComposer::class),
            artifactRegistry: $artifactRegistry,
            agentRunner: $agentRunner,
            runStore: $childRunStore,
            parentRunStore: $parentRunStore,
            eventStore: $this->createStub(EventStoreInterface::class),
            metadataReader: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader::class),
            childRunDirectory: $container->get(\Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory::class),
            contextAccessor: $contextAccessor,
            toolRegistry: $registryStub,
            mcpToolsResolver: $container->get(\Ineersa\CodingAgent\Agent\Execution\AgentMcpToolsResolver::class),
            agentsContextBuilder: $container->get(AgentsContextBuilder::class),
            skillsContextBuilder: $container->get(SkillsContextBuilder::class),
            agentsConfig: $container->get(\Ineersa\CodingAgent\Config\AgentsConfig::class),
            modelResolver: $container->get(\Ineersa\CodingAgent\Config\ModelResolver::class),
            progressSnapshotBuilder: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder::class),
            childProgressSummaryBuilder: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder::class),
            clock: new MockClock(),
        );

        $result = $contextAccessor->with($toolContext, static fn (): string => $service->execute($parentRunId, 'HITL then resume'));

        $this->assertStringContainsString('Resumed handoff', $result);
        $entries = $artifactRegistry->list($parentRunId);
        $this->assertCount(1, $entries);
        $this->assertSame(AgentArtifactStatusEnum::Completed, $entries[0]->status);
    }

    public function testExecuteUsesForkModelOverrideFromSnapshot(): void
    {
        $parentRunId = 'parent-fork-model';
        $childRunId = 'child-fork-model';

        $parentRunStore = new InMemoryRunStore();
        $parentRunStore->compareAndSwap(new RunState(
            runId: $parentRunId,
            status: RunStatus::Running,
            version: 1,
            messages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'hello']]),
            ],
        ), 0);

        $completedChild = new RunState(
            runId: $childRunId,
            status: RunStatus::Completed,
            version: 2,
            messages: [
                new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'done']]),
            ],
        );

        $childRunStore = $this->createStub(RunStoreInterface::class);
        $childRunStore->method('get')->willReturn($completedChild);

        $captured = null;
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start')->willReturnCallback(
            static function (StartRunInput $input) use (&$captured, $childRunId): string {
                $captured = $input;

                return $childRunId;
            },
        );

        $registryStub = $this->createStub(ToolRegistryInterface::class);
        $registryStub->method('activeToolNames')->willReturn(['read', 'subagent', 'fork']);

        $contextAccessor = new StackToolExecutionContextAccessor();
        $toolContext = new ToolContext(
            runId: $parentRunId,
            turnNo: 1,
            toolCallId: 'call_fork_model',
            toolName: 'fork',
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 120,
        );

        $container = self::getContainer();
        $forkContextBuilder = $this->buildForkContextBuilder('llama_cpp/fork-override');
        $service = new ForkExecutionService(
            forkContextBuilder: $forkContextBuilder,
            messageComposer: $container->get(\Ineersa\CodingAgent\Agent\Fork\ForkChildMessageComposer::class),
            artifactRegistry: $container->get(AgentArtifactRegistry::class),
            agentRunner: $agentRunner,
            runStore: $childRunStore,
            parentRunStore: $parentRunStore,
            eventStore: $this->createStub(EventStoreInterface::class),
            metadataReader: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader::class),
            childRunDirectory: $container->get(\Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory::class),
            contextAccessor: $contextAccessor,
            toolRegistry: $registryStub,
            mcpToolsResolver: $container->get(\Ineersa\CodingAgent\Agent\Execution\AgentMcpToolsResolver::class),
            agentsContextBuilder: $container->get(AgentsContextBuilder::class),
            skillsContextBuilder: $container->get(SkillsContextBuilder::class),
            agentsConfig: $container->get(\Ineersa\CodingAgent\Config\AgentsConfig::class),
            modelResolver: $container->get(\Ineersa\CodingAgent\Config\ModelResolver::class),
            progressSnapshotBuilder: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder::class),
            childProgressSummaryBuilder: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder::class),
            clock: new MockClock(),
        );

        $contextAccessor->with($toolContext, static fn (): string => $service->execute($parentRunId, 'model test'));

        $this->assertNotNull($captured);
        $this->assertSame('llama_cpp/fork-override', $captured->metadata->model);
        $this->assertArrayNotHasKey('fork_level', $captured->metadata->session);
    }

    public function testExecuteFallsBackToCurrentSessionModelOverStaleRunStartedMetadata(): void
    {
        $parentRunId = $this->createSessionWithCurrentModel('llama_cpp/flash');
        $childRunId = 'child-fork-session-model';

        $parentRunStore = new InMemoryRunStore();
        $parentRunStore->compareAndSwap(new RunState(
            runId: $parentRunId,
            status: RunStatus::Running,
            version: 1,
            messages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'hello']]),
            ],
        ), 0);

        $completedChild = new RunState(
            runId: $childRunId,
            status: RunStatus::Completed,
            version: 2,
            messages: [
                new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'done']]),
            ],
        );

        $childRunStore = $this->createStub(RunStoreInterface::class);
        $childRunStore->method('get')->willReturn($completedChild);

        $parentEventStore = $this->createStub(EventStoreInterface::class);
        $parentEventStore->method('allFor')->willReturnCallback(
            static function (string $runId) use ($parentRunId): array {
                if ($parentRunId !== $runId) {
                    return [];
                }

                return [
                    new RunEvent(
                        runId: $parentRunId,
                        seq: 1,
                        turnNo: 0,
                        type: RunEventTypeEnum::RunStarted->value,
                        payload: [
                            'payload' => [
                                'metadata' => [
                                    'model' => 'deepseek/deepseek-v4-pro',
                                    'session' => ['kind' => 'session'],
                                ],
                            ],
                        ],
                    ),
                ];
            },
        );
        $metadataReader = new \Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader($parentEventStore);

        $captured = null;
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start')->willReturnCallback(
            static function (StartRunInput $input) use (&$captured, $childRunId): string {
                $captured = $input;

                return $childRunId;
            },
        );

        $registryStub = $this->createStub(ToolRegistryInterface::class);
        $registryStub->method('activeToolNames')->willReturn(['read', 'subagent', 'fork']);

        $contextAccessor = new StackToolExecutionContextAccessor();
        $toolContext = new ToolContext(
            runId: $parentRunId,
            turnNo: 1,
            toolCallId: 'call_fork_session_model',
            toolName: 'fork',
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 120,
        );

        $container = self::getContainer();
        $service = new ForkExecutionService(
            forkContextBuilder: $this->buildForkContextBuilder(null),
            messageComposer: $container->get(\Ineersa\CodingAgent\Agent\Fork\ForkChildMessageComposer::class),
            artifactRegistry: $container->get(AgentArtifactRegistry::class),
            agentRunner: $agentRunner,
            runStore: $childRunStore,
            parentRunStore: $parentRunStore,
            eventStore: $this->createStub(EventStoreInterface::class),
            metadataReader: $metadataReader,
            childRunDirectory: $container->get(\Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory::class),
            contextAccessor: $contextAccessor,
            toolRegistry: $registryStub,
            mcpToolsResolver: $container->get(\Ineersa\CodingAgent\Agent\Execution\AgentMcpToolsResolver::class),
            agentsContextBuilder: $container->get(AgentsContextBuilder::class),
            skillsContextBuilder: $container->get(SkillsContextBuilder::class),
            agentsConfig: $container->get(\Ineersa\CodingAgent\Config\AgentsConfig::class),
            modelResolver: self::getContainer()->get(\Ineersa\CodingAgent\Config\ModelResolver::class),
            progressSnapshotBuilder: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder::class),
            childProgressSummaryBuilder: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder::class),
            clock: new MockClock(),
        );

        $contextAccessor->with($toolContext, static fn (): string => $service->execute($parentRunId, 'session model test'));

        $this->assertNotNull($captured);
        $this->assertSame('llama_cpp/flash', $captured->metadata->model);
    }

    public function testExecuteExplicitModelOverridesForksModelFromSnapshot(): void
    {
        $parentRunId = 'parent-fork-explicit-model';
        $childRunId = 'child-fork-explicit-model';

        $parentRunStore = new InMemoryRunStore();
        $parentRunStore->compareAndSwap(new RunState(
            runId: $parentRunId,
            status: RunStatus::Running,
            version: 1,
            messages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'hello']]),
            ],
        ), 0);

        $completedChild = new RunState(
            runId: $childRunId,
            status: RunStatus::Completed,
            version: 2,
            messages: [
                new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'done']]),
            ],
        );

        $childRunStore = $this->createStub(RunStoreInterface::class);
        $childRunStore->method('get')->willReturn($completedChild);

        $captured = null;
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start')->willReturnCallback(
            static function (StartRunInput $input) use (&$captured, $childRunId): string {
                $captured = $input;

                return $childRunId;
            },
        );

        $registryStub = $this->createStub(ToolRegistryInterface::class);
        $registryStub->method('activeToolNames')->willReturn(['read', 'subagent', 'fork']);

        $contextAccessor = new StackToolExecutionContextAccessor();
        $toolContext = new ToolContext(
            runId: $parentRunId,
            turnNo: 1,
            toolCallId: 'call_fork_explicit_model',
            toolName: 'fork',
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 120,
        );

        $container = self::getContainer();
        $service = new ForkExecutionService(
            forkContextBuilder: $this->buildForkContextBuilder('llama_cpp/fork-override'),
            messageComposer: $container->get(\Ineersa\CodingAgent\Agent\Fork\ForkChildMessageComposer::class),
            artifactRegistry: $container->get(AgentArtifactRegistry::class),
            agentRunner: $agentRunner,
            runStore: $childRunStore,
            parentRunStore: $parentRunStore,
            eventStore: $this->createStub(EventStoreInterface::class),
            metadataReader: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader::class),
            childRunDirectory: $container->get(\Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory::class),
            contextAccessor: $contextAccessor,
            toolRegistry: $registryStub,
            mcpToolsResolver: $container->get(\Ineersa\CodingAgent\Agent\Execution\AgentMcpToolsResolver::class),
            agentsContextBuilder: $container->get(AgentsContextBuilder::class),
            skillsContextBuilder: $container->get(SkillsContextBuilder::class),
            agentsConfig: $container->get(\Ineersa\CodingAgent\Config\AgentsConfig::class),
            modelResolver: $container->get(\Ineersa\CodingAgent\Config\ModelResolver::class),
            progressSnapshotBuilder: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder::class),
            childProgressSummaryBuilder: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder::class),
            clock: new MockClock(),
        );

        $contextAccessor->with(
            $toolContext,
            static fn (): string => $service->execute($parentRunId, 'explicit model test', modelOverride: 'tool/explicit-model'),
        );

        $this->assertNotNull($captured);
        $this->assertSame('tool/explicit-model', $captured->metadata->model);
    }

    public function testExecutePropagatesExplicitThinkingToChildMetadata(): void
    {
        $parentRunId = 'parent-fork-thinking';
        $childRunId = 'child-fork-thinking';

        $parentRunStore = new InMemoryRunStore();
        $parentRunStore->compareAndSwap(new RunState(
            runId: $parentRunId,
            status: RunStatus::Running,
            version: 1,
            messages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'hello']]),
            ],
        ), 0);

        $completedChild = new RunState(
            runId: $childRunId,
            status: RunStatus::Completed,
            version: 2,
            messages: [
                new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'done']]),
            ],
        );

        $childRunStore = $this->createStub(RunStoreInterface::class);
        $childRunStore->method('get')->willReturn($completedChild);

        $captured = null;
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start')->willReturnCallback(
            static function (StartRunInput $input) use (&$captured, $childRunId): string {
                $captured = $input;

                return $childRunId;
            },
        );

        $registryStub = $this->createStub(ToolRegistryInterface::class);
        $registryStub->method('activeToolNames')->willReturn(['read', 'subagent', 'fork']);

        $contextAccessor = new StackToolExecutionContextAccessor();
        $toolContext = new ToolContext(
            runId: $parentRunId,
            turnNo: 1,
            toolCallId: 'call_fork_thinking',
            toolName: 'fork',
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 120,
        );

        $container = self::getContainer();
        $service = new ForkExecutionService(
            forkContextBuilder: $this->buildForkContextBuilder(null),
            messageComposer: $container->get(\Ineersa\CodingAgent\Agent\Fork\ForkChildMessageComposer::class),
            artifactRegistry: $container->get(AgentArtifactRegistry::class),
            agentRunner: $agentRunner,
            runStore: $childRunStore,
            parentRunStore: $parentRunStore,
            eventStore: $this->createStub(EventStoreInterface::class),
            metadataReader: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader::class),
            childRunDirectory: $container->get(\Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory::class),
            contextAccessor: $contextAccessor,
            toolRegistry: $registryStub,
            mcpToolsResolver: $container->get(\Ineersa\CodingAgent\Agent\Execution\AgentMcpToolsResolver::class),
            agentsContextBuilder: $container->get(AgentsContextBuilder::class),
            skillsContextBuilder: $container->get(SkillsContextBuilder::class),
            agentsConfig: $container->get(\Ineersa\CodingAgent\Config\AgentsConfig::class),
            modelResolver: $container->get(\Ineersa\CodingAgent\Config\ModelResolver::class),
            progressSnapshotBuilder: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder::class),
            childProgressSummaryBuilder: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder::class),
            clock: new MockClock(),
        );

        $contextAccessor->with(
            $toolContext,
            static fn (): string => $service->execute($parentRunId, 'thinking test', reasoningOverride: 'high'),
        );

        $this->assertNotNull($captured);
        $this->assertSame('high', $captured->metadata->reasoning);
    }

    public function testExecuteFallsBackToCurrentSessionReasoningOverStaleRunStartedMetadata(): void
    {
        $parentRunId = $this->createSessionWithCurrentModel('llama_cpp/flash', reasoning: 'medium');
        $childRunId = 'child-fork-session-reasoning';

        $parentRunStore = new InMemoryRunStore();
        $parentRunStore->compareAndSwap(new RunState(
            runId: $parentRunId,
            status: RunStatus::Running,
            version: 1,
            messages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'hello']]),
            ],
        ), 0);

        $completedChild = new RunState(
            runId: $childRunId,
            status: RunStatus::Completed,
            version: 2,
            messages: [
                new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'done']]),
            ],
        );

        $childRunStore = $this->createStub(RunStoreInterface::class);
        $childRunStore->method('get')->willReturn($completedChild);

        $parentEventStore = $this->createStub(EventStoreInterface::class);
        $parentEventStore->method('allFor')->willReturnCallback(
            static function (string $runId) use ($parentRunId): array {
                if ($parentRunId !== $runId) {
                    return [];
                }

                return [
                    new RunEvent(
                        runId: $parentRunId,
                        seq: 1,
                        turnNo: 0,
                        type: RunEventTypeEnum::RunStarted->value,
                        payload: [
                            'payload' => [
                                'metadata' => [
                                    'model' => 'session/selected-model',
                                    'reasoning' => 'high',
                                    'session' => ['kind' => 'session'],
                                ],
                            ],
                        ],
                    ),
                ];
            },
        );
        $metadataReader = new \Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader($parentEventStore);

        $captured = null;
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start')->willReturnCallback(
            static function (StartRunInput $input) use (&$captured, $childRunId): string {
                $captured = $input;

                return $childRunId;
            },
        );

        $registryStub = $this->createStub(ToolRegistryInterface::class);
        $registryStub->method('activeToolNames')->willReturn(['read', 'subagent', 'fork']);

        $contextAccessor = new StackToolExecutionContextAccessor();
        $toolContext = new ToolContext(
            runId: $parentRunId,
            turnNo: 1,
            toolCallId: 'call_fork_session_reasoning',
            toolName: 'fork',
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 120,
        );

        $container = self::getContainer();
        $service = new ForkExecutionService(
            forkContextBuilder: $this->buildForkContextBuilder(null),
            messageComposer: $container->get(\Ineersa\CodingAgent\Agent\Fork\ForkChildMessageComposer::class),
            artifactRegistry: $container->get(AgentArtifactRegistry::class),
            agentRunner: $agentRunner,
            runStore: $childRunStore,
            parentRunStore: $parentRunStore,
            eventStore: $this->createStub(EventStoreInterface::class),
            metadataReader: $metadataReader,
            childRunDirectory: $container->get(\Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory::class),
            contextAccessor: $contextAccessor,
            toolRegistry: $registryStub,
            mcpToolsResolver: $container->get(\Ineersa\CodingAgent\Agent\Execution\AgentMcpToolsResolver::class),
            agentsContextBuilder: $container->get(AgentsContextBuilder::class),
            skillsContextBuilder: $container->get(SkillsContextBuilder::class),
            agentsConfig: $container->get(\Ineersa\CodingAgent\Config\AgentsConfig::class),
            modelResolver: $container->get(\Ineersa\CodingAgent\Config\ModelResolver::class),
            progressSnapshotBuilder: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder::class),
            childProgressSummaryBuilder: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder::class),
            clock: new MockClock(),
        );

        $contextAccessor->with($toolContext, static fn (): string => $service->execute($parentRunId, 'session reasoning test'));

        $this->assertNotNull($captured);
        $this->assertSame('medium', $captured->metadata->reasoning);
    }

    public function testExecuteUsesForkThinkingLevelSettingWhenToolThinkingOmitted(): void
    {
        $parentRunId = 'parent-fork-config-thinking';
        $childRunId = 'child-fork-config-thinking';

        $parentRunStore = new InMemoryRunStore();
        $parentRunStore->compareAndSwap(new RunState(
            runId: $parentRunId,
            status: RunStatus::Running,
            version: 1,
            messages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'hello']]),
            ],
        ), 0);

        $completedChild = new RunState(
            runId: $childRunId,
            status: RunStatus::Completed,
            version: 2,
            messages: [
                new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'done']]),
            ],
        );

        $childRunStore = $this->createStub(RunStoreInterface::class);
        $childRunStore->method('get')->willReturn($completedChild);

        $parentEventStore = $this->createStub(EventStoreInterface::class);
        $parentEventStore->method('allFor')->willReturnCallback(
            static function (string $runId) use ($parentRunId): array {
                if ($parentRunId !== $runId) {
                    return [];
                }

                return [
                    new RunEvent(
                        runId: $parentRunId,
                        seq: 1,
                        turnNo: 0,
                        type: RunEventTypeEnum::RunStarted->value,
                        payload: [
                            'payload' => [
                                'metadata' => [
                                    'model' => 'session/selected-model',
                                    'reasoning' => 'high',
                                    'session' => ['kind' => 'session'],
                                ],
                            ],
                        ],
                    ),
                ];
            },
        );
        $metadataReader = new \Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader($parentEventStore);

        $captured = null;
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start')->willReturnCallback(
            static function (StartRunInput $input) use (&$captured, $childRunId): string {
                $captured = $input;

                return $childRunId;
            },
        );

        $registryStub = $this->createStub(ToolRegistryInterface::class);
        $registryStub->method('activeToolNames')->willReturn(['read', 'subagent', 'fork']);

        $contextAccessor = new StackToolExecutionContextAccessor();
        $toolContext = new ToolContext(
            runId: $parentRunId,
            turnNo: 1,
            toolCallId: 'call_fork_config_thinking',
            toolName: 'fork',
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 120,
        );

        $container = self::getContainer();
        $service = new ForkExecutionService(
            forkContextBuilder: $this->buildForkContextBuilder(null, 'xhigh'),
            messageComposer: $container->get(\Ineersa\CodingAgent\Agent\Fork\ForkChildMessageComposer::class),
            artifactRegistry: $container->get(AgentArtifactRegistry::class),
            agentRunner: $agentRunner,
            runStore: $childRunStore,
            parentRunStore: $parentRunStore,
            eventStore: $this->createStub(EventStoreInterface::class),
            metadataReader: $metadataReader,
            childRunDirectory: $container->get(\Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory::class),
            contextAccessor: $contextAccessor,
            toolRegistry: $registryStub,
            mcpToolsResolver: $container->get(\Ineersa\CodingAgent\Agent\Execution\AgentMcpToolsResolver::class),
            agentsContextBuilder: $container->get(AgentsContextBuilder::class),
            skillsContextBuilder: $container->get(SkillsContextBuilder::class),
            agentsConfig: $container->get(\Ineersa\CodingAgent\Config\AgentsConfig::class),
            modelResolver: $container->get(\Ineersa\CodingAgent\Config\ModelResolver::class),
            progressSnapshotBuilder: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder::class),
            childProgressSummaryBuilder: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder::class),
            clock: new MockClock(),
        );

        $contextAccessor->with($toolContext, static fn (): string => $service->execute($parentRunId, 'config thinking test'));

        $this->assertNotNull($captured);
        $this->assertSame('xhigh', $captured->metadata->reasoning);
    }

    public function testExecuteExplicitToolThinkingOverridesForkThinkingLevelSetting(): void
    {
        $parentRunId = 'parent-fork-explicit-thinking';
        $childRunId = 'child-fork-explicit-thinking';

        $parentRunStore = new InMemoryRunStore();
        $parentRunStore->compareAndSwap(new RunState(
            runId: $parentRunId,
            status: RunStatus::Running,
            version: 1,
            messages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'hello']]),
            ],
        ), 0);

        $completedChild = new RunState(
            runId: $childRunId,
            status: RunStatus::Completed,
            version: 2,
            messages: [
                new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'done']]),
            ],
        );

        $childRunStore = $this->createStub(RunStoreInterface::class);
        $childRunStore->method('get')->willReturn($completedChild);

        $captured = null;
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start')->willReturnCallback(
            static function (StartRunInput $input) use (&$captured, $childRunId): string {
                $captured = $input;

                return $childRunId;
            },
        );

        $registryStub = $this->createStub(ToolRegistryInterface::class);
        $registryStub->method('activeToolNames')->willReturn(['read', 'subagent', 'fork']);

        $contextAccessor = new StackToolExecutionContextAccessor();
        $toolContext = new ToolContext(
            runId: $parentRunId,
            turnNo: 1,
            toolCallId: 'call_fork_explicit_thinking',
            toolName: 'fork',
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 120,
        );

        $container = self::getContainer();
        $service = new ForkExecutionService(
            forkContextBuilder: $this->buildForkContextBuilder(null, 'xhigh'),
            messageComposer: $container->get(\Ineersa\CodingAgent\Agent\Fork\ForkChildMessageComposer::class),
            artifactRegistry: $container->get(AgentArtifactRegistry::class),
            agentRunner: $agentRunner,
            runStore: $childRunStore,
            parentRunStore: $parentRunStore,
            eventStore: $this->createStub(EventStoreInterface::class),
            metadataReader: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader::class),
            childRunDirectory: $container->get(\Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory::class),
            contextAccessor: $contextAccessor,
            toolRegistry: $registryStub,
            mcpToolsResolver: $container->get(\Ineersa\CodingAgent\Agent\Execution\AgentMcpToolsResolver::class),
            agentsContextBuilder: $container->get(AgentsContextBuilder::class),
            skillsContextBuilder: $container->get(SkillsContextBuilder::class),
            agentsConfig: $container->get(\Ineersa\CodingAgent\Config\AgentsConfig::class),
            modelResolver: $container->get(\Ineersa\CodingAgent\Config\ModelResolver::class),
            progressSnapshotBuilder: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder::class),
            childProgressSummaryBuilder: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder::class),
            clock: new MockClock(),
        );

        $contextAccessor->with(
            $toolContext,
            static fn (): string => $service->execute($parentRunId, 'explicit thinking test', reasoningOverride: 'low'),
        );

        $this->assertNotNull($captured);
        $this->assertSame('low', $captured->metadata->reasoning);
    }

    private function buildForkContextBuilder(?string $model, ?string $thinkingLevel = null): \Ineersa\CodingAgent\Agent\Fork\ForkContextBuilder
    {
        $container = self::getContainer();

        return new \Ineersa\CodingAgent\Agent\Fork\ForkContextBuilder(
            sanitizer: $container->get(\Ineersa\CodingAgent\Agent\Fork\ForkSnapshotSanitizer::class),
            compactor: $container->get(\Ineersa\CodingAgent\Agent\Fork\ForkSnapshotCompactor::class),
            promptBuilder: $container->get(\Ineersa\CodingAgent\Agent\Fork\ForkTaskPromptBuilder::class),
            configResolver: new \Ineersa\CodingAgent\Agent\Fork\ForkConfigResolver(new \Ineersa\CodingAgent\Config\ForksConfigDTO(model: $model, thinkingLevel: $thinkingLevel)),
        );
    }

    private function createSessionWithCurrentModel(string $model, ?string $reasoning = null): string
    {
        $entity = new HatfieldSession();
        $entity->cwd = self::getContainer()->getParameter('kernel.project_dir');
        $em = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $em->persist($entity);
        $em->flush();

        $sessionId = (string) $entity->id;
        $fields = ['model' => $model];
        if (null !== $reasoning) {
            $fields['reasoning'] = $reasoning;
        }
        self::getContainer()->get(SessionMetadataStore::class)->writeSessionMetadata($sessionId, $fields);

        return $sessionId;
    }
}
