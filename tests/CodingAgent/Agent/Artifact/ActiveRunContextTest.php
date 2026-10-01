<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Agent\Artifact\ActiveRunContext;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Session\History\CacheHistoryProjectionStore;
use Ineersa\CodingAgent\Session\History\HistoryDTO;
use Ineersa\CodingAgent\Session\History\HistoryProjectionSnapshot;
use Ineersa\CodingAgent\Session\History\HistoryProjector;
use Ineersa\CodingAgent\Session\RunState\CacheRunStateStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class ActiveRunContextTest extends IsolatedKernelTestCase
{
    private RunOperationalProjectionRepository $repository;
    private CacheHistoryProjectionStore $history;
    private RunLockManager $locks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get('test.run_operational_projection_repository');
        $lockFactory = new LockFactory(new InMemoryStore());
        $this->locks = new RunLockManager($lockFactory);
        $this->history = new CacheHistoryProjectionStore(new ArrayAdapter(), $lockFactory, new HistoryProjector(), $this->locks);
    }

    public function testCacheMissFailsClosedWithoutArchiveReplay(): void
    {
        $context = $this->context();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Run state projection missing for run run-1');
        $context->stateFor('run-1');
    }

    public function testRememberPersistsSharedStateAndOperationalProjection(): void
    {
        $context = $this->context();
        $state = new RunState('run-1', RunStatus::Running, lastSeq: 2, model: 'test-model');

        $context->remember($state);
        $this->history->remember('run-1', new HistoryProjectionSnapshot(new HistoryDTO([], [], 0), 2));

        $this->assertEquals($state, $context->stateFor('run-1'));
        $this->assertSame(RunStatus::Running, $this->repository->findOperationalStatus('run-1')?->status);
    }

    public function testSeparateConsumersSeeCurrentMessagesWithoutArchiveReads(): void
    {
        $store = $this->store();
        $writer = $this->context($store);
        $reader = $this->context($store);

        $message = new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'hello shared']]);
        $writer->remember(new RunState(
            runId: 'run-shared',
            status: RunStatus::Running,
            lastSeq: 5,
            messages: [$message],
            model: 'shared-model',
        ));
        $this->history->remember('run-shared', new HistoryProjectionSnapshot(new HistoryDTO([], [], 0), 5));

        $seen = $reader->stateFor('run-shared');
        $this->assertSame(5, $seen->lastSeq);
        $this->assertSame('shared-model', $seen->model);
        $this->assertCount(1, $seen->messages);
        $this->assertSame('hello shared', $seen->messages[0]->content[0]['text']);

        $writer->remember(new RunState(
            'run-shared', RunStatus::Running, turnNo: 2, lastSeq: 9,
            messages: [new AgentMessage('user', [['type' => 'text', 'text' => 'newer shared']])],
            model: 'shared-model',
        ));
        $this->history->remember('run-shared', new HistoryProjectionSnapshot(new HistoryDTO([1, 2], [2 => 'newer shared'], 2), 9));
        $updated = $reader->stateFor('run-shared');
        $this->assertSame(9, $updated->lastSeq);
        $this->assertSame(2, $updated->turnNo);
        $this->assertSame('newer shared', $updated->messages[0]->content[0]['text']);
    }

    public function testInvalidateOnlyDropsProcessLocalHotCache(): void
    {
        $context = $this->context();
        $state = new RunState('run-1', RunStatus::Running, lastSeq: 3, model: 'm');
        $context->remember($state);
        $this->history->remember('run-1', new HistoryProjectionSnapshot(new HistoryDTO([], [], 0), 3));
        $context->invalidate('run-1');

        $this->assertSame(3, $context->stateFor('run-1')->lastSeq);
    }

    public function testPersistenceFailureInvalidatesSharedAndLocalState(): void
    {
        $context = $this->context();
        $context->remember(new RunState('run-1', RunStatus::Running, lastSeq: 1, model: 'm'));
        $invalid = new RunState('run-1', RunStatus::Completed, lastSeq: 2, activeStepId: str_repeat('x', 256), model: 'm');

        try {
            $context->remember($invalid);
            $this->fail('Invalid projection must fail.');
        } catch (ValidationFailedException) {
        }

        $this->expectException(\RuntimeException::class);
        $context->stateFor('run-1');
    }

    public function testInitializeQueuedPublishesBootstrapState(): void
    {
        $context = $this->context();
        $queued = $context->initializeQueued('run-new');

        $this->assertSame(RunStatus::Queued, $queued->status);
        $this->assertSame(0, $context->stateFor('run-new')->lastSeq);
    }

    public function testCursorMismatchFailsClosed(): void
    {
        $context = $this->context();
        $context->initializeQueued('run-mismatch');
        $context->remember(new RunState('run-mismatch', RunStatus::Running, lastSeq: 3, model: 'm'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cursor mismatch');
        $context->stateFor('run-mismatch');
    }

    public function testTurnMismatchFailsClosed(): void
    {
        $context = $this->context();
        $context->remember(new RunState('run-turn', RunStatus::Running, turnNo: 2, lastSeq: 3, model: 'm'));
        $this->history->remember('run-turn', new HistoryProjectionSnapshot(new HistoryDTO([1], [1 => 'a'], 1), 3));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('turn mismatch');
        $context->stateFor('run-turn');
    }

    public function testRepeatedBootstrapPreservesExistingMessagesAndHistory(): void
    {
        $context = $this->context();
        $state = new RunState('run-existing', RunStatus::Running, turnNo: 1, lastSeq: 5, model: 'm', messages: [new AgentMessage('user', [['type' => 'text', 'text' => 'retained']])]);
        $context->remember($state);
        $history = new HistoryDTO([1], [1 => 'retained'], 1);
        $this->history->remember('run-existing', new HistoryProjectionSnapshot($history, 5));

        $this->assertEquals($state, $context->initializeQueued('run-existing'));
        $this->assertEquals($history, $this->history->get('run-existing')->history);
    }

    public function testStaleWriterCannotEraseNewerSharedState(): void
    {
        $store = $this->store();
        $newer = $this->context($store);
        $stale = $this->context($store);
        $newer->remember(new RunState('run-stale', RunStatus::Running, lastSeq: 9, model: 'm'));
        $this->history->remember('run-stale', new HistoryProjectionSnapshot(new HistoryDTO([], [], 0), 9));

        try {
            $stale->remember(new RunState('run-stale', RunStatus::Running, lastSeq: 5, model: 'm'));
            $this->fail('Stale writes must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Cannot regress', $exception->getMessage());
        }
        $this->assertSame(9, $newer->stateFor('run-stale')->lastSeq);
    }

    private function context(?CacheRunStateStore $store = null): ActiveRunContext
    {
        return new ActiveRunContext($store ?? $this->store(), $this->repository, $this->locks, $this->history);
    }

    private function store(): CacheRunStateStore
    {
        return new CacheRunStateStore(
            new ArrayAdapter(),
            new LockFactory(new InMemoryStore()),
            AttributeSerializerValidatorTestFactory::serializer(true),
            $this->locks,
        );
    }
}
