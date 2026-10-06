<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
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

        $subscriber = new ToolBatchSnapshotCleanupHookSubscriber($store, new TestLogger(), $this->createStub(\Ineersa\AgentCore\Contract\Tool\ToolLaunchInputStoreInterface::class));
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

        $subscriber = new ToolBatchSnapshotCleanupHookSubscriber($store, new TestLogger(), $this->createStub(\Ineersa\AgentCore\Contract\Tool\ToolLaunchInputStoreInterface::class));
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
        $activeRunContext->loadRecovered($prev);
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

    public function testCanonicalToolResultDeletesFailedLaunchInputWithoutWaitingForSiblings(): void
    {
        $inputStore = $this->createMock(\Ineersa\AgentCore\Contract\Tool\ToolLaunchInputStoreInterface::class);
        $inputStore->expects($this->once())->method('delete')->with('run-1', 'fork-call');
        $inputStore->expects($this->never())->method('deleteAllForRun');
        $subscriber = new ToolBatchSnapshotCleanupHookSubscriber($this->createStore(), new TestLogger(), $inputStore);
        $subscriber->handleAfterTurnCommit(new AfterTurnCommitHookContext(
            runId: 'run-1', turnNo: 1, status: RunStatus::Running->value,
            events: [new AfterTurnCommitEventSummary(1, RunEventTypeEnum::ToolExecutionEnd->value, ['tool_result' => ['tool_call_id' => 'fork-call']])],
            effectsCount: 0, runState: new RunState('run-1', RunStatus::Running, turnNo: 1),
        ));
    }

    public function testFailedCanonicalCommitDoesNotDeleteLaunchInput(): void
    {
        $inputStore = $this->createMock(\Ineersa\AgentCore\Contract\Tool\ToolLaunchInputStoreInterface::class);
        $inputStore->expects($this->never())->method('delete');
        $inputStore->expects($this->never())->method('deleteAllForRun');
        $eventStore = $this->createStub(PreparedTransitionEventStoreInterface::class);
        $eventStore->method('appendTransition')->willThrowException(new \RuntimeException('append failed'));
        $active = new TestActiveRunContext();
        $previous = RunState::queued('run-1');
        $active->loadRecovered($previous);
        $commit = new RunCommit(sourceAcceptance: new \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance(new \Ineersa\AgentCore\Tests\Support\InMemoryCommandStore()),
            activeRunContext: $active, eventStore: $eventStore,
            stepDispatcher: new StepDispatcher(new TestMessageBus(), new TestMessageBus()), logger: new TestLogger(),
            toolBatchCollector: new \Ineersa\AgentCore\Application\Handler\ToolBatchCollector(),
            hookDispatcher: new HookDispatcher([new ToolBatchSnapshotCleanupHookSubscriber($this->createStore(), new TestLogger(), $inputStore)]), toolAuthorization: new \Ineersa\AgentCore\Tests\Support\TestToolExecutionAuthorization(), executionOperations: new \Ineersa\AgentCore\Tests\Support\TestExecutionOperationStore()
        );
        $this->expectExceptionMessage('append failed');
        $commit->commit($previous, new RunState('run-1', RunStatus::Running, version: 1, turnNo: 1, model: 'test-model'), [new RunEvent('run-1', 1, 1, RunEventTypeEnum::ToolExecutionEnd->value, ['tool_result' => ['tool_call_id' => 'fork-call']])]);
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
            new \Ineersa\AgentCore\Tests\Support\InMemoryEventStore(),
        );
    }

    private function createRunCommit(SessionToolBatchStore $store, TestActiveRunContext $activeRunContext): RunCommit
    {
        $hookDispatcher = new HookDispatcher([
            new ToolBatchSnapshotCleanupHookSubscriber($store, new TestLogger(), $this->createStub(\Ineersa\AgentCore\Contract\Tool\ToolLaunchInputStoreInterface::class)),
        ]);

        return new RunCommit(sourceAcceptance: new \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance(new \Ineersa\AgentCore\Tests\Support\InMemoryCommandStore()),
            activeRunContext: $activeRunContext,
            eventStore: new CleanupHookSubscriberNoOpEventStore(),
            stepDispatcher: new StepDispatcher(new TestMessageBus(), new TestMessageBus()),
            logger: new TestLogger(),
            toolBatchCollector: new \Ineersa\AgentCore\Application\Handler\ToolBatchCollector(),
            hookDispatcher: $hookDispatcher, toolAuthorization: new \Ineersa\AgentCore\Tests\Support\TestToolExecutionAuthorization(), executionOperations: new \Ineersa\AgentCore\Tests\Support\TestExecutionOperationStore()
        );
    }
}

final class CleanupHookSubscriberNoOpEventStore implements PreparedTransitionEventStoreInterface
{
    /** @var array<string, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO> */
    private array $pending = [];

    public function appendTransition(array $events, array $work): array
    {
        $runId = $events[0]->runId ?? $work['run_id'] ?? null;
        if (!\is_string($runId) || '' === $runId) {
            throw new \InvalidArgumentException('Prepared transition requires run identity.');
        }
        $this->assertTransitionReady($runId);
        $this->pending[$runId] = new \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO(
            hash('sha256', serialize([$work, $events])),
            0,
            $work,
            array_map(static fn (RunEvent $event): int => $event->seq, $events),
        );

        return $events;
    }

    public function assertTransitionReady(string $runId): void
    {
    }

    public function verifiedPendingTransition(string $runId): ?\Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO
    {
        return $this->pending[$runId] ?? null;
    }

    public function finalizeVerifiedTransition(string $runId, string $identity): void
    {
        if (($this->pending[$runId]->identity ?? null) !== $identity) {
            throw new \RuntimeException('Fixture transition identity mismatch.');
        }
        unset($this->pending[$runId]);
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
