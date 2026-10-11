<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter;
use Ineersa\CodingAgent\Runtime\Controller\SessionBootstrapDelivery;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapDescriptorDTO;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapProducer;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapSpoolStore;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapTransfer;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Revolt\EventLoop;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

final class SessionBootstrapDeliveryTest extends PerMethodIsolatedKernelTestCase
{
    public function testDrainCompletionReadsTheArchiveEvenWithoutANotification(): void
    {
        [$delivery, $descriptor] = $this->prepareTransfer();
        $reflection = new \ReflectionClass($delivery);
        $timeout = $reflection->getProperty('timeout')->getValue($delivery);
        try {
            // The output-drained callback must catch this commit even when no
            // worker stdout notification ever reaches the controller's observer.
            PreparedEventStoreSeeder::appendMany(static::getContainer()->get(PreparedTransitionEventStoreInterface::class), [
                RunEvent::forAppend($descriptor->runId, 1, 'agent_end', ['reason' => 'completed']),
            ]);
            $reflection->getMethod('completeReady')->invoke($delivery, new RuntimeEvent(RuntimeEventTypeEnum::SessionReady->value,
                $descriptor->runId, 0, ['canonical_seq' => $descriptor->canonicalSeq, 'end_offset' => $descriptor->endOffset]), $descriptor->bootstrapId);
            $stream = $reflection->getProperty('stream')->getValue($delivery);
            $this->assertInstanceOf(\Generator::class, $stream);
            $frame = $stream->current();
            $this->assertSame(RuntimeEventTypeEnum::BootstrapSuffix->value, $frame->type);
            $this->assertGreaterThan($descriptor->canonicalSeq, $frame->payload['canonical_seq']);
            $this->assertSame($descriptor->bootstrapId, $frame->payload['bootstrap_id']);
            $this->assertSame($descriptor->viewEpoch, $frame->payload['view_epoch']);
            unset($stream);
        } finally {
            $delivery->cancelForRun($descriptor->runId);
        }
        $this->assertNull($reflection->getProperty('stream')->getValue($delivery));
        $this->assertNull($reflection->getProperty('descriptor')->getValue($delivery));
        $this->assertNotContains($timeout, EventLoop::getIdentifiers());
        $this->assertFalse(static::getContainer()->get(SessionBootstrapSpoolStore::class)->isActive($descriptor->runId));
    }

    public function testExpiredTransferReleasesItsSpoolReaderAndTimeoutWithoutWaiting(): void
    {
        $clock = Clock::get();
        $mock = new MockClock($clock->now());
        Clock::set($mock);
        $delivery = null;
        try {
            [$delivery, $descriptor] = $this->prepareTransfer();
            $reflection = new \ReflectionClass($delivery);
            $timeout = $reflection->getProperty('timeout')->getValue($delivery);
            $mock->sleep(61);
            $reflection->getMethod('pump')->invoke($delivery);
            $this->assertNull($reflection->getProperty('stream')->getValue($delivery));
            $this->assertNull($reflection->getProperty('descriptor')->getValue($delivery));
            $this->assertNotContains($timeout, EventLoop::getIdentifiers());
            $this->assertFalse(static::getContainer()->get(SessionBootstrapSpoolStore::class)->isActive($descriptor->runId));
        } finally {
            $delivery?->cancel();
            Clock::set($clock);
        }
    }

    /** @return array{SessionBootstrapDelivery, SessionBootstrapDescriptorDTO} */
    private function prepareTransfer(): array
    {
        $container = static::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('bootstrap delivery');
        PreparedEventStoreSeeder::appendMany($container->get(PreparedTransitionEventStoreInterface::class), [
            RunEvent::forAppend($run, 0, 'run_started', []),
            RunEvent::forAppend($run, 1, 'turn_advanced', ['turn_no' => 1]),
            RunEvent::forAppend($run, 1, 'agent_end', ['reason' => 'completed']),
        ]);
        $producer = $container->get(SessionBootstrapProducer::class);
        $producer->prepare($run);
        $descriptor = $producer->seal();
        $delivery = new SessionBootstrapDelivery($container->get(SessionBootstrapSpoolStore::class),
            $container->get(SessionBootstrapTransfer::class), new RuntimeEventEmitter(new TestLogger()), new TestLogger());
        $delivery->begin($run, 'attach-command');
        $delivery->expect('attach-request');
        $this->assertTrue($delivery->observe(new RuntimeEvent(RuntimeEventTypeEnum::BootstrapAvailable->value, $run, 0,
            $descriptor->toArray() + ['request_id' => 'attach-request'])));

        return [$delivery, $descriptor];
    }
}
