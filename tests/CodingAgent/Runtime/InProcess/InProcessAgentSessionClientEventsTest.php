<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\InProcess;

use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\History\HistorySelectionServiceInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\RefreshRunContext;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Agent\Context\AgentsContextBuilder;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateService;
use Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Ineersa\CodingAgent\SystemPrompt\AgentsContextDiscovery;
use Ineersa\CodingAgent\SystemPrompt\AgentsContextRenderer;
use Ineersa\CodingAgent\SystemPrompt\SystemPromptBuilder;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @covers \Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient
 */
final class InProcessAgentSessionClientEventsTest extends IsolatedKernelTestCase
{
    private const string RUN_ID = 'in-process-events';

    private static ReverseOnlyEventStore $eventStore;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$eventStore = new ReverseOnlyEventStore();
        self::getContainer()->set(EventStoreInterface::class, self::$eventStore);
    }

    #[Test]
    public function eventsYieldsTransientsThenChronologicalUnseenCanonicalEventsWithoutAllFor(): void
    {
        self::$eventStore->replace([
            new RunEvent(self::RUN_ID, 1, 0, RunEventTypeEnum::RunStarted->value, []),
            new RunEvent(self::RUN_ID, 2, 0, RunEventTypeEnum::ToolBatchCommitted->value, []),
            new RunEvent(self::RUN_ID, 4, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
        ]);
        $transientSink = new InMemoryRuntimeEventSink();
        $transientSink->emit(new RuntimeEvent(
            RuntimeEventTypeEnum::AssistantTextDelta->value,
            self::RUN_ID,
            0,
            ['block_id' => 'text-1', 'delta' => 'streamed'],
        ));

        $events = iterator_to_array($this->client($transientSink)->events(self::RUN_ID, 1));

        $this->assertSame([0, 4], array_map(static fn (RuntimeEvent $event): int => $event->seq, $events));
        $this->assertSame(RuntimeEventTypeEnum::AssistantTextDelta->value, $events[0]->type);
        $this->assertSame(RuntimeEventTypeEnum::TurnStarted->value, $events[1]->type);
        $this->assertSame(1, self::$eventStore->readAfterSeqCalls);
        // Physical suffix reads stop once the cursor is reached.
        $this->assertSame(0, self::$eventStore->allForCalls);
    }

    #[Test]
    public function eventsReturnsNoRepeatedCursorEventsAndDeliversTerminalFollowUp(): void
    {
        self::$eventStore->replace([
            new RunEvent(self::RUN_ID, 1, 0, RunEventTypeEnum::RunStarted->value, []),
            new RunEvent(self::RUN_ID, 3, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
        ]);

        $this->assertSame([], iterator_to_array($this->client()->events(self::RUN_ID, 3)));

        self::$eventStore->append(new RunEvent(self::RUN_ID, 5, 1, RunEventTypeEnum::AgentEnd->value, ['status' => 'completed']));
        $followUp = iterator_to_array($this->client()->events(self::RUN_ID, 3));

        $this->assertSame([5], array_map(static fn (RuntimeEvent $event): int => $event->seq, $followUp));
        $this->assertSame(RuntimeEventTypeEnum::RunCompleted->value, $followUp[0]->type);
        $this->assertSame(0, self::$eventStore->allForCalls);
    }

    #[Test]
    public function eventsReadsOnlyLargeHistoryTailAfterCursor(): void
    {
        $events = [];
        for ($seq = 1; $seq <= 2000; ++$seq) {
            $events[] = new RunEvent(
                self::RUN_ID,
                $seq,
                1,
                RunEventTypeEnum::TurnAdvanced->value,
                ['turn_no' => 1],
            );
        }
        self::$eventStore->replace($events);

        $unseen = iterator_to_array($this->client()->events(self::RUN_ID, 1997));

        $this->assertSame([1998, 1999, 2000], array_map(static fn (RuntimeEvent $event): int => $event->seq, $unseen));
        $this->assertSame(1, self::$eventStore->readAfterSeqCalls);
        $this->assertSame(0, self::$eventStore->allForCalls);
    }

    #[Test]
    public function eventsReplaysAllVisibleCanonicalEventsWhenCursorIsZero(): void
    {
        self::$eventStore->replace([
            new RunEvent(self::RUN_ID, 1, 0, RunEventTypeEnum::RunStarted->value, []),
            new RunEvent(self::RUN_ID, 3, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
        ]);

        $events = iterator_to_array($this->client()->events(self::RUN_ID));

        $this->assertSame([1, 3], array_map(static fn (RuntimeEvent $event): int => $event->seq, $events));
        $this->assertSame(0, self::$eventStore->allForCalls);
    }

    #[Test]
    public function attachRefreshesGeneratedInstructionsOnEveryResume(): void
    {
        $runId = self::getContainer()->get(HatfieldSessionStore::class)->createSession();
        $bus = new TestMessageBus();
        $client = $this->client(commandBus: $bus);

        $this->assertSame($runId, $client->attach($runId)->runId);
        $this->assertCount(1, $bus->messages);
        $refresh = $bus->messages[0];
        $this->assertInstanceOf(RefreshRunContext::class, $refresh);
        $this->assertSame($runId, $refresh->runId());
        $this->assertSame('system', $refresh->messages[0]->role);
        $this->assertNotEmpty($refresh->messages[0]->content);

        $client->attach($runId);
        $this->assertCount(2, $bus->messages);
        $this->assertInstanceOf(RefreshRunContext::class, $bus->messages[1]);
    }

    #[Test]
    public function attachRejectsMissingSessionWithoutDispatchingRefresh(): void
    {
        $bus = new TestMessageBus();
        try {
            $this->client(commandBus: $bus)->attach('missing-session');
            $this->fail('Missing session must not acquire refreshed state.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Session "missing-session" not found.', $error->getMessage());
        }
        $this->assertSame([], $bus->messages);
    }

    private function client(?InMemoryRuntimeEventSink $transientSink = null, ?TestMessageBus $commandBus = null): InProcessAgentSessionClient
    {
        $container = self::getContainer();

        return new InProcessAgentSessionClient(
            runner: $this->createStub(AgentRunnerInterface::class),
            eventStore: self::$eventStore,
            mapper: $container->get(RuntimeEventMapper::class),
            historySelectionService: $this->createStub(HistorySelectionServiceInterface::class),
            systemPromptBuilder: $container->get(SystemPromptBuilder::class),
            agentsContextDiscovery: $container->get(AgentsContextDiscovery::class),
            agentsContextRenderer: $container->get(AgentsContextRenderer::class),
            skillsContextBuilder: $container->get(SkillsContextBuilder::class),
            agentsContextBuilder: $container->get(AgentsContextBuilder::class),
            promptTemplateService: $container->get(PromptTemplateService::class),
            sessionMetaStore: $container->get(HatfieldSessionStore::class),
            modelResolver: $container->get(ModelResolver::class),
            commandBus: $commandBus ?? new TestMessageBus(),
            sessionRepairService: $this->createStub(\Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface::class),
            transientSink: $transientSink,
            activeRunContext: new class implements ActiveRunContextInterface {
                public function stateFor(string $runId): RunState
                {
                    return RunState::queued($runId)->with(['status' => RunStatus::Completed]);
                }

                public function remember(RunState $state): void
                {
                }

                public function initializeQueued(string $runId): RunState
                {
                    return RunState::queued($runId);
                }

                public function initialize(RunState $state): void
                {
                }

                /**
                 * @param list<RunEvent> $events
                 */
                public function applyCommittedSuffix(string $runId, array $events, callable $advance): RunState
                {
                    return $advance($this->stateFor($runId), $events);
                }

                public function invalidate(string $runId): void
                {
                }

                public function withdrawForCommit(string $runId): void
                {
                    $this->invalidate($runId);
                }

                public function clear(): void
                {
                }
            },
            runStateRebuilder: $this->createStub(\Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface::class),
        );
    }
}

/**
 * @internal
 */
final class ReverseOnlyEventStore implements EventStoreInterface
{
    public int $allForCalls = 0;

    public int $readAfterSeqCalls = 0;

    /** @var list<RunEvent> */
    private array $events = [];

    /** @param list<RunEvent> $events */
    public function replace(array $events): void
    {
        $this->events = $events;
        $this->allForCalls = 0;
        $this->readAfterSeqCalls = 0;
    }

    public function append(RunEvent $event): RunEvent
    {
        $this->events[] = $event;

        return $event;
    }

    public function appendMany(array $events): array
    {
        foreach ($events as $event) {
            $this->append($event);
        }

        return $events;
    }

    public function latestSequenceFor(string $runId): ?int
    {
        $latest = null;
        foreach ($this->rangeFor($runId, 1, \PHP_INT_MAX) as $event) {
            $latest = $event->seq;
        }

        return $latest;
    }

    public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable
    {
        foreach ($this->events as $event) {
            if ($event->runId === $runId && $event->seq >= $startSeq && $event->seq <= $endSeq) {
                yield $event;
            }
        }
    }

    public function readAfterSeq(string $runId, int $cursor): array
    {
        ++$this->readAfterSeqCalls;
        $events = [];
        foreach ($this->rangeFor($runId, 1, \PHP_INT_MAX) as $event) {
            if ($event->seq > $cursor) {
                $events[] = $event;
            }
        }

        return $events;
    }
}
