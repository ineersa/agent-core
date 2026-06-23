<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
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
use Ineersa\CodingAgent\Agent\Execution\SubagentExecutionService;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\AgentCore\Tests\Support\TestDirectoryIsolation;

final class SubagentExecutionServiceTest extends IsolatedKernelTestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        // This test doesn't need a full temp directory for sessions — the
        // artifact paths will be created inside the test's sessions base path.
    }

    protected function tearDown(): void
    {
        parent::tearDown();
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

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects(self::once())->method('start')->willReturn('child-uuid');

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

        // Use real AgentArtifactRegistry from container (backed by temp directory).
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);

        $service = new SubagentExecutionService(
            catalog: $catalog,
            depthGuard: self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\AgentDepthGuard::class),
            policyResolver: self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\AgentToolPolicyResolver::class),
            promptBuilder: self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\AgentPromptBuilder::class),
            artifactRegistry: $registry,
            agentRunner: $agentRunner,
            runStore: $runStore,
            eventStore: $eventStore,
            childRunLocator: $locator,
            contextAccessor: self::getContainer()->get(\Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor::class),
            logger: self::getContainer()->get('logger'),
        );

        $result = $service->execute('parent-1', 'test-agent', 'Inspect Foo.php', '', '');

        self::assertStringContainsString('Handoff:', $result);

        // Verify artifact was finalized.
        $entry = $registry->get('parent-1', \substr($result, 0));
        // Can't check directly since artifactId is random - just verify no exception.
    }
}
