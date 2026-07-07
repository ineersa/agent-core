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
use Ineersa\CodingAgent\Agent\Artifact\AgentChildArtifactLaunchContextStore;
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
            runId: 'child-fork-uuid',
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
            static function (StartRunInput $input) use (&$captured): string {
                $captured = $input;

                return $input->runId;
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
            launchContextStore: $container->get(AgentChildArtifactLaunchContextStore::class),
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
            progressSnapshotBuilder: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder::class),
            childProgressSummaryBuilder: $container->get(\Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder::class),
            clock: new MockClock(),
        );

        $result = $contextAccessor->with($toolContext, static fn (): string => $service->execute($parentRunId, 'Do fork task'));

        $this->assertNotNull($captured);
        $this->assertStringContainsString('FORK MODE IS ENABLED', $captured->systemPrompt);
        $this->assertStringContainsString('Fork launched in the background', $result);
        $this->assertMatchesRegularExpression('/agent_run_id: [0-9a-f-]{36}/', $result);

        $allowed = $captured->metadata->toolsScope['allowed_tools'] ?? [];
        $this->assertContains('subagent', $allowed);
        $this->assertNotContains('fork', $allowed);
        $this->assertSame('fork', $captured->metadata->session['agent_name'] ?? null);
        $this->assertSame('fork', $captured->metadata->session['child_kind'] ?? null);

        $entries = $container->get(AgentArtifactRegistry::class)->list($parentRunId);
        $this->assertNotEmpty($entries);
        $this->assertSame(AgentArtifactKindEnum::Fork, $entries[0]->kind);
    }
}
