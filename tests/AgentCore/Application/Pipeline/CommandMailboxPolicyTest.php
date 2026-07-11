<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\CommandHandlerRegistry;
use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Handler\MessageIdempotencyService;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Pipeline\AdvanceRunHandler;
use Ineersa\AgentCore\Application\Pipeline\ApplyCommandHandler;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Pipeline\LlmStepResultHandler;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\AgentCore\Application\Pipeline\RunOrchestrator;
use Ineersa\AgentCore\Application\Pipeline\StartRunHandler;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
use Ineersa\AgentCore\Application\Replay\PromptStateReplayService;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\StartRunPayload;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\HotPromptStateStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Tests\Application\Handler\InMemoryIdempotencyStore;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\AgentCore\Tests\Support\TestSerializerFactory;
use Ineersa\CodingAgent\Session\Replay\SessionHotPromptReplayService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class CommandMailboxPolicyTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir().'/agent-core-mailbox-'.uniqid('', true);
        mkdir($this->basePath, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);
    }

    public function testSteerCommandsSupersedeWhenDrainModeOneAtATime(): void
    {
        $fixture = $this->createFixture(steerDrainMode: 'one_at_a_time');
        $runId = 'run-mailbox-steer';

        $fixture->orchestrator->onStartRun($this->startRun($runId));

        $fixture->orchestrator->onApplyCommand($this->steerCommand($runId, 'steer-1', 'first steer'));
        $fixture->orchestrator->onApplyCommand($this->steerCommand($runId, 'steer-2', 'latest steer'));

        $fixture->orchestrator->onAdvanceRun(new AdvanceRun(
            runId: $runId,
            turnNo: 0,
            stepId: 'advance-1',
            attempt: 1,
            idempotencyKey: 'advance-idemp-1',
        ));

        $state = $fixture->runStore->get($runId);
        $this->assertNotNull($state);

        $userMessages = array_values(array_filter(
            $state->messages,
            static fn (object $message): bool => $message instanceof AgentMessage
                && 'user' === $message->role,
        ));

        $this->assertCount(2, $userMessages);
        $this->assertSame('latest steer', $userMessages[1]->content[0]['text']);

        $events = $fixture->eventStore->allFor($runId);

        $superseded = array_values(array_filter(
            $events,
            static fn (\Ineersa\AgentCore\Domain\Event\RunEvent $event): bool => 'agent_command_superseded' === $event->type
                && 'steer-1' === ($event->payload['idempotency_key'] ?? null),
        ));
        $applied = array_values(array_filter(
            $events,
            static fn (\Ineersa\AgentCore\Domain\Event\RunEvent $event): bool => 'agent_command_applied' === $event->type
                && 'steer-2' === ($event->payload['idempotency_key'] ?? null),
        ));

        $this->assertCount(1, $superseded);
        $this->assertCount(1, $applied);
    }

    public function testQueueCapRejectsNonCancelCommands(): void
    {
        $fixture = $this->createFixture(maxPendingCommands: 1);
        $runId = 'run-mailbox-cap';

        $fixture->orchestrator->onStartRun($this->startRun($runId));

        $fixture->orchestrator->onApplyCommand($this->steerCommand($runId, 'cap-steer-1', 'queued'));
        $fixture->orchestrator->onApplyCommand(new ApplyCommand(
            runId: $runId,
            turnNo: 0,
            stepId: 'follow-up-1',
            attempt: 1,
            idempotencyKey: 'cap-follow-up-1',
            kind: 'follow_up',
            payload: ['message' => [
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => 'follow-up']],
            ]],
        ));

        $rejections = array_values(array_filter(
            $fixture->eventStore->allFor($runId),
            static fn (\Ineersa\AgentCore\Domain\Event\RunEvent $event): bool => 'agent_command_rejected' === $event->type
                && 'cap-follow-up-1' === ($event->payload['idempotency_key'] ?? null),
        ));

        $this->assertCount(1, $rejections);
        $this->assertStringContainsString('mailbox cap', (string) $rejections[0]->payload['reason']);
    }

    public function testContinueIsRejectedWhenCancelTerminalizesRunWithoutActiveWork(): void
    {
        $fixture = $this->createFixture();
        $runId = 'run-mailbox-cancel-vs-continue';

        $fixture->orchestrator->onStartRun($this->startRun($runId));
        $fixture->orchestrator->onAdvanceRun(new AdvanceRun(
            runId: $runId,
            turnNo: 0,
            stepId: 'advance-1',
            attempt: 1,
            idempotencyKey: 'advance-idemp-1',
        ));

        $fixture->orchestrator->onLlmStepResult(new LlmStepResult(
            runId: $runId,
            turnNo: 1,
            stepId: 'advance-1',
            attempt: 1,
            idempotencyKey: 'llm-failed-1',
            assistantMessage: null,
            usage: [],
            stopReason: 'error',
            error: ['message' => 'transient', 'retryable' => true],
        ));

        $fixture->orchestrator->onApplyCommand(new ApplyCommand(
            runId: $runId,
            turnNo: 1,
            stepId: 'cancel-1',
            attempt: 1,
            idempotencyKey: 'cancel-1',
            kind: 'cancel',
            payload: ['reason' => 'user cancel'],
        ));

        $fixture->orchestrator->onApplyCommand(new ApplyCommand(
            runId: $runId,
            turnNo: 1,
            stepId: 'continue-1',
            attempt: 1,
            idempotencyKey: 'continue-1',
            kind: 'continue',
            payload: [],
        ));

        $continueRejections = array_values(array_filter(
            $fixture->eventStore->allFor($runId),
            static fn (\Ineersa\AgentCore\Domain\Event\RunEvent $event): bool => 'agent_command_rejected' === $event->type
                && 'continue-1' === ($event->payload['idempotency_key'] ?? null),
        ));

        $this->assertCount(1, $continueRejections);
        $this->assertStringContainsString(
            'cancelled',
            (string) ($continueRejections[0]->payload['reason'] ?? ''),
        );

        $state = $fixture->runStore->get($runId);
        $this->assertNotNull($state);
        // Failed LLM retry with no streaming/tools: cancel terminalizes immediately (issue #205).
        $this->assertSame(RunStatus::Cancelled, $state->status);

        $agentEnds = array_values(array_filter(
            $fixture->eventStore->allFor($runId),
            static fn (\Ineersa\AgentCore\Domain\Event\RunEvent $event): bool => 'agent_end' === $event->type
                && 'cancelled' === ($event->payload['reason'] ?? null),
        ));
        $this->assertCount(1, $agentEnds);
    }

    public function testContinueSchedulesAdvanceForRetryableFailureWithValidLastRole(): void
    {
        $fixture = $this->createFixture();
        $runId = 'run-mailbox-continue';

        $fixture->orchestrator->onStartRun($this->startRun($runId));
        $fixture->orchestrator->onAdvanceRun(new AdvanceRun(
            runId: $runId,
            turnNo: 0,
            stepId: 'advance-1',
            attempt: 1,
            idempotencyKey: 'advance-idemp-1',
        ));

        $fixture->orchestrator->onLlmStepResult(new LlmStepResult(
            runId: $runId,
            turnNo: 1,
            stepId: 'advance-1',
            attempt: 1,
            idempotencyKey: 'llm-failed-1',
            assistantMessage: null,
            usage: [],
            stopReason: 'error',
            error: ['message' => 'transient', 'retryable' => true],
        ));

        $fixture->orchestrator->onApplyCommand(new ApplyCommand(
            runId: $runId,
            turnNo: 1,
            stepId: 'continue-1',
            attempt: 1,
            idempotencyKey: 'continue-1',
            kind: 'continue',
            payload: [],
        ));

        $state = $fixture->runStore->get($runId);
        $this->assertNotNull($state);
        $this->assertSame(RunStatus::Running, $state->status);

        $advanceCommands = array_values(array_filter(
            $fixture->commandBus->messages,
            static fn (object $message): bool => $message instanceof AdvanceRun,
        ));

        $this->assertCount(1, $advanceCommands);
    }

    public function testStopBoundaryReturnsShouldContinueTrueWhenFollowUpApplied(): void
    {
        $fixture = $this->createFixture();
        $runId = 'run-stop-boundary-follow-up';

        $fixture->orchestrator->onStartRun($this->startRun($runId));

        $fixture->orchestrator->onAdvanceRun(new AdvanceRun(
            runId: $runId,
            turnNo: 0,
            stepId: 'advance-1',
            attempt: 1,
            idempotencyKey: 'advance-idemp-1',
        ));

        // Queue a follow-up command (stored in command store, pending)
        $fixture->orchestrator->onApplyCommand(new ApplyCommand(
            runId: $runId,
            turnNo: 1,
            stepId: 'follow-up-1',
            attempt: 1,
            idempotencyKey: 'follow-up-1',
            kind: 'follow_up',
            payload: ['message' => [
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => 'follow me up']],
            ]],
        ));

        // Send LLM result with stop_reason='stop', no tool calls, no error
        // This triggers the stop-boundary path in LlmStepResultHandler
        $fixture->orchestrator->onLlmStepResult(new LlmStepResult(
            runId: $runId,
            turnNo: 1,
            stepId: 'advance-1',
            attempt: 1,
            idempotencyKey: 'llm-stop-1',
            assistantMessage: null,
            usage: [],
            stopReason: 'stop',
            error: null,
        ));

        $state = $fixture->runStore->get($runId);
        $this->assertNotNull($state);
        // shouldContinue=true keeps the run Running
        $this->assertSame(RunStatus::Running, $state->status);

        $events = $fixture->eventStore->allFor($runId);
        $appliedFollowUp = array_values(array_filter(
            $events,
            static fn (\Ineersa\AgentCore\Domain\Event\RunEvent $event): bool => 'agent_command_applied' === $event->type
                && 'follow-up-1' === ($event->payload['idempotency_key'] ?? null),
        ));
        $this->assertCount(1, $appliedFollowUp);

        // shouldContinue should have dispatched a follow-up AdvanceRun
        $advanceCommands = array_values(array_filter(
            $fixture->commandBus->messages,
            static fn (object $message): bool => $message instanceof AdvanceRun,
        ));
        // Only one from stop-boundary shouldContinue (ApplyCommandHandler no
        // longer dispatches AdvanceRun while the run is active).
        $this->assertCount(1, $advanceCommands);
    }

    public function testStopBoundaryReturnsShouldContinueTrueWhenSteerApplied(): void
    {
        $fixture = $this->createFixture();
        $runId = 'run-stop-boundary-steer';

        $fixture->orchestrator->onStartRun($this->startRun($runId));

        $fixture->orchestrator->onAdvanceRun(new AdvanceRun(
            runId: $runId,
            turnNo: 0,
            stepId: 'advance-1',
            attempt: 1,
            idempotencyKey: 'advance-idemp-1',
        ));

        // Queue a steer command
        $fixture->orchestrator->onApplyCommand($this->steerCommand($runId, 'stop-steer-1', 'steer at stop boundary'));

        // Send LLM result with stop_reason='stop', no tool calls, no error
        $fixture->orchestrator->onLlmStepResult(new LlmStepResult(
            runId: $runId,
            turnNo: 1,
            stepId: 'advance-1',
            attempt: 1,
            idempotencyKey: 'llm-stop-2',
            assistantMessage: null,
            usage: [],
            stopReason: 'stop',
            error: null,
        ));

        $state = $fixture->runStore->get($runId);
        $this->assertNotNull($state);
        // shouldContinue=true keeps the run Running
        $this->assertSame(RunStatus::Running, $state->status, 'Run should remain Running after steer at stop boundary');

        // Verify steer command was applied
        $events = $fixture->eventStore->allFor($runId);
        $appliedSteer = array_values(array_filter(
            $events,
            static fn (\Ineersa\AgentCore\Domain\Event\RunEvent $event): bool => 'agent_command_applied' === $event->type
                && 'stop-steer-1' === ($event->payload['idempotency_key'] ?? null),
        ));
        $this->assertCount(1, $appliedSteer);

        // Verify follow-up AdvanceRun was dispatched
        $advanceCommands = array_values(array_filter(
            $fixture->commandBus->messages,
            static fn (object $message): bool => $message instanceof AdvanceRun,
        ));
        // Only one from stop-boundary shouldContinue (ApplyCommandHandler no
        // longer dispatches AdvanceRun while the run is active).
        $this->assertCount(1, $advanceCommands);
    }

    public function testStopBoundaryReturnsFalseWhenNoCommandsPending(): void
    {
        $fixture = $this->createFixture();
        $runId = 'run-stop-boundary-no-commands';

        $fixture->orchestrator->onStartRun($this->startRun($runId));

        $fixture->orchestrator->onAdvanceRun(new AdvanceRun(
            runId: $runId,
            turnNo: 0,
            stepId: 'advance-1',
            attempt: 1,
            idempotencyKey: 'advance-idemp-1',
        ));

        // Send LLM result with stop_reason='stop', no tool calls, no error,
        // and NO pending commands -> shouldContinue=false
        $fixture->orchestrator->onLlmStepResult(new LlmStepResult(
            runId: $runId,
            turnNo: 1,
            stepId: 'advance-1',
            attempt: 1,
            idempotencyKey: 'llm-stop-3',
            assistantMessage: null,
            usage: [],
            stopReason: 'stop',
            error: null,
        ));

        $state = $fixture->runStore->get($runId);
        $this->assertNotNull($state);
        // shouldContinue=false should complete the run
        $this->assertSame(RunStatus::Completed, $state->status);

        // No follow-up AdvanceRun should have been dispatched
        $advanceCommands = array_values(array_filter(
            $fixture->commandBus->messages,
            static fn (object $message): bool => $message instanceof AdvanceRun,
        ));
        $this->assertCount(0, $advanceCommands);
    }

    public function testCopyStatePreservesRetryAttempts(): void
    {
        $policy = new CommandMailboxPolicy(
            commandStore: new InMemoryCommandStore(),
            commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
        );

        $state = new RunState(
            runId: 'run-copy-retry',
            status: RunStatus::Running,
            retryAttempts: 2,
        );

        $reflection = new \ReflectionClass($policy);
        $copyState = $reflection->getMethod('copyState');
        /** @var RunState $copied */
        $copied = $copyState->invoke($policy, $state, ['messages' => []]);

        $this->assertSame(2, $copied->retryAttempts);
    }

    private function createFixture(int $maxPendingCommands = 100, string $steerDrainMode = 'one_at_a_time'): CommandMailboxFixture
    {
        $runStore = new InMemoryRunStore();
        $eventStore = new InMemoryEventStore();
        $commandStore = new InMemoryCommandStore();

        $replayService = new SessionHotPromptReplayService($eventStore, new HotPromptStateStore(), new PromptStateReplayService(), new ReplayEventPreparer());

        $commandBus = new TestMessageBus();
        $executionBus = new TestMessageBus();

        $stepDispatcher = new StepDispatcher($executionBus);
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            steerDrainMode: $steerDrainMode,
        );
        $toolBatchCollector = new ToolBatchCollector();

        $runCommit = new RunCommit(
            runStore: $runStore,
            eventStore: $eventStore,
            commandStore: $commandStore,
            hotPromptStateRebuilder: $replayService,
            stepDispatcher: $stepDispatcher,
            logger: new NullLogger(),
            hookDispatcher: null,
        );

        $runMessageProcessor = new RunMessageProcessor(
            runStore: $runStore,
            idempotency: new MessageIdempotencyService(new InMemoryIdempotencyStore()),
            runLockManager: new RunLockManager(new LockFactory(new InMemoryStore())),
            runCommit: $runCommit,
            stepDispatcher: $stepDispatcher,
            logger: new NullLogger(),
            handlers: [
                new StartRunHandler(
                    eventFactory: new \Ineersa\AgentCore\Domain\Event\EventFactory(),
                    normalizer: TestSerializerFactory::normalizer(),
                ),
                new ApplyCommandHandler(
                    commandStore: $commandStore,
                    commandRouter: $commandRouter,
                    commandMailboxPolicy: $commandMailboxPolicy,
                    eventFactory: new \Ineersa\AgentCore\Domain\Event\EventFactory(),
                    messageNormalizer: new \Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer(),
                    maxPendingCommands: $maxPendingCommands,
                    commandBus: $commandBus,
                ),
                new AdvanceRunHandler(
                    commandMailboxPolicy: $commandMailboxPolicy,
                    eventFactory: new \Ineersa\AgentCore\Domain\Event\EventFactory(),
                ),
                new LlmStepResultHandler(
                    toolBatchCollector: $toolBatchCollector,
                    commandMailboxPolicy: $commandMailboxPolicy,
                    eventFactory: new \Ineersa\AgentCore\Domain\Event\EventFactory(),
                    toolCallExtractor: new \Ineersa\AgentCore\Application\Pipeline\ToolCallExtractor(),
                    messageNormalizer: new \Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer(),
                    stepDispatcher: $stepDispatcher,
                    commandBus: $commandBus,
                ),
                new ToolCallResultHandler(
                    toolBatchCollector: $toolBatchCollector,
                    eventFactory: new \Ineersa\AgentCore\Domain\Event\EventFactory(),
                    toolCallExtractor: new \Ineersa\AgentCore\Application\Pipeline\ToolCallExtractor(),
                    messageNormalizer: new \Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer(),
                ),
            ],
        );

        $orchestrator = new RunOrchestrator(
            runMessageProcessor: $runMessageProcessor,
        );

        return new CommandMailboxFixture($orchestrator, $runStore, $eventStore, $commandStore, $commandBus);
    }

    private function startRun(string $runId): StartRun
    {
        return new StartRun(
            runId: $runId,
            turnNo: 0,
            stepId: 'start-1',
            attempt: 1,
            idempotencyKey: 'start-idemp-1',
            payload: new StartRunPayload(messages: [new AgentMessage(
                role: 'user',
                content: [[
                    'type' => 'text',
                    'text' => 'hello',
                ]],
            )]),
        );
    }

    private function steerCommand(string $runId, string $idempotencyKey, string $text): ApplyCommand
    {
        return new ApplyCommand(
            runId: $runId,
            turnNo: 0,
            stepId: 'steer-'.$idempotencyKey,
            attempt: 1,
            idempotencyKey: $idempotencyKey,
            kind: 'steer',
            payload: [
                'message' => [
                    'role' => 'user',
                    'content' => [[
                        'type' => 'text',
                        'text' => $text,
                    ]],
                ],
            ],
        );
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());

                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }
}

final readonly class CommandMailboxFixture
{
    public function __construct(
        public RunOrchestrator $orchestrator,
        public InMemoryRunStore $runStore,
        public InMemoryEventStore $eventStore,
        public InMemoryCommandStore $commandStore,
        public TestMessageBus $commandBus,
    ) {
    }
}
