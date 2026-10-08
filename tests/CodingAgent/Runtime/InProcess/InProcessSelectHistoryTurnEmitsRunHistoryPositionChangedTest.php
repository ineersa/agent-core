<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\InProcess;

use Ineersa\AgentCore\Application\Dto\RunStateReplayResult;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\History\HistorySelectionServiceInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\AgentCore\Tests\Support\TestActiveRunContext;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Agent\Context\AgentsContextBuilder;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateService;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\History\HistoryProjector;
use Ineersa\CodingAgent\Session\History\HistorySelectionService;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Ineersa\CodingAgent\SystemPrompt\AgentsContextDiscovery;
use Ineersa\CodingAgent\SystemPrompt\AgentsContextRenderer;
use Ineersa\CodingAgent\SystemPrompt\SystemPromptBuilder;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

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
        $eventStore = new InMemoryEventStore();
        foreach ($this->sessionEvents() as $event) {
            $eventStore->seed($event);
        }

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
            historyProjector: new HistoryProjector(),
            replayEventPreparer: new ReplayEventPreparer(),
            runCommit: new \Ineersa\AgentCore\Application\Pipeline\RunCommit(
                activeRunContext: $activeRunContext,
                eventStore: $eventStore,
                logger: new NullLogger(),
                finalizer: \Ineersa\AgentCore\Tests\Support\TestTransitionFinalizerFactory::create($eventStore, new \Ineersa\AgentCore\Application\Handler\StepDispatcher(new TestMessageBus(), new TestMessageBus(), new \Ineersa\AgentCore\Tests\Support\TestLogger())),
            ),
        );
        $sink = new InMemoryRuntimeEventSink();

        $this->client($eventStore, $historySelectionService, $sink, $activeRunContext)->send(self::RUN_ID, new UserCommand(
            type: 'select_history_turn',
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
        $repair = $this->createMock(\Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface::class);
        $result = new \Ineersa\CodingAgent\Runtime\Contract\RepairResult(true, false, 'refused', \Ineersa\CodingAgent\Runtime\Contract\SessionRepairRefusalReasonEnum::ActiveStreaming);
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
        $repair = $this->createMock(\Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface::class);
        $repair->expects($this->once())->method('repair')->willThrowException(new \RuntimeException('private detail'));
        $sink = new InMemoryRuntimeEventSink();
        $registry = new TestActiveRunContext();
        $registry->loadRecovered(new RunState(self::RUN_ID, RunStatus::Running));
        $client = $this->client(new InMemoryEventStore(), $this->createStub(HistorySelectionServiceInterface::class), $sink, $registry, $repair);
        try {
            $client->repair(self::RUN_ID);
            $this->fail('Owner repair failure must propagate.');
        } catch (\Symfony\Component\Messenger\Exception\HandlerFailedException $exception) {
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
        InMemoryEventStore $eventStore,
        HistorySelectionServiceInterface $historySelectionService,
        InMemoryRuntimeEventSink $sink,
        TestActiveRunContext $activeRunContext,
        ?\Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface $repair = null,
    ): InProcessAgentSessionClient {
        $container = self::getContainer();

        $handler = new \Ineersa\CodingAgent\Application\Pipeline\SessionMaintenanceHandler($historySelectionService, $repair ?? $this->createStub(\Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface::class), $sink, $container->get(\Ineersa\CodingAgent\Runtime\Stream\StdoutRuntimeEventSink::class), false, new NullLogger(), $activeRunContext, $container->get(\Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor::class), $container->get(HatfieldSessionStore::class), $container->get(\Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware::class), $container->get(\Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery::class), $container->get(\Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository::class));
        $bus = new \Symfony\Component\Messenger\MessageBus([new \Symfony\Component\Messenger\Middleware\HandleMessageMiddleware(new \Symfony\Component\Messenger\Handler\HandlersLocator([
            \Ineersa\CodingAgent\Application\Message\SelectHistoryPrompt::class => [[$handler, 'select']],
            \Ineersa\CodingAgent\Application\Message\RepairSession::class => [[$handler, 'repair']],
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
            transientSink: $sink,
        );
    }
}
