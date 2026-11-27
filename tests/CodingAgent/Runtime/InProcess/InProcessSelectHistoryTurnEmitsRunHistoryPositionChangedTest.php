<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\InProcess;

use Ineersa\AgentCore\Application\DTO\RunStateReplayResult;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\History\HistorySelectionServiceInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\AgentCore\Tests\Support\TestActiveRunContext;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\AgentCore\Tests\Support\TestTransitionFinalizerFactory;
use Ineersa\CodingAgent\Agent\Context\AgentsContextBuilder;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\ChildInvocationBackgroundService;
use Ineersa\CodingAgent\Application\Message\RepairSession;
use Ineersa\CodingAgent\Application\Message\SelectHistoryEntry;
use Ineersa\CodingAgent\Application\Pipeline\SessionMaintenanceHandler;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateService;
use Ineersa\CodingAgent\Runtime\BackgroundWorkSnapshotProvider;
use Ineersa\CodingAgent\Runtime\Contract\RepairResult;
use Ineersa\CodingAgent\Runtime\Contract\SessionRepairRefusalReasonEnum;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Contract\UserCommandTypeEnum;
use Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Stream\StdoutRuntimeEventSink;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapProducer;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapSpoolStore;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapTransfer;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\History\HistorySelectionService;
use Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface;
use Ineersa\CodingAgent\Session\RunHistoryIndex;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Ineersa\CodingAgent\SystemPrompt\AgentsContextDiscovery;
use Ineersa\CodingAgent\SystemPrompt\AgentsContextRenderer;
use Ineersa\CodingAgent\SystemPrompt\SystemPromptBuilder;
use Ineersa\CodingAgent\Tests\Support\HistoryEventStoreFactory;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\CodingAgent\Tool\BashBackgroundRequestService;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

/**
 * Thesis: an in-process history selection emits the transient position event
 * the runtime protocol needs, in addition to persisting canonical selection.
 * RuntimeEventMapper intentionally drops history_position_set, so without this
 * bridge the transcript cannot rebuild at the selected retained boundary.
 */
#[CoversNothing]
final class InProcessSelectHistoryTurnEmitsRunHistoryPositionChangedTest extends IsolatedKernelTestCase
{
    private const string RUN_ID = 'test-history-select-run';

    #[Test]
    public function sendSelectHistoryTurnPersistsSelectionAndEmitsOneRuntimePositionEvent(): void
    {
        $eventStore = HistoryEventStoreFactory::create(self::getContainer()->get(HatfieldSessionStore::class), $this->sessionEvents());

        $activeRunContext = new TestActiveRunContext();
        $activeRunContext->loadRecovered(new RunState(
            runId: self::RUN_ID,
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 3,
            model: 'test-model',
        ));
        $rebuiltState = new RunState(
            runId: self::RUN_ID,
            status: RunStatus::Running,
            version: 2,
            turnNo: 0,
            lastSeq: 4,
            model: 'test-model',
        );
        $rebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $rebuilder->expects($this->once())
            ->method('rebuildAtPosition')
            ->with($this->isInstanceOf(RunState::class), self::RUN_ID, 0)
            ->willReturn(RunStateReplayResult::rebuilt($rebuiltState));

        $historySelectionService = new HistorySelectionService(
            eventStore: $eventStore,
            runStateRebuilder: $rebuilder,
            activeRunContext: $activeRunContext,
            lockManager: new RunLockManager(new LockFactory(new InMemoryStore())),
            logger: new NullLogger(),
            historyIndex: self::getContainer()->get(RunHistoryIndex::class),
            runCommit: new RunCommit(
                activeRunContext: $activeRunContext,
                eventStore: $eventStore,
                logger: new NullLogger(),
                finalizer: TestTransitionFinalizerFactory::create($eventStore, new StepDispatcher(new TestMessageBus(), new TestMessageBus(), new TestLogger(), events: new EventDispatcher())),
            ),
        );
        $sink = new InMemoryRuntimeEventSink();

        $this->client($eventStore, $historySelectionService, $sink, $activeRunContext)->send(self::RUN_ID, new UserCommand(
            type: UserCommandTypeEnum::SelectHistoryTurn,
            payload: ['turn_no' => 1],
        ));

        $canonical = $eventStore->allFor(self::RUN_ID);
        $this->assertCount(4, $canonical);
        $this->assertSame(RunEventTypeEnum::HistoryPositionSet->value, $canonical[3]->type);
        $this->assertSame(0, $canonical[3]->payload['position_turn_no']);
        $this->assertSame(1, $canonical[3]->payload['selected_prompt_turn_no']);

        /** @var list<RuntimeEvent> $events */
        $events = iterator_to_array($sink->drain(self::RUN_ID));
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame(RuntimeEventTypeEnum::RunHistoryPositionChanged->value, $event->type);
        $this->assertSame(self::RUN_ID, $event->runId);
        $this->assertSame(0, $event->payload['position_turn_no'] ?? null);
        $this->assertSame(1, $event->payload['selected_prompt_turn_no'] ?? null);
        $this->assertSame(4, $event->seq);
    }

    public function testSynchronousRepairReturnsHandledResultAndEmitsRefusalWithoutExceptionDetails(): void
    {
        $repair = $this->createMock(SessionRepairServiceInterface::class);
        $result = new RepairResult(true, false, 'refused', SessionRepairRefusalReasonEnum::ActiveStreaming);
        $repair->expects($this->once())->method('repair')->with(self::RUN_ID, false)->willReturn($result);
        $sink = new InMemoryRuntimeEventSink();
        $registry = new TestActiveRunContext();
        $registry->loadRecovered(new RunState(self::RUN_ID, RunStatus::Running, isStreaming: true));
        $client = $this->client(new InMemoryEventStore(), $this->createStub(HistorySelectionServiceInterface::class), $sink, $registry, $repair);
        $this->assertSame($result, $client->repair(self::RUN_ID, false));
        $events = iterator_to_array($sink->drain(self::RUN_ID));
        $this->assertCount(1, $events);
        $this->assertSame('session.repair.completed', $events[0]->type);
        $this->assertSame('completed', $events[0]->payload['status']);
        $this->assertSame('active_streaming', $events[0]->payload['refusal_reason']);
        $this->assertArrayNotHasKey('exception_class', $events[0]->payload);
        $this->assertArrayNotHasKey('exception_message', $events[0]->payload);
    }

    public function testOwnerRepairFailureEmitsSanitizedReplyAndPropagatesToSynchronousCaller(): void
    {
        $repair = $this->createMock(SessionRepairServiceInterface::class);
        $repair->expects($this->once())->method('repair')->willThrowException(new \RuntimeException('private detail'));
        $sink = new InMemoryRuntimeEventSink();
        $registry = new TestActiveRunContext();
        $registry->loadRecovered(new RunState(self::RUN_ID, RunStatus::Running));
        $client = $this->client(new InMemoryEventStore(), $this->createStub(HistorySelectionServiceInterface::class), $sink, $registry, $repair);
        try {
            $client->repair(self::RUN_ID);
            $this->fail('Owner repair failure must propagate.');
        } catch (HandlerFailedException $exception) {
            $this->assertSame('private detail', $exception->getPrevious()->getMessage());
        }
        $events = iterator_to_array($sink->drain(self::RUN_ID));
        $this->assertCount(1, $events);
        $this->assertSame('failed', $events[0]->payload['status']);
        $this->assertSame(\RuntimeException::class, $events[0]->payload['exception_class']);
        $this->assertArrayNotHasKey('exception_message', $events[0]->payload);
        $this->assertNotEmpty($events[0]->payload['commandId']);
    }

    /** @return list<RunEvent> */
    private function sessionEvents(): array
    {
        return [
            new RunEvent(self::RUN_ID, 1, 0, RunEventTypeEnum::RunStarted->value, [
                'payload' => ['messages' => [[
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => 'First prompt']],
                ]]],
            ]),
            new RunEvent(self::RUN_ID, 2, 1, RunEventTypeEnum::TurnAdvanced->value, [
                'turn_no' => 1,
                'step_id' => 'follow_up-1',
            ]),
            new RunEvent(self::RUN_ID, 3, 1, RunEventTypeEnum::HistoryPositionSet->value, [
                'position_turn_no' => 1,
                'previous_position_turn_no' => 0,
                'reason' => 'continue',
            ]),
        ];
    }

    private function client(
        EventStoreInterface $eventStore,
        HistorySelectionServiceInterface $historySelectionService,
        InMemoryRuntimeEventSink $sink,
        TestActiveRunContext $activeRunContext,
        ?SessionRepairServiceInterface $repair = null,
    ): InProcessAgentSessionClient {
        $container = self::getContainer();

        $handler = new SessionMaintenanceHandler($historySelectionService, $repair ?? $this->createStub(SessionRepairServiceInterface::class), $sink, $container->get(StdoutRuntimeEventSink::class), false, new NullLogger(), $activeRunContext, $container->get(RunMessageProcessor::class), $container->get(OwnerRunInitializationMiddleware::class), $container->get(PendingTransitionRecovery::class), $container->get(DeferredSubagentBatchRepository::class), $container->get(SessionBootstrapProducer::class), $container->get(RunLockManager::class));
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            SelectHistoryEntry::class => [[$handler, 'select']],
            RepairSession::class => [[$handler, 'repair']],
        ]))]);

        return new InProcessAgentSessionClient(
            runner: $this->createStub(AgentRunnerInterface::class),
            eventStore: $eventStore,
            mapper: $container->get(RuntimeEventMapper::class),
            systemPromptBuilder: $container->get(SystemPromptBuilder::class),
            agentsContextDiscovery: $container->get(AgentsContextDiscovery::class),
            agentsContextRenderer: $container->get(AgentsContextRenderer::class),
            skillsContextBuilder: $container->get(SkillsContextBuilder::class),
            agentsContextBuilder: $container->get(AgentsContextBuilder::class),
            promptTemplateService: $container->get(PromptTemplateService::class),
            sessionMetaStore: $container->get(HatfieldSessionStore::class),
            modelResolver: $container->get(ModelResolver::class),
            commandBus: $bus,
            backgroundRequests: self::getContainer()->get(BashBackgroundRequestService::class),
            backgroundProcesses: self::getContainer()->get(BackgroundProcessManager::class),
            childBackground: self::getContainer()->get(ChildInvocationBackgroundService::class),
            backgroundWork: self::getContainer()->get(BackgroundWorkSnapshotProvider::class),
            bootstrapSpools: self::getContainer()->get(SessionBootstrapSpoolStore::class),
            bootstrapTransfer: self::getContainer()->get(SessionBootstrapTransfer::class),
            transientSink: $sink,
        );
    }
}
