<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\TestActiveRunContext;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionToolBatchStore;
use Ineersa\CodingAgent\Session\ToolBatchSnapshotCleanupHookSubscriber;
use Ineersa\CodingAgent\Tests\Session\Support\ParentSessionToolBatchRunStoragePaths;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class ToolBatchSnapshotCleanupHookSubscriberTest extends TestCase
{
    private string $projectDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('tool-batch-cleanup-hook');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir, withSessions: true);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    public function testDeletesExactBatchAfterToolBatchCommitted(): void
    {
        $store = $this->createStore();
        $finalized = new ToolBatchStateDTO([], [], [], [], [], true, 2);
        $store->save('run-1', 3, 'step-x', $finalized);
        $store->save('run-1', 3, 'step-other', new ToolBatchStateDTO([], [], [], [], [], false, 2));

        $subscriber = new ToolBatchSnapshotCleanupHookSubscriber($store, new TestLogger());
        $subscriber->handleAfterTurnCommit(new AfterTurnCommitHookContext(
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
            runState: new RunState('run-1', RunStatus::Running, turnNo: 3),
        ));

        $this->assertNull($store->load('run-1', 3, 'step-x'));
        $this->assertNotNull($store->load('run-1', 3, 'step-other'));
    }

    public function testTerminalAgentEndDeletesAllRemainingSnapshots(): void
    {
        $store = $this->createStore();
        $store->save('run-1', 1, 's1', new ToolBatchStateDTO([], [], [], [], [], false, 2));
        $store->save('run-1', 2, 's2', new ToolBatchStateDTO([], [], [], [], [], false, 2));

        $subscriber = new ToolBatchSnapshotCleanupHookSubscriber($store, new TestLogger());
        $subscriber->handleAfterTurnCommit(new AfterTurnCommitHookContext(
            runId: 'run-1',
            turnNo: 2,
            status: RunStatus::Completed->value,
            events: [new AfterTurnCommitEventSummary(99, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed'])],
            effectsCount: 0,
            runState: new RunState('run-1', RunStatus::Completed, turnNo: 2),
        ));

        $this->assertNull($store->load('run-1', 1, 's1'));
        $this->assertNull($store->load('run-1', 2, 's2'));
    }

    public function testCleanupInvokedAfterSuccessfulRunCommit(): void
    {
        $store = $this->createStore();
        $finalized = new ToolBatchStateDTO([], [], [], [], [], true, 2);
        $store->save('run-1', 1, 'step-1', $finalized);

        $activeRunContext = new TestActiveRunContext();
        $prev = RunState::queued('run-1');
        $activeRunContext->remember($prev);
        $commit = $this->createRunCommit($store, $activeRunContext);

        $next = new RunState(runId: 'run-1', status: RunStatus::Running, version: $prev->version + 1, turnNo: 1, lastSeq: 2, model: 'test-model');
        $events = [
            new RunEvent('run-1', 1, 1, RunEventTypeEnum::ToolBatchCommitted->value, [
                'count' => 1,
                'turn_no' => 1,
                'step_id' => 'step-1',
            ]),
        ];

        $commit->commit($prev, $next, $events, []);
        $this->assertNull($store->load('run-1', 1, 'step-1'));
    }

    private function createStore(): SessionToolBatchStore
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
        );
        $hatfield = new HatfieldSessionStore($appConfig, $entityManager, new \Symfony\Component\EventDispatcher\EventDispatcher());

        [$serializer, $validator] = AttributeSerializerValidatorTestFactory::create();

        return new SessionToolBatchStore(
            new ParentSessionToolBatchRunStoragePaths($hatfield),
            new LockFactory(new FlockStore()),
            new NullLogger(),
            $serializer,
            $validator,
        );
    }

    private function createRunCommit(SessionToolBatchStore $store, TestActiveRunContext $activeRunContext): RunCommit
    {
        $hookDispatcher = new HookDispatcher([
            new ToolBatchSnapshotCleanupHookSubscriber($store, new TestLogger()),
        ]);

        return new RunCommit(
            activeRunContext: $activeRunContext,
            eventStore: new CleanupHookSubscriberNoOpEventStore(),
            commandStore: new InMemoryCommandStore(),
            stepDispatcher: new StepDispatcher(new TestMessageBus(), new TestMessageBus()),
            logger: new TestLogger(),
            hookDispatcher: $hookDispatcher,
        );
    }
}

final class CleanupHookSubscriberNoOpEventStore implements EventStoreInterface
{
    public function append(RunEvent $event): RunEvent
    {
        return $event;
    }

    public function appendMany(array $events): array
    {
        return $events;
    }

    public function latestSequenceFor(string $runId): ?int
    {
        $events = $this->allFor($runId);

        return [] === $events ? null : $events[array_key_last($events)]->seq;
    }

    public function firstFor(string $runId): ?RunEvent
    {
        $events = $this->allFor($runId);

        return $events[0] ?? null;
    }

    public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable
    {
        return [];
    }

    public function reverseFor(string $runId): iterable
    {
        return [];
    }

    public function allFor(string $runId): array
    {
        return [];
    }
}
