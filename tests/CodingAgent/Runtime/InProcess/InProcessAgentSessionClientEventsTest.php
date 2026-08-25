<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\InProcess;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\History\HistorySelectionServiceInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
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
        $this->assertSame(1, self::$eventStore->reverseForCalls);
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

    private function client(?InMemoryRuntimeEventSink $transientSink = null): InProcessAgentSessionClient
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
            transientSink: $transientSink,
        );
    }
}

/**
 * @internal
 */
final class ReverseOnlyEventStore implements EventStoreInterface
{
    public int $allForCalls = 0;

    public int $reverseForCalls = 0;

    /** @var list<RunEvent> */
    private array $events = [];

    /** @param list<RunEvent> $events */
    public function replace(array $events): void
    {
        $this->events = $events;
        $this->allForCalls = 0;
        $this->reverseForCalls = 0;
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
        foreach ($this->reverseFor($runId) as $event) {
            return $event->seq;
        }

        return null;
    }

    public function firstFor(string $runId): ?RunEvent
    {
        foreach ($this->events as $event) {
            if ($event->runId === $runId) {
                return $event;
            }
        }

        return null;
    }

    public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable
    {
        foreach ($this->events as $event) {
            if ($event->runId === $runId && $event->seq >= $startSeq && $event->seq <= $endSeq) {
                yield $event;
            }
        }
    }

    public function reverseFor(string $runId): iterable
    {
        ++$this->reverseForCalls;
        for ($index = \count($this->events) - 1; $index >= 0; --$index) {
            if ($this->events[$index]->runId === $runId) {
                yield $this->events[$index];
            }
        }
    }

    public function allFor(string $runId): array
    {
        ++$this->allForCalls;

        throw new \LogicException('InProcessAgentSessionClient must use reverseFor().');
    }
}
