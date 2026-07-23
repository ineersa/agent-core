<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStoreFactory;
use Ineersa\CodingAgent\Agent\Context\AgentsContextBuilder;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\McpAgentModeEnum;
use Ineersa\CodingAgent\Agent\Definition\McpPolicyDTO;
use Ineersa\CodingAgent\Agent\Execution\AgentMcpToolsResolver;
use Ineersa\CodingAgent\Agent\Execution\AgentPromptBuilder;
use Ineersa\CodingAgent\Agent\Execution\AgentToolPolicyResolver;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder;
use Ineersa\CodingAgent\Agent\Execution\SubagentExecutionService;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;
use Ineersa\CodingAgent\Agent\Execution\SubagentToolSetResolver;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Ineersa\CodingAgent\Session\CommittedRunEventAppender;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Ineersa\CodingAgent\SystemPrompt\SystemPromptBuilder;
use Ineersa\CodingAgent\Tests\Agent\Execution\Support\PipelineCapturingAgentRunner;
use Ineersa\CodingAgent\Tests\Agent\Execution\Support\PromptContractTestSupport;
use Ineersa\CodingAgent\Tests\Agent\Execution\Support\ProviderBoundaryCaptureSupport;
use Ineersa\CodingAgent\Tests\Support\Mcp\TestMcpConfigLoaderFactory;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/**
 * GF-05 immutable RED specification for child subagent prompt/message contract.
 *
 * @group gf-05-prompt-contract
 */
#[Group('gf-05-prompt-contract')]
final class SubagentPromptUserContextContractTest extends IsolatedKernelTestCase
{
    public function testChildRunUsesReviewedUserContextLayoutAndCanonicalRunStarted(): void
    {
        $parentRunId = 'parent-gf05-layout';
        $marker = 'GF05_PROJECT_INSTRUCTION_MARKER';
        $agentsContext = '<project_context><project_instructions path="/x/AGENTS.md">'.$marker.'</project_instructions></project_context>';

        $parentRunStore = new InMemoryRunStore();
        $parentRunStore->compareAndSwap(new RunState(
            runId: $parentRunId,
            status: RunStatus::Running,
            version: 1,
            turnNo: 0,
            lastSeq: 0,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: null,
            messages: [
                new AgentMessage(role: 'system', content: [['type' => 'text', 'text' => 'parent-system']]),
                new AgentMessage(
                    role: 'user-context',
                    content: [['type' => 'text', 'text' => $agentsContext]],
                    metadata: ['source' => 'agents_context'],
                ),
            ],
            activeStepId: 'parent-step',
            retryableFailure: false,
        ),
            0,
        );

        $childRunStore = new InMemoryRunStore();
        $eventStore = new InMemoryEventStore();
        $pipelineRunner = PipelineCapturingAgentRunner::create($childRunStore, $eventStore);

        $service = $this->buildSubagentService(
            parentRunStore: $parentRunStore,
            childRunStore: $childRunStore,
            eventStore: $eventStore,
            agentRunner: $pipelineRunner,
            catalog: new AgentDefinitionCatalog([
                new AgentDefinitionDTO(
                    name: 'gf05-scout',
                    description: 'GF05 scout',
                    tools: ['read'],
                    mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
                    instructions: 'Scout child instructions.',
                ),
            ]),
        );

        self::getContainer()->get(StackToolExecutionContextAccessor::class)->with(new ToolContext(
            runId: $parentRunId,
            turnNo: 1,
            toolCallId: 'gf05-scout-call',
            toolName: 'subagent',
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 120,
        ), static fn () => $service->execute($parentRunId, 'gf05-scout', 'Inspect layout contract'));

        $this->assertNotNull($pipelineRunner->lastStartInput);
        $canonical = $pipelineRunner->lastStartInput->messages;
        $this->assertSame($pipelineRunner->lastStartInput->systemPrompt, PromptContractTestSupport::messageText($canonical[0]));

        $runStarted = PromptContractTestSupport::findRunStartedEvent($eventStore, $pipelineRunner->lastStartInput->runId ?? '');
        $this->assertNotNull($runStarted, 'Child run must emit canonical run_started through real StartRun pipeline.');
        $runStartedMessages = PromptContractTestSupport::messagesFromRunStartedPayload($runStarted->payload);
        PromptContractTestSupport::assertCanonicalMatchesRunStartedMessages($canonical, $runStartedMessages);
        $this->assertSame(
            $pipelineRunner->lastStartInput->systemPrompt,
            PromptContractTestSupport::systemPromptFromRunStartedPayload($runStarted->payload),
        );

        $keys = PromptContractTestSupport::roleSourceKeys(PromptContractTestSupport::summarizeMessages($canonical));
        $this->assertSame(
            ['system:', 'user-context:agents_context', 'user-context:agent_child_contract', 'user:'],
            $keys,
            'Reviewed child layout: system, agents_context user-context, contract, task user.',
        );

        $systemText = PromptContractTestSupport::messageText($canonical[0]);
        $this->assertStringNotContainsString($marker, $systemText, 'AGENTS.md body must not live in child system text.');
        $this->assertStringContainsString($marker, PromptContractTestSupport::messageText($canonical[1]));

        $contractText = PromptContractTestSupport::messageText($canonical[2]);
        $this->assertStringNotContainsString('Allowed tools:', $contractText);

        $provider = PromptContractTestSupport::providerVisibleSummaries($canonical);
        $this->assertCount(4, $provider);
        $this->assertSame('system', $provider[0]['role']);
        $this->assertSame('user', $provider[1]['role']);
        $this->assertStringStartsWith('[user-context] ', $provider[1]['text']);
        $this->assertStringContainsString($agentsContext, $provider[1]['text']);
        $this->assertStringStartsWith('[user-context] ', $provider[2]['text']);
        $this->assertSame('user', $provider[3]['role']);
    }

    public function testChildSystemTextOmitsSynthesizedDynamicMcpDescriptionButProviderSchemaKeepsTool(): void
    {
        $parentRunStore = new InMemoryRunStore();
        $parentRunStore->compareAndSwap(new RunState(
            runId: 'parent-mcp',
            status: RunStatus::Running,
            version: 1,
            turnNo: 0,
            lastSeq: 0,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: null,
            messages: [],
            activeStepId: 'p',
            retryableFailure: false,
        ),
            0,
        );

        $registry = new ToolRegistry();
        $registry->registerTool(
            name: 'read',
            description: 'Read files',
            parametersJsonSchema: ['type' => 'object'],
            handler: $this->dummyHandler(),
            promptLine: 'read path — explicit prompt line',
        );
        $mcpDescription = 'SYNTHESIZED_MCP_DESCRIPTION_SHOULD_NOT_APPEAR_IN_SYSTEM';
        $registry->addDynamicTool(
            name: 'browser__search',
            description: $mcpDescription,
            parametersJsonSchema: ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
            handler: $this->dummyHandler(),
        );
        $registry->registerTool(
            name: 'fork',
            description: 'PARENT_ONLY_FORK_DESCRIPTION',
            parametersJsonSchema: ['type' => 'object'],
            handler: $this->dummyHandler(),
            promptLine: 'fork — parent only',
        );

        $childRunStore = new InMemoryRunStore();
        $eventStore = new InMemoryEventStore();
        $pipelineRunner = PipelineCapturingAgentRunner::create($childRunStore, $eventStore);

        $service = $this->buildSubagentService(
            parentRunStore: $parentRunStore,
            childRunStore: $childRunStore,
            eventStore: $eventStore,
            agentRunner: $pipelineRunner,
            catalog: new AgentDefinitionCatalog([
                new AgentDefinitionDTO(
                    name: 'gf05-mcp',
                    description: 'mcp child',
                    tools: ['read', 'browser__search'],
                    mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
                    instructions: 'Child with MCP tool.',
                ),
            ]),
            registry: $registry,
        );

        self::getContainer()->get(StackToolExecutionContextAccessor::class)->with(new ToolContext(
            runId: 'parent-mcp',
            turnNo: 1,
            toolCallId: 'parent-mcp-call',
            toolName: 'subagent',
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 120,
        ), static fn () => $service->execute('parent-mcp', 'gf05-mcp', 'Use MCP tool'));

        $systemText = PromptContractTestSupport::messageText($pipelineRunner->lastStartInput->messages[0]);
        $this->assertStringContainsString('read path', $systemText);
        $this->assertStringNotContainsString($mcpDescription, $systemText);
        $this->assertStringNotContainsString('browser__search: '.$mcpDescription, $systemText);
        $this->assertStringNotContainsString('PARENT_ONLY_FORK_DESCRIPTION', $systemText);

        $childRunId = $pipelineRunner->lastStartInput->runId;
        $this->assertNotNull($childRunId);

        $resolver = new SubagentToolSetResolver(
            $this->innerToolboxResolver($registry, ['read', 'browser__search', 'fork']),
            new SubagentRunMetadataReader($eventStore),
        );
        $capture = ProviderBoundaryCaptureSupport::create(
            $this->toolboxFromRegistry($registry),
            $resolver,
        );
        $capture->captureForRun($childRunId, $pipelineRunner->lastStartInput->messages);

        $schemas = $capture->capturedProviderToolSchemas();
        $names = array_column($schemas, 'name');
        $this->assertContains('browser__search', $names);
        $this->assertNotContains('fork', $names);
        $byName = [];
        foreach ($schemas as $schema) {
            $byName[$schema['name']] = $schema['description'];
        }
        $this->assertSame($mcpDescription, $byName['browser__search'] ?? null);
    }

    public function testOrdinaryChildOmitsAgentsDefinitionsContext(): void
    {
        $parentRunStore = new InMemoryRunStore();
        $parentRunStore->compareAndSwap(new RunState(
            runId: 'parent-no-agents-def',
            status: RunStatus::Running,
            version: 1,
            turnNo: 0,
            lastSeq: 0,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: null,
            messages: [
                new AgentMessage(
                    role: 'user-context',
                    content: [['type' => 'text', 'text' => '<agents_instructions><available_agents></available_agents></agents_instructions>']],
                    metadata: ['source' => 'agents_definitions_context'],
                ),
            ],
            activeStepId: 'p',
            retryableFailure: false,
        ),
            0,
        );

        $childRunStore = new InMemoryRunStore();
        $eventStore = new InMemoryEventStore();
        $pipelineRunner = PipelineCapturingAgentRunner::create($childRunStore, $eventStore);
        $service = $this->buildSubagentService(
            parentRunStore: $parentRunStore,
            childRunStore: $childRunStore,
            eventStore: $eventStore,
            agentRunner: $pipelineRunner,
            catalog: new AgentDefinitionCatalog([
                new AgentDefinitionDTO(
                    name: 'gf05-worker',
                    description: 'worker',
                    tools: ['read'],
                    mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
                    instructions: 'Worker.',
                ),
            ]),
        );

        self::getContainer()->get(StackToolExecutionContextAccessor::class)->with(new ToolContext(
            runId: 'parent-no-agents-def',
            turnNo: 1,
            toolCallId: 'parent-no-agents-call',
            toolName: 'subagent',
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 120,
        ), static fn () => $service->execute('parent-no-agents-def', 'gf05-worker', 'Task'));

        foreach ($pipelineRunner->lastStartInput->messages as $message) {
            $this->assertNotSame(
                'agents_definitions_context',
                $message->metadata['source'] ?? null,
                'Ordinary subagents must not receive available-agent definitions context.',
            );
        }
    }

    private function buildSubagentService(
        RunStoreInterface $parentRunStore,
        RunStoreInterface $childRunStore,
        EventStoreInterface $eventStore,
        PipelineCapturingAgentRunner $agentRunner,
        AgentDefinitionCatalog $catalog,
        ?ToolRegistryInterface $registry = null,
    ): SubagentExecutionService {
        $registry ??= self::getContainer()->get(ToolRegistryInterface::class);
        $policy = new AgentToolPolicyResolver($registry, $this->emptyMcpToolsResolver(), new AgentsConfig());

        return Support\SubagentExecutionServiceFactory::build([
            'catalog' => $catalog,
            'policyResolver' => $policy,
            'promptBuilder' => new AgentPromptBuilder(self::getContainer()->get(SystemPromptBuilder::class)),
            'skillsContextBuilder' => self::getContainer()->get(SkillsContextBuilder::class),
            'agentsContextBuilder' => self::getContainer()->get(AgentsContextBuilder::class),
            'artifactRegistry' => self::getContainer()->get(\Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry::class),
            'agentRunner' => $agentRunner,
            'runStore' => $this->pollingChildRunStore($childRunStore),
            'parentRunStore' => $parentRunStore,
            'eventStore' => $eventStore,
            'committedRunEventAppender' => self::getContainer()->get(CommittedRunEventAppender::class),
            'metadataReader' => new SubagentRunMetadataReader($eventStore),
            'childRunDirectory' => self::getContainer()->get(AgentChildRunDirectory::class),
            'contextAccessor' => self::getContainer()->get(StackToolExecutionContextAccessor::class),
            'logger' => self::getContainer()->get('logger'),
            'agentsConfig' => new AgentsConfig(subagentToolTimeoutSeconds: 2),
            'childProgressSummaryBuilder' => new SubagentChildProgressSummaryBuilder(self::getContainer()->get(AgentChildRunEventStoreFactory::class)),
            'appConfig' => self::getContainer()->get(\Ineersa\CodingAgent\Config\AppConfig::class),
            'batchRepository' => self::getContainer()->get(\Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository::class),
            'lifecycleListener' => self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\SubagentChildRunBatchLifecycleListener::class),
            'forkLaunchInputBuilder' => self::getContainer()->get(\Ineersa\CodingAgent\Agent\Fork\ForkChildLaunchInputBuilder::class),
            'forkToolPolicyResolver' => self::getContainer()->get(\Ineersa\CodingAgent\Agent\Fork\ForkToolPolicyResolver::class),
            'modelSelectionService' => self::getContainer()->get(\Ineersa\CodingAgent\Config\ModelSelectionService::class),
        ]);
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
                if (null !== $state) {
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
                        pendingHumanInputRequests: $state->pendingHumanInputRequests,
                    );
                }

                return null;
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
        $catalogStore = $this->createStub(McpToolCatalogStoreInterface::class);
        $catalogStore->method('read')->willReturn(null);

        return new AgentMcpToolsResolver($catalogStore, TestMcpConfigLoaderFactory::loaderForServers([]));
    }

    private function dummyHandler(): ToolHandlerInterface
    {
        return new class implements ToolHandlerInterface {
            public function __invoke(array $arguments): string
            {
                return 'ok';
            }
        };
    }

    /**
     * @param list<string> $toolNames
     */
    private function innerToolboxResolver(ToolRegistry $registry, array $toolNames): \Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface
    {
        return new class($registry, $toolNames) implements \Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface {
            public function __construct(private ToolRegistry $registry, private array $toolNames)
            {
            }

            public function resolve(string $toolsRef, ?int $turnNo = null, ?string $runId = null): \Ineersa\AgentCore\Contract\Tool\ActiveToolSet
            {
                unset($toolsRef, $turnNo, $runId);

                return new \Ineersa\AgentCore\Contract\Tool\ActiveToolSet(toolNames: $this->toolNames);
            }
        };
    }

    private function toolboxFromRegistry(ToolRegistry $registry): ToolboxInterface
    {
        return new class($registry) implements ToolboxInterface {
            public function __construct(private ToolRegistry $registry)
            {
            }

            public function getTools(): array
            {
                $tools = [];
                foreach ($this->registry->activeToolDefinitions() as $definition) {
                    $tools[] = new Tool(
                        reference: new ExecutionReference(self::class),
                        name: $definition->name,
                        description: $definition->description,
                        parameters: $definition->parametersJsonSchema,
                    );
                }

                return $tools;
            }

            public function execute(ToolCall $toolCall): \Symfony\AI\Agent\Toolbox\ToolResult
            {
                throw new \LogicException('not used');
            }
        };
    }
}
