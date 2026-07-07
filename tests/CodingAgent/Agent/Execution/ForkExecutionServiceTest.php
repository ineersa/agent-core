<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\RunStoreInterface;
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
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
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
            progressSnapshotBuilder: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder::class),
            childProgressSummaryBuilder: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder::class),
            clock: new MockClock(),
        );

        $result = $contextAccessor->with($toolContext, static fn (): string => $service->execute($parentRunId, 'Do fork task'));

        $this->assertNotNull($captured);
        $this->assertStringContainsString('FORK MODE IS ENABLED', $captured->systemPrompt);
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
}
