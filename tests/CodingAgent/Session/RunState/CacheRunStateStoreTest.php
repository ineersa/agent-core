<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\RunState;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Session\RunState\CacheRunStateStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class CacheRunStateStoreTest extends TestCase
{
    public function testGetFailsClosedWhenMissing(): void
    {
        $store = $this->store();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Run state projection missing for run missing');
        $store->get('missing');
    }

    public function testRememberRejectsSequenceRegression(): void
    {
        $store = $this->store();
        $store->remember(new RunState('run-1', RunStatus::Running, lastSeq: 5, model: 'm'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already at seq 5');
        $store->remember(new RunState('run-1', RunStatus::Running, lastSeq: 4, model: 'm'));
    }

    public function testSequenceHolesRemainLegal(): void
    {
        $store = $this->store();
        $store->remember(new RunState('run-1', RunStatus::Running, lastSeq: 1, model: 'm'));
        $store->remember(new RunState(
            'run-1',
            RunStatus::Running,
            lastSeq: 4,
            messages: [new AgentMessage('user', [['type' => 'text', 'text' => 'after hole']])],
            model: 'm',
        ));

        $this->assertSame(4, $store->get('run-1')->lastSeq);
        $this->assertSame('after hole', $store->get('run-1')->messages[0]->content[0]['text']);
    }

    public function testInitializeFailsClosedWhenProjectionAlreadyExists(): void
    {
        $store = $this->store();
        $store->initialize(RunState::queued('run-1'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('shared projection already exists');
        $store->initialize(new RunState('run-1', RunStatus::Running, lastSeq: 1, model: 'm'));
    }

    public function testCorruptEntryIsNotTreatedAsAnAbsentRun(): void
    {
        $pool = new ArrayAdapter();
        $store = new CacheRunStateStore($pool, new LockFactory(new InMemoryStore()), AttributeSerializerValidatorTestFactory::serializer(true), new RunLockManager(new LockFactory(new InMemoryStore())));
        $item = $pool->getItem('hatfield.run_state.run-corrupt');
        $item->set('broken projection');
        $pool->save($item);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid run state projection payload');
        $store->find('run-corrupt');
    }

    public function testWithdrawForCommitRejectsOrdinaryGetUntilRemember(): void
    {
        $store = $this->store();
        $store->remember(new RunState('run-1', RunStatus::Running, lastSeq: 2, turnNo: 1, model: 'm'));
        $store->withdrawForCommit('run-1');

        $this->assertFalse($store->isReady('run-1'));
        try {
            $store->get('run-1');
            $this->fail('not-ready projection must fail closed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not ready', $e->getMessage());
        }

        $store->remember(new RunState('run-1', RunStatus::Running, lastSeq: 3, turnNo: 1, model: 'm'));
        $this->assertTrue($store->isReady('run-1'));
        $this->assertSame(3, $store->get('run-1')->lastSeq);
    }

    private function store(): CacheRunStateStore
    {
        return new CacheRunStateStore(
            new ArrayAdapter(),
            new LockFactory(new InMemoryStore()),
            AttributeSerializerValidatorTestFactory::serializer(true),
            new RunLockManager(new LockFactory(new InMemoryStore())),
        );
    }
}
