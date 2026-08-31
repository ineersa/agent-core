<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Application\Dto\RunStateReplayResult;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Execution\AgentMcpToolsResolver;
use Ineersa\CodingAgent\Agent\Execution\AgentPromptBuilder;
use Ineersa\CodingAgent\Agent\Execution\AgentToolPolicyResolver;
use Ineersa\CodingAgent\Agent\Execution\RunStartedMetadataReader;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder;
use Ineersa\CodingAgent\Agent\Execution\SubagentExecutionService;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Session\CommittedRunEventAppender;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\SystemPrompt\SystemPromptBuilder;
use Ineersa\CodingAgent\Tests\Agent\Execution\Support\PipelineCapturingAgentRunner;
use Ineersa\CodingAgent\Tests\Agent\Execution\Support\PromptContractTestSupport;
use Ineersa\CodingAgent\Tests\Agent\Execution\Support\ProviderBoundaryCaptureSupport;
use Ineersa\CodingAgent\Tests\Support\Mcp\TestMcpConfigLoaderFactory;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use PHPUnit\Framework\Attributes\Group;

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

        // Parent run identity must be a pure-digit hatfield_session id.
        // createSession() allocates a real DB PK so ParaTest workers cannot collide.
        $parentRunId = self::getContainer()->get(HatfieldSessionStore::class)->createSession('launch child after context');
        $eventStore = self::getContainer()->get(EventStoreInterface::class);
        $parentRunner = PipelineCapturingAgentRunner::create($eventStore);
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

        $parentState = $this->parentState($parentRunId, $parentCanonical);
        $childEventStore = new InMemoryEventStore();
        $childRunner = PipelineCapturingAgentRunner::create($childEventStore);

        $service = $this->buildSubagentService(
            parentState: $parentState,
            childEventStore: $childEventStore,
            childRunner: $childRunner,
        );

        $accessor = self::getContainer()->get(StackToolExecutionContextAccessor::class);
        $accessor->with(new ToolContext(
            runId: $parentRunId,
            turnNo: 1,
            toolCallId: 'gf05-tool-call',
            toolName: 'subagent',
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 120,
            parentModel: 'test-model',
        ), static fn () => $service->execute($parentRunId, 'gf05-scout', 'Verify inherited AGENTS context'));

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
        RunState $parentState,
        EventStoreInterface $childEventStore,
        PipelineCapturingAgentRunner $childRunner,
    ): SubagentExecutionService {
        $registry = self::getContainer()->get(ToolRegistryInterface::class);
        $policy = new AgentToolPolicyResolver($registry, $this->emptyMcpToolsResolver(), new AgentsConfig());

        return Support\SubagentExecutionServiceFactory::build([
            'catalog' => new AgentDefinitionCatalog([
                new AgentDefinitionDTO(
                    name: 'gf05-scout',
                    description: 'GF05 scout',
                    tools: ['read'],
                    instructions: 'Scout child instructions.',
                ),
            ]),
            'policyResolver' => $policy,
            'promptBuilder' => new AgentPromptBuilder(self::getContainer()->get(SystemPromptBuilder::class)),
            'skillsContextBuilder' => self::getContainer()->get(\Ineersa\CodingAgent\Skills\SkillsContextBuilder::class),
            'artifactRegistry' => self::getContainer()->get(\Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry::class),
            'agentRunner' => $childRunner,
            'runStateRebuilder' => $this->rebuildParentState($parentState),
            'eventStore' => $childEventStore,
            'committedRunEventAppender' => self::getContainer()->get(CommittedRunEventAppender::class),
            'metadataReader' => new RunStartedMetadataReader($childEventStore, AttributeSerializerValidatorTestFactory::denormalizer()),
            'relationshipReader' => \Ineersa\CodingAgent\Tests\Support\StubRunRelationshipReader::topLevel($parentState->runId),
            'childRunDirectory' => self::getContainer()->get(\Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory::class),
            'contextAccessor' => self::getContainer()->get(StackToolExecutionContextAccessor::class),
            'logger' => self::getContainer()->get('logger'),
            'agentsConfig' => new AgentsConfig(subagentToolTimeoutSeconds: 2),
            'childProgressSummaryBuilder' => new SubagentChildProgressSummaryBuilder(),
            'appConfig' => self::getContainer()->get(\Ineersa\CodingAgent\Config\AppConfig::class),
            'modelResolver' => self::getContainer()->get(\Ineersa\CodingAgent\Config\ModelResolver::class),
            'batchRepository' => self::getContainer()->get(\Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository::class),
            'lifecycleListener' => self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\SubagentChildRunBatchLifecycleListener::class),
            'forkLaunchInputBuilder' => self::getContainer()->get(\Ineersa\CodingAgent\Agent\Fork\ForkChildLaunchInputBuilder::class),
            'forkToolPolicyResolver' => self::getContainer()->get(\Ineersa\CodingAgent\Agent\Fork\ForkToolPolicyResolver::class),
            'childExtensionSelection' => self::getContainer()->get(\Ineersa\CodingAgent\Agent\ChildExtensionSelectionService::class),
            'toolRegistry' => self::getContainer()->get(ToolRegistryInterface::class),
        ]);
    }

    private function parentState(string $runId, array $messages): RunState
    {
        return new RunState(
            runId: $runId,
            status: RunStatus::Running,
            version: 1,
            turnNo: 0,
            lastSeq: 1,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: null,
            messages: $messages,
            activeStepId: 'parent-step',
            retryableFailure: false,
            model: 'test-model',
        );
    }

    private function rebuildParentState(RunState $parentState): RunStateRebuilderInterface
    {
        $rebuilder = $this->createStub(RunStateRebuilderInterface::class);
        $rebuilder->method('rebuildIfStale')->willReturn(
            RunStateReplayResult::rebuilt($parentState, $parentState->lastSeq, 1, true),
        );

        return $rebuilder;
    }

    private function emptyMcpToolsResolver(): AgentMcpToolsResolver
    {
        $catalogStore = $this->createStub(\Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface::class);
        $catalogStore->method('read')->willReturn(null);

        return new AgentMcpToolsResolver($catalogStore, TestMcpConfigLoaderFactory::loaderForServers([]));
    }
}
