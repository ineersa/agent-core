<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\CodingAgent\Agent\Context\AgentsContextBuilder;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\McpAgentModeEnum;
use Ineersa\CodingAgent\Agent\Definition\McpPolicyDTO;
use Ineersa\CodingAgent\Agent\Execution\AgentDepthGuard;
use Ineersa\CodingAgent\Agent\Execution\AgentMcpToolsResolver;
use Ineersa\CodingAgent\Agent\Execution\AgentPromptBuilder;
use Ineersa\CodingAgent\Agent\Execution\AgentToolPolicyResolver;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder;
use Ineersa\CodingAgent\Agent\Execution\SubagentExecutionService;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Session\CommittedRunEventAppender;
use Ineersa\CodingAgent\SystemPrompt\SystemPromptBuilder;
use Ineersa\CodingAgent\Tests\Agent\Execution\Support\PipelineCapturingAgentRunner;
use Ineersa\CodingAgent\Tests\Agent\Execution\Support\PromptContractTestSupport;
use Ineersa\CodingAgent\Tests\Agent\Execution\Support\ProviderBoundaryCaptureSupport;
use Ineersa\CodingAgent\Tests\Support\Mcp\TestMcpConfigLoaderFactory;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * GF-05 RED: bare project-root AGENTS.md must appear in effective parent + inheriting child context.
 */
#[Group('gf-05-prompt-contract')]
final class Gf05BareAgentsEffectiveContextIntegrationTest extends PerMethodIsolatedKernelTestCase
{
    public function testBareRootAgentsMdInParentAndInheritingChildEffectiveContext(): void
    {
        $sentinel = 'GF05_BARE_ROOT_AGENTS_SENTINEL_'.bin2hex(random_bytes(4));
        file_put_contents($this->isolatedCwd().'/AGENTS.md', $sentinel."\n");

        $parentRunId = Uuid::v4()->toRfc4122();
        $parentRunStore = self::getContainer()->get(RunStoreInterface::class);
        $eventStore = self::getContainer()->get(EventStoreInterface::class);
        $parentRunner = PipelineCapturingAgentRunner::create($parentRunStore, $eventStore);
        self::getContainer()->set(AgentRunnerInterface::class, $parentRunner);

        self::getContainer()->get(InProcessAgentSessionClient::class)->start(new StartRunRequest(
            prompt: 'launch child after context',
            runId: $parentRunId,
        ));

        $parentCanonical = $parentRunner->lastStartInput?->messages ?? [];
        $this->assertNotEmpty($parentCanonical);

        $parentRunStarted = PromptContractTestSupport::findRunStartedEvent($eventStore, $parentRunId);
        $this->assertNotNull($parentRunStarted);
        $parentRunStartedMessages = PromptContractTestSupport::messagesFromRunStartedPayload($parentRunStarted->payload);
        PromptContractTestSupport::assertCanonicalMatchesRunStartedMessages($parentCanonical, $parentRunStartedMessages);
        PromptContractTestSupport::assertSentinelCountInAgentsContext($parentCanonical, $sentinel, 1);
        PromptContractTestSupport::assertSentinelCountInAgentsContext($parentRunStartedMessages, $sentinel, 1);

        $parentCapture = ProviderBoundaryCaptureSupport::create(self::getContainer()->get(\Symfony\AI\Agent\Toolbox\ToolboxInterface::class));
        $parentCapture->captureForRun($parentRunId, $parentCanonical);
        PromptContractTestSupport::assertProviderUserMessagesContainSentinelOnce($parentCapture->capturedProviderMessages(), $sentinel);

        $childRunStore = new InMemoryRunStore();
        $childEventStore = new InMemoryEventStore();
        $childRunner = PipelineCapturingAgentRunner::create($childRunStore, $childEventStore);

        $service = $this->buildSubagentService(
            parentRunStore: $parentRunStore,
            childRunStore: $childRunStore,
            childEventStore: $childEventStore,
            childRunner: $childRunner,
        );

        $service->execute($parentRunId, 'gf05-scout', 'Verify inherited AGENTS context');

        $this->assertNotNull($childRunner->lastStartInput);
        $childCanonical = $childRunner->lastStartInput->messages;
        $childRunId = $childRunner->lastStartInput->runId;
        $this->assertNotNull($childRunId);

        $childRunStarted = PromptContractTestSupport::findRunStartedEvent($childEventStore, $childRunId);
        $this->assertNotNull($childRunStarted);
        $childRunStartedMessages = PromptContractTestSupport::messagesFromRunStartedPayload($childRunStarted->payload);
        PromptContractTestSupport::assertCanonicalMatchesRunStartedMessages($childCanonical, $childRunStartedMessages);

        PromptContractTestSupport::assertSentinelCountInAgentsContext($childCanonical, $sentinel, 1);
        PromptContractTestSupport::assertSentinelCountInAgentsContext($childRunStartedMessages, $sentinel, 1);

        $systemText = PromptContractTestSupport::messageText($childCanonical[0]);
        $this->assertStringNotContainsString($sentinel, $systemText, 'Child must not embed AGENTS.md body in system text.');

        $keys = PromptContractTestSupport::roleSourceKeys(PromptContractTestSupport::summarizeMessages($childCanonical));
        $this->assertContains('user-context:agents_context', $keys);

        $childCapture = ProviderBoundaryCaptureSupport::create(
            self::getContainer()->get(\Symfony\AI\Agent\Toolbox\ToolboxInterface::class),
            ProviderBoundaryCaptureSupport::fixedToolSetResolver(['read']),
        );
        $childCapture->captureForRun($childRunId, $childCanonical);
        PromptContractTestSupport::assertProviderUserMessagesContainSentinelOnce($childCapture->capturedProviderMessages(), $sentinel);
    }

    private function buildSubagentService(
        RunStoreInterface $parentRunStore,
        RunStoreInterface $childRunStore,
        EventStoreInterface $childEventStore,
        PipelineCapturingAgentRunner $childRunner,
    ): SubagentExecutionService {
        $registry = self::getContainer()->get(ToolRegistryInterface::class);
        $policy = new AgentToolPolicyResolver($registry, $this->emptyMcpToolsResolver());

        return new SubagentExecutionService(
            catalog: new AgentDefinitionCatalog([
                new AgentDefinitionDTO(
                    name: 'gf05-scout',
                    description: 'GF05 scout',
                    tools: ['read'],
                    mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
                    instructions: 'Scout child instructions.',
                ),
            ]),
            depthGuard: new AgentDepthGuard(),
            policyResolver: $policy,
            promptBuilder: new AgentPromptBuilder(self::getContainer()->get(SystemPromptBuilder::class)),
            skillsContextBuilder: self::getContainer()->get(\Ineersa\CodingAgent\Skills\SkillsContextBuilder::class),
            artifactRegistry: self::getContainer()->get(\Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry::class),
            agentRunner: $childRunner,
            runStore: $this->pollingChildRunStore($childRunStore),
            parentRunStore: $parentRunStore,
            committedRunEventAppender: self::getContainer()->get(CommittedRunEventAppender::class),
            metadataReader: new SubagentRunMetadataReader($childEventStore),
            childRunDirectory: self::getContainer()->get(\Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory::class),
            contextAccessor: self::getContainer()->get(\Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor::class),
            logger: self::getContainer()->get('logger'),
            agentsConfig: new AgentsConfig(subagentToolTimeoutSeconds: 2),
            progressSnapshotBuilder: new \Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder(),
            childProgressSummaryBuilder: new SubagentChildProgressSummaryBuilder(self::getContainer()->get(\Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStoreFactory::class)),
            agentsContextBuilder: self::getContainer()->get(AgentsContextBuilder::class),
            appConfig: self::getContainer()->get(\Ineersa\CodingAgent\Config\AppConfig::class),
        );
    }

    private function pollingChildRunStore(RunStoreInterface $inner): RunStoreInterface
    {
        return new class($inner) implements RunStoreInterface {
            public function __construct(private RunStoreInterface $inner)
            {
            }

            public function get(string $runId): ?RunState
            {
                $state = $this->inner->get($runId);
                if (null === $state) {
                    return null;
                }

                return new RunState(
                    runId: $state->runId,
                    status: RunStatus::Completed,
                    version: max(1, $state->version),
                    turnNo: $state->turnNo,
                    lastSeq: $state->lastSeq,
                    isStreaming: false,
                    streamingMessage: null,
                    pendingToolCalls: [],
                    errorMessage: null,
                    messages: $state->messages,
                    activeStepId: $state->activeStepId,
                    retryableFailure: false,
                );
            }

            public function compareAndSwap(RunState $state, int $expectedVersion): bool
            {
                return $this->inner->compareAndSwap($state, $expectedVersion);
            }

            public function findRunningStaleBefore(\DateTimeImmutable $updatedBefore): array
            {
                return $this->inner->findRunningStaleBefore($updatedBefore);
            }
        };
    }

    private function emptyMcpToolsResolver(): AgentMcpToolsResolver
    {
        $catalogStore = $this->createStub(\Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface::class);
        $catalogStore->method('read')->willReturn(null);

        return new AgentMcpToolsResolver($catalogStore, TestMcpConfigLoaderFactory::loaderForServers([]));
    }
}
