<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\HookSubscriberRegistry;
use Ineersa\AgentCore\Application\Handler\InMemoryToolBatchStore;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Replay\HotPromptStateRebuilderInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
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

/**
 * Cleanup runs only after successful RunCommit (AfterTurnCommit hook), not when CAS fails.
 */
final class RunCommitToolBatchCleanupHookTest extends TestCase
{
    public function testCleanupNotInvokedWhenCommitFails(): void
    {
        $store = new InMemoryToolBatchStore();
        $store->save('run-1', 1, 'step-1', ['finalized' => true]);

        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(RunState::queued('run-1'), 0);
        $current = $runStore->get('run-1');
        $this->assertNotNull($current);
        $runStore->compareAndSwap(new RunState(
            runId: 'run-1',
            status: RunStatus::Running,
            version: $current->version + 1,
            turnNo: 1,
            lastSeq: 0,
        ), $current->version);

        $stalePrev = RunState::queued('run-1');

        $serializer = $this->createSerializer();
        $hookDispatcher = new HookDispatcher(new HookSubscriberRegistry([
            new ToolBatchSnapshotCleanupHookSubscriber($store, new TestLogger()),
        ]), new EventDispatcher(), $serializer, $serializer);

        $commit = new RunCommit(
            runStore: $runStore,
            eventStore: new ToolBatchCleanupNoOpEventStore(),
            commandStore: new InMemoryCommandStore(),
            hotPromptStateRebuilder: new ToolBatchCleanupNoOpHotPromptRebuilder(),
            stepDispatcher: new StepDispatcher(new TestMessageBus()),
            logger: new TestLogger(),
            hookDispatcher: $hookDispatcher,
        );

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

    public function testCleanupInvokedAfterSuccessfulCommit(): void
    {
        $store = new InMemoryToolBatchStore();
        $store->save('run-1', 1, 'step-1', ['finalized' => true]);

        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(RunState::queued('run-1'), 0);

        $serializer = $this->createSerializer();
        $hookDispatcher = new HookDispatcher(new HookSubscriberRegistry([
            new ToolBatchSnapshotCleanupHookSubscriber($store, new TestLogger()),
        ]), new EventDispatcher(), $serializer, $serializer);

        $commit = new RunCommit(
            runStore: $runStore,
            eventStore: new ToolBatchCleanupNoOpEventStore(),
            commandStore: new InMemoryCommandStore(),
            hotPromptStateRebuilder: new ToolBatchCleanupNoOpHotPromptRebuilder(),
            stepDispatcher: new StepDispatcher(new TestMessageBus()),
            logger: new TestLogger(),
            hookDispatcher: $hookDispatcher,
        );

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

    private function createSerializer(): Serializer
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

final class ToolBatchCleanupNoOpEventStore implements EventStoreInterface
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

final class ToolBatchCleanupNoOpHotPromptRebuilder implements HotPromptStateRebuilderInterface
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
