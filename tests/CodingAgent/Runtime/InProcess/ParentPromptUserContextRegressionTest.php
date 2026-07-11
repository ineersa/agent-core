<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\InProcess;

use Ineersa\AgentCore\Application\Handler\MessageIdempotencyService;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\AgentCore\Application\Pipeline\RunOrchestrator;
use Ineersa\AgentCore\Application\Pipeline\StartRunHandler;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\StartRunPayload;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Infrastructure\Storage\RunEventStore;
use Ineersa\AgentCore\Tests\Application\Handler\InMemoryIdempotencyStore;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\AgentCore\Tests\Support\TestSerializerFactory;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Session\Replay\SessionHotPromptReplayService;
use Ineersa\CodingAgent\Tests\Agent\Execution\Support\PromptContractTestSupport;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use Ineersa\AgentCore\Application\Replay\PromptStateReplayService;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Infrastructure\Storage\HotPromptStateStore;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Uid\Uuid;

/**
 * GF-05 parent-run regression: freeze current parent message order and tool surface.
 *
 * @group gf-05-prompt-contract
 */
#[Group('gf-05-prompt-contract')]
final class ParentPromptUserContextRegressionTest extends PerMethodIsolatedKernelTestCase
{
    private ParentRegressionCapturingRunner $pipelineRunner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pipelineRunner = ParentRegressionCapturingRunner::create();
        self::getContainer()->set(AgentRunnerInterface::class, $this->pipelineRunner);
    }

    public function testParentStartRunPreservesMessageOrderAndProviderRepresentation(): void
    {
        $sessionId = Uuid::v4()->toRfc4122();
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
        $this->assertGreaterThanOrEqual(2, $keys);
        $this->assertSame('system:', $keys[0]);
        $this->assertContains('user-context:skills_context', $keys);
        $this->assertContains('user-context:agents_definitions_context', $keys);
        $skillsIndex = array_search('user-context:skills_context', $keys, true);
        $agentsDefIndex = array_search('user-context:agents_definitions_context', $keys, true);
        $this->assertNotFalse($skillsIndex);
        $this->assertNotFalse($agentsDefIndex);
        $this->assertLessThan($agentsDefIndex, $skillsIndex, 'Frozen parent order: skills_context before agents_definitions_context.');
        $this->assertSame('user:', $keys[array_key_last($keys)]);

        $provider = PromptContractTestSupport::providerVisibleSummaries($canonical);
        $this->assertSame('system', $provider[0]['role']);
        $this->assertSame('user', end($provider)['role']);

        $registry = self::getContainer()->get(ToolRegistryInterface::class);
        $active = $registry->activeToolNames();
        $this->assertNotEmpty($active);
        $toolbox = self::getContainer()->get(\Symfony\AI\Agent\Toolbox\ToolboxInterface::class);
        $allowedSet = array_fill_keys($active, true);
        $providerTools = array_values(array_filter(
            $toolbox->getTools(),
            static fn ($tool): bool => isset($allowedSet[$tool->getName()]),
        ));
        $this->assertNotEmpty($providerTools);
    }

    public function testParentStartInjectsBareRootAgentsMdIntoEffectiveContext(): void
    {
        $sentinel = 'GF05_BARE_ROOT_AGENTS_SENTINEL_'.bin2hex(random_bytes(4));
        file_put_contents($this->isolatedCwd().'/AGENTS.md', $sentinel."
");

        $sessionId = Uuid::v4()->toRfc4122();
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
        $this->assertContains('user-context:agents_context', $keys);
        $agentsIndex = array_search('user-context:agents_context', $keys, true);
        $skillsIndex = array_search('user-context:skills_context', $keys, true);
        $agentsDefIndex = array_search('user-context:agents_definitions_context', $keys, true);
        $this->assertNotFalse($agentsIndex);
        $this->assertNotFalse($skillsIndex);
        $this->assertNotFalse($agentsDefIndex);
        $this->assertLessThan($skillsIndex, $agentsIndex);
        $this->assertLessThan($agentsDefIndex, $skillsIndex);

        $capture = ProviderBoundaryCaptureSupport::create(self::getContainer()->get(\Symfony\AI\Agent\Toolbox\ToolboxInterface::class));
        $capture->captureForRun($sessionId, $canonical);
        PromptContractTestSupport::assertProviderUserMessagesContainSentinelOnce($capture->capturedProviderMessages(), $sentinel);
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
        public readonly RunEventStore $eventStore,
    ) {
    }

    public static function create(): self
    {
        $runStore = new InMemoryRunStore();
        $eventStore = new RunEventStore();
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
}
