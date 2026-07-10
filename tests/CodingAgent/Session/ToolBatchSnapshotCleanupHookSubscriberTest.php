<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\HookSubscriberRegistry;
use Ineersa\AgentCore\Application\Handler\InMemoryToolBatchStore;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Replay\HotPromptStateRebuilderInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\PromptState;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Session\ToolBatchSnapshotCleanupHookSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

final class ToolBatchSnapshotCleanupHookSubscriberTest extends TestCase
{
    public function testDeletesExactBatchAfterToolBatchCommitted(): void
    {
        $store = new InMemoryToolBatchStore();
        $store->save('run-1', 3, 'step-x', ['finalized' => true]);
        $store->save('run-1', 3, 'step-other', ['finalized' => false]);

        $logger = new TestLogger();
        $subscriber = new ToolBatchSnapshotCleanupHookSubscriber($store, $logger);

        $context = new AfterTurnCommitHookContext(
            runId: 'run-1',
            turnNo: 3,
            status: RunStatus::Running->value,
            events: [
                new AfterTurnCommitEventSummary(10, RunEventTypeEnum::ToolBatchCommitted->value, [
                    'count' => 1,
                    'turn_no' => 3,
                    'step_id' => 'step-x',
                ]),
            ],
            effectsCount: 0,
        );

        $subscriber->handleAfterTurnCommit($context);

        $this->assertNull($store->load('run-1', 3, 'step-x'));
        $this->assertNotNull($store->load('run-1', 3, 'step-other'));
    }

    public function testTerminalAgentEndDeletesAllRemainingSnapshots(): void
    {
        $store = new InMemoryToolBatchStore();
        $store->save('run-1', 1, 's1', ['finalized' => false]);
        $store->save('run-1', 2, 's2', ['finalized' => false]);

        $subscriber = new ToolBatchSnapshotCleanupHookSubscriber($store, new TestLogger());

        $subscriber->handleAfterTurnCommit(new AfterTurnCommitHookContext(
            runId: 'run-1',
            turnNo: 2,
            status: RunStatus::Completed->value,
            events: [new AfterTurnCommitEventSummary(99, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed'])],
            effectsCount: 0,
        ));

        $this->assertNull($store->load('run-1', 1, 's1'));
        $this->assertNull($store->load('run-1', 2, 's2'));
    }

    public function testCleanupNotInvokedWhenRunCommitFails(): void
    {
        $store = new InMemoryToolBatchStore();
        $store->save('run-1', 1, 'step-1', ['finalized' => true]);

        $stalePrev = RunState::queued('run-1');

        $runStoreForCommit = new InMemoryRunStore();
        $runStoreForCommit->compareAndSwap(RunState::queued('run-1'), 0);
        $live = $runStoreForCommit->get('run-1');
        $this->assertNotNull($live);
        $runStoreForCommit->compareAndSwap(new RunState(
            runId: 'run-1',
            status: RunStatus::Running,
            version: $live->version + 1,
            turnNo: 1,
            lastSeq: 0,
        ), $live->version);

        $commit = $this->createRunCommit($store, $runStoreForCommit);

        $next = new RunState(runId: 'run-1', status: RunStatus::Running, version: $stalePrev->version + 1, turnNo: 1, lastSeq: 2);

        $events = [
            new RunEvent('run-1', 1, 1, RunEventTypeEnum::ToolBatchCommitted->value, [
                'count' => 1,
                'turn_no' => 1,
                'step_id' => 'step-1',
            ]),
        ];

        $this->assertFalse($commit->commit($stalePrev, $next, $events, []));
        $this->assertNotNull($store->load('run-1', 1, 'step-1'));
    }

    public function testCleanupInvokedAfterSuccessfulRunCommit(): void
    {
        $store = new InMemoryToolBatchStore();
        $store->save('run-1', 1, 'step-1', ['finalized' => true]);

        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(RunState::queued('run-1'), 0);

        $commit = $this->createRunCommit($store, $runStore);

        $prev = $runStore->get('run-1');
        $this->assertNotNull($prev);
        $next = new RunState(runId: 'run-1', status: RunStatus::Running, version: $prev->version + 1, turnNo: 1, lastSeq: 2);

        $events = [
            new RunEvent('run-1', 1, 1, RunEventTypeEnum::ToolBatchCommitted->value, [
                'count' => 1,
                'turn_no' => 1,
                'step_id' => 'step-1',
            ]),
        ];

        $this->assertTrue($commit->commit($prev, $next, $events, []));
        $this->assertNull($store->load('run-1', 1, 'step-1'));
    }

    private function createRunCommit(InMemoryToolBatchStore $store, InMemoryRunStore $runStore): RunCommit
    {
        $serializer = $this->createAfterTurnCommitSerializer();
        $hookDispatcher = new HookDispatcher(new HookSubscriberRegistry([
            new ToolBatchSnapshotCleanupHookSubscriber($store, new TestLogger()),
        ]), new EventDispatcher(), $serializer, $serializer);

        return new RunCommit(
            runStore: $runStore,
            eventStore: new CleanupHookSubscriberNoOpEventStore(),
            commandStore: new InMemoryCommandStore(),
            hotPromptStateRebuilder: new CleanupHookSubscriberNoOpHotPromptRebuilder(),
            stepDispatcher: new StepDispatcher(new TestMessageBus()),
            logger: new TestLogger(),
            hookDispatcher: $hookDispatcher,
        );
    }

    private function createAfterTurnCommitSerializer(): Serializer
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());

        return new Serializer([
            new ArrayDenormalizer(),
            new ObjectNormalizer(
                classMetadataFactory: $classMetadataFactory,
                nameConverter: new MetadataAwareNameConverter($classMetadataFactory),
                propertyTypeExtractor: new PropertyInfoExtractor([new PhpDocExtractor(), new ReflectionExtractor()]),
            ),
        ]);
    }
}

final class CleanupHookSubscriberNoOpEventStore implements EventStoreInterface
{
    public function append(RunEvent $event): void
    {
    }

    public function appendMany(array $events): void
    {
    }

    public function allFor(string $runId): array
    {
        return [];
    }
}

final class CleanupHookSubscriberNoOpHotPromptRebuilder implements HotPromptStateRebuilderInterface
{
    public function rebuildHotPromptState(string $runId): PromptState
    {
        return new PromptState(
            runId: $runId,
            source: 'test',
            eventCount: 0,
            lastSeq: 0,
            missingSequences: [],
            isContiguous: true,
            tokenEstimate: 0,
            messages: [],
        );
    }
}
