<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\InProcess;

use Ineersa\AgentCore\Application\Handler\MessageIdempotencyService;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\AgentCore\Application\Pipeline\RunOrchestrator;
use Ineersa\AgentCore\Application\Pipeline\StartRunHandler;
use Ineersa\AgentCore\Application\Replay\PromptStateReplayService;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\StartRunPayload;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\AgentCore\Infrastructure\Storage\HotPromptStateStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Tests\Application\Handler\InMemoryIdempotencyStore;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\AgentCore\Tests\Support\TestSerializerFactory;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\Replay\SessionHotPromptReplayService;
use Ineersa\CodingAgent\Tests\Agent\Execution\Support\PromptContractTestSupport;
use Ineersa\CodingAgent\Tests\Agent\Execution\Support\ProviderBoundaryCaptureSupport;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Uid\Uuid;

/**
 * GF-05 parent-run regression: freeze parent message order and tool surface.
 *
 * Extends {@see PerMethodIsolatedKernelTestCase} because the test mutates the
 * live container via Container::set(AgentRunnerInterface). That base captures
 * and restores the exact exception-handler stack so ParaTest does not mark
 * these methods risky.
 *
 * @group gf-05-prompt-contract
 */
#[Group('gf-05-prompt-contract')]
final class ParentPromptUserContextRegressionTest extends PerMethodIsolatedKernelTestCase
{
    private ParentRegressionCapturingRunner $pipelineRunner;

    public function testParentStartRunPreservesMessageOrderAndProviderRepresentation(): void
    {
        $sessionId = self::getContainer()->get(HatfieldSessionStore::class)->createSession('parent user task');
        self::getContainer()->get(InProcessAgentSessionClient::class)->start(new StartRunRequest(
            prompt: 'parent user task',
            runId: $sessionId,
        ));

        $this->assertNotNull($this->pipelineRunner->lastStartInput);
        $canonical = $this->pipelineRunner->lastStartInput->messages;
        $this->assertNotEmpty($canonical);
        $this->assertSame('', $this->pipelineRunner->lastStartInput->systemPrompt, 'Frozen parent StartRunInput.systemPrompt is empty; system text lives in messages[0].');
        $this->assertSame('system', $canonical[0]->role);

        $runStarted = PromptContractTestSupport::findRunStartedEvent($this->pipelineRunner->eventStore, $sessionId);
        $this->assertNotNull($runStarted);
        PromptContractTestSupport::assertCanonicalMatchesRunStartedMessages(
            $canonical,
            PromptContractTestSupport::messagesFromRunStartedPayload($runStarted->payload),
        );

        $keys = PromptContractTestSupport::roleSourceKeys(PromptContractTestSupport::summarizeMessages($canonical));
        $this->assertSame(
            ['system:', 'user-context:skills_context', 'user-context:agents_definitions_context', 'user:'],
            $keys,
            'Frozen parent order without bare AGENTS.md: system → skills_context → agents_definitions_context → user.',
        );

        $skillsText = PromptContractTestSupport::messageText($canonical[1]);
        $this->assertStringContainsString('gf05-test-skill', $skillsText);
        $agentsDefText = PromptContractTestSupport::messageText($canonical[2]);
        $this->assertStringContainsString('gf05-test-agent', $agentsDefText);

        $capture = ProviderBoundaryCaptureSupport::create(self::getContainer()->get(\Symfony\AI\Agent\Toolbox\ToolboxInterface::class));
        $capture->captureForRun($sessionId, $canonical);
        $providerMessages = $capture->capturedProviderMessages();
        $this->assertNotEmpty($providerMessages);
        $this->assertSame('system', $providerMessages[0]['role']);
        $this->assertSame('user', $providerMessages[array_key_last($providerMessages)]['role']);
        $this->assertStringContainsString('gf05-test-skill', $providerMessages[1]['text']);
        $this->assertStringContainsString('gf05-test-agent', $providerMessages[2]['text']);

        $registry = self::getContainer()->get(ToolRegistryInterface::class);
        $active = $registry->activeToolNames();
        $this->assertNotEmpty($active);
        $providerTools = $capture->capturedProviderToolSchemas();
        $this->assertNotEmpty($providerTools);
    }

    public function testParentStartInjectsBareRootAgentsMdIntoEffectiveContext(): void
    {
        $sentinel = 'GF05_BARE_ROOT_AGENTS_SENTINEL_'.bin2hex(random_bytes(4));
        file_put_contents($this->isolatedCwd().'/AGENTS.md', $sentinel."\n");

        $sessionId = self::getContainer()->get(HatfieldSessionStore::class)->createSession('parent user task');
        self::getContainer()->get(InProcessAgentSessionClient::class)->start(new StartRunRequest(
            prompt: 'parent user task',
            runId: $sessionId,
        ));

        $canonical = $this->pipelineRunner->lastStartInput?->messages ?? [];
        $this->assertNotEmpty($canonical);

        $runStarted = PromptContractTestSupport::findRunStartedEvent($this->pipelineRunner->eventStore, $sessionId);
        $this->assertNotNull($runStarted);
        $runStartedMessages = PromptContractTestSupport::messagesFromRunStartedPayload($runStarted->payload);
        PromptContractTestSupport::assertCanonicalMatchesRunStartedMessages($canonical, $runStartedMessages);

        PromptContractTestSupport::assertSentinelCountInAgentsContext($canonical, $sentinel, 1);
        PromptContractTestSupport::assertSentinelCountInAgentsContext($runStartedMessages, $sentinel, 1);

        $keys = PromptContractTestSupport::roleSourceKeys(PromptContractTestSupport::summarizeMessages($canonical));
        $this->assertSame(
            ['system:', 'user-context:agents_context', 'user-context:skills_context', 'user-context:agents_definitions_context', 'user:'],
            $keys,
            'Parent with bare AGENTS.md: system → agents_context → skills_context → agents_definitions_context → user.',
        );

        $capture = ProviderBoundaryCaptureSupport::create(self::getContainer()->get(\Symfony\AI\Agent\Toolbox\ToolboxInterface::class));
        $capture->captureForRun($sessionId, $canonical);
        PromptContractTestSupport::assertProviderUserMessagesContainSentinelOnce($capture->capturedProviderMessages(), $sentinel);
    }

    /**
     * OS temp (not project var/tmp): AgentsContextDiscovery walks ancestors to
     * filesystem root. A project-tree cwd would always inject monorepo AGENTS.md
     * and break the "without bare AGENTS.md" freeze contract.
     */
    protected function createIsolatedWorkingDirectory(): string
    {
        return TestDirectoryIsolation::createOsTempDir('hatfield-test', 0o750);
    }

    protected function afterKernelBoot(): void
    {
        $this->provisionDeterministicParentContextResources($this->isolatedCwd());
        $this->pipelineRunner = ParentRegressionCapturingRunner::create();
        self::getContainer()->set(AgentRunnerInterface::class, $this->pipelineRunner);
    }

    private function provisionDeterministicParentContextResources(string $cwd): void
    {
        $skillDir = $cwd.'/.agents/skills/gf05-test-skill';
        mkdir($skillDir, 0777, true);
        file_put_contents(
            $skillDir.'/SKILL.md',
            "---\nname: gf05-test-skill\ndescription: GF05 deterministic parent skills_context\n---\n\nGF05 skill body for parent freeze.\n",
        );

        file_put_contents(
            $cwd.'/.agents/gf05-test-agent.md',
            "---\nname: gf05-test-agent\ndescription: GF05 deterministic agents_definitions_context\ntools: read\nforegroundAllowed: true\n---\n\nGF05 agent body.\n",
        );
    }
}

/**
 * @internal
 */
final class ParentRegressionCapturingRunner implements AgentRunnerInterface
{
    public ?StartRunInput $lastStartInput = null;

    public function __construct(
        private readonly RunOrchestrator $orchestrator,
        public readonly InMemoryEventStore $eventStore,
    ) {
    }

    public static function create(): self
    {
        $runStore = new InMemoryRunStore();
        $eventStore = new InMemoryEventStore();
        $commandStore = new InMemoryCommandStore();
        $runCommit = new RunCommit(
            runStore: $runStore,
            eventStore: $eventStore,
            commandStore: $commandStore,
            hotPromptStateRebuilder: new SessionHotPromptReplayService(
                $eventStore,
                new HotPromptStateStore(),
                new PromptStateReplayService(),
                new ReplayEventPreparer(),
            ),
            stepDispatcher: new StepDispatcher(new TestMessageBus()),
            logger: new NullLogger(),
            hookDispatcher: null,
        );
        $processor = new RunMessageProcessor(
            runStore: $runStore,
            idempotency: new MessageIdempotencyService(new InMemoryIdempotencyStore()),
            runLockManager: new RunLockManager(new LockFactory(new InMemoryStore())),
            runCommit: $runCommit,
            stepDispatcher: new StepDispatcher(new TestMessageBus()),
            logger: new NullLogger(),
            handlers: [
                new StartRunHandler(new EventFactory(), TestSerializerFactory::normalizer()),
            ],
        );

        return new self(new RunOrchestrator($processor), $eventStore);
    }

    public function start(StartRunInput $input): string
    {
        $this->lastStartInput = $input;
        $runId = $input->runId ?? Uuid::v4()->toRfc4122();
        $stepId = 'start-'.hrtime(true);
        $this->orchestrator->onStartRun(new StartRun(
            runId: $runId,
            turnNo: 0,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', $runId.'|'.$stepId),
            payload: new StartRunPayload(
                systemPrompt: $input->systemPrompt,
                messages: $input->messages,
                metadata: $input->metadata,
            ),
        ));

        return $runId;
    }

    public function continue(string $runId): void
    {
    }

    public function shell(string $runId, string $rawInput): void
    {
    }

    public function steer(string $runId, \Ineersa\AgentCore\Domain\Message\AgentMessage $message): void
    {
    }

    public function followUp(string $runId, \Ineersa\AgentCore\Domain\Message\AgentMessage $message): void
    {
    }

    public function appendMessage(string $runId, \Ineersa\AgentCore\Domain\Message\AgentMessage $message): void
    {
    }

    public function cancel(string $runId, ?string $reason = null): void
    {
    }

    public function answerHuman(string $runId, string $questionId, mixed $answer): void
    {
    }

    public function compact(string $runId, ?string $customInstructions = null): void
    {
    }

    public function changeModel(string $runId, string $model): void
    {
    }
}
