<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter;
use Ineersa\CodingAgent\Runtime\Controller\SessionBootstrapDelivery;
use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapDescriptorDTO;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapProducer;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapSpoolStore;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapTransfer;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Ineersa\Tui\Tests\E2E\TuiE2eDatabaseEnv;
use PHPUnit\Framework\Attributes\DataProvider;
use Revolt\EventLoop;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class SessionBootstrapDeliveryTest extends PerMethodIsolatedKernelTestCase
{
    private ?RuntimeEventEmitter $emitter = null;
    /** @var list<resource> */
    private array $sockets = [];

    protected function tearDown(): void
    {
        $this->emitter?->shutdown();
        foreach ($this->sockets as $socket) {
            fclose($socket);
        }
        $this->sockets = [];
        parent::tearDown();
    }

    #[DataProvider('blockedReadyCancellation')]
    public function testReadyBackpressureStillServicesCancellationWithoutAReader(string $mode): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('ready-backpressure');
        try {
            TestDirectoryIsolation::createHatfieldTree($directory);
            $paths = TuiE2eDatabaseEnv::allocateIsolatedPaths(ProjectDir::get(), $directory, 'ready-backpressure');
            $schema = static::getContainer()->get('doctrine.dbal.default_connection')->getParams()['path'];
            (new Filesystem())->copy($schema, $paths['appAbsolute']);
            // A separate owned process contains a blocking-write regression.
            // The full socket and absent reader are deterministic barriers;
            // three seconds is only a safety cap, never readiness synchronization.
            $process = new Process([\PHP_BINARY, __DIR__.'/Support/ReadyBackpressure.php', $mode], $directory, [
                'HATFIELD_SESSION_ID' => false, 'HATFIELD_CWD' => $directory,
                'HATFIELD_TEST_DATABASE_PATH' => $paths['appEnv'],
            ], timeout: 3);
            try {
                $process->run();
            } catch (ProcessTimedOutException $exception) {
                $this->fail('Ready output blocked cancellation after barrier: '.$process->getOutput().' '.$exception->getMessage());
            }
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertStringContainsString("buffer-full\ncancel-serviced\nfinished\n", $process->getOutput());
        } finally {
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    /** @return iterable<array{string}> */
    public static function blockedReadyCancellation(): iterable
    {
        yield ['cancel'];
        yield ['shutdown'];
    }

    public function testReadyPublishesOnlyAfterDrainedCutRecheckAndPrecedesLiveOutput(): void
    {
        [$delivery, $descriptor] = $this->prepareTransfer();
        $reflection = new \ReflectionClass($delivery);
        $emitter = $this->emitter;
        $output = new \ReflectionClass($emitter);
        try {
            $reflection->getProperty('ended')->setValue($delivery, true);
            $delivery->acknowledge($descriptor);
            $pending = $output->getProperty('pending')->getValue($emitter);
            $this->assertNotEmpty($pending, 'Earlier output is the deterministic drain barrier.');
            foreach ($pending as $frame) {
                $this->assertNotSame(RuntimeEventTypeEnum::SessionReady->value, JsonlCodec::decodeEvent($frame['line'])->type);
            }
            PreparedEventStoreSeeder::appendMany(static::getContainer()->get(PreparedTransitionEventStoreInterface::class), [
                RunEvent::forAppend($descriptor->runId, 1, 'history_tail_discarded', ['after_turn_no' => 0]),
            ]);
            // Drain the exact pending record. Its callback rechecks the archive,
            // folds the dropped fence, and admits one final ready without yielding.
            $output->getMethod('flushBootstrap')->invoke($emitter);
            $this->assertNotNull($reflection->getProperty('descriptor')->getValue($delivery));
            $this->assertNotNull($reflection->getProperty('timeout')->getValue($delivery));
            $this->assertFalse($emitter->tryEmit(new RuntimeEvent(RuntimeEventTypeEnum::TurnStarted->value, $descriptor->runId, 4)));
            $pending = $output->getProperty('pending')->getValue($emitter);
            $this->assertSame(['session.ready', 'turn.started'], array_map(static fn (array $frame): string => JsonlCodec::decodeEvent($frame['line'])->type, $pending));
            $output->getMethod('flushBootstrap')->invoke($emitter);
            $this->assertNotNull($reflection->getProperty('descriptor')->getValue($delivery), 'Live output admitted behind ready still owns the drain.');
            $this->assertFalse(stream_get_meta_data($this->sockets[0])['blocked']);
            $output->getMethod('flushBootstrap')->invoke($emitter);
            $this->assertNull($reflection->getProperty('descriptor')->getValue($delivery));
            $this->assertNull($reflection->getProperty('timeout')->getValue($delivery));
            $this->assertTrue($emitter->tryEmit(new RuntimeEvent(RuntimeEventTypeEnum::TurnStarted->value, $descriptor->runId, 5)));
            stream_set_blocking($this->sockets[1], false);
            $lines = array_filter(explode("\n", stream_get_contents($this->sockets[1])));
            $events = array_map(JsonlCodec::decodeEvent(...), $lines);
            $ready = array_values(array_filter($events, static fn (RuntimeEvent $event): bool => RuntimeEventTypeEnum::SessionReady->value === $event->type));
            $this->assertCount(1, $ready);
            $this->assertSame(3, $ready[0]->payload['canonical_seq']);
            $this->assertSame(['current_foreground_response_seq' => null, 'pending_foreground_requests' => []], $ready[0]->payload['foreground']);
            $this->assertSame(RuntimeEventTypeEnum::TurnStarted->value, $events[array_key_last($events)]->type);
            $this->assertSame([4, 5], array_values(array_map(static fn (RuntimeEvent $event): int => $event->seq,
                array_filter($events, static fn (RuntimeEvent $event): bool => RuntimeEventTypeEnum::TurnStarted->value === $event->type))));
            $this->assertSame([], $output->getProperty('pending')->getValue($emitter));
        } finally {
            $delivery->cancel();
        }
    }

    public function testDrainCompletionReadsTheArchiveEvenWithoutANotification(): void
    {
        [$delivery, $descriptor] = $this->prepareTransfer();
        $reflection = new \ReflectionClass($delivery);
        $timeout = $reflection->getProperty('timeout')->getValue($delivery);
        try {
            // Simulate completed frame delivery, then use the real validated ACK
            // path to retain the sealed baseline before deleting the spool.
            $reflection->getProperty('ended')->setValue($delivery, true);
            $delivery->acknowledge($descriptor);
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
            $stream->next();
            $this->assertSame(RuntimeEventTypeEnum::SessionReady->value, $stream->current()->type);
            $this->assertSame(['current_foreground_response_seq' => null, 'pending_foreground_requests' => []], $stream->current()->payload['foreground']);
            unset($stream);
        } finally {
            $delivery->cancelForRun($descriptor->runId);
        }
        $this->assertNull($reflection->getProperty('stream')->getValue($delivery));
        $this->assertNull($reflection->getProperty('descriptor')->getValue($delivery));
        $this->assertNotContains($timeout, EventLoop::getIdentifiers());
        $this->assertFalse(static::getContainer()->get(SessionBootstrapSpoolStore::class)->isActive($descriptor->runId));
    }

    public function testDroppedCanonicalFenceDuringReadyDrainClearsTheRetainedBaseline(): void
    {
        [$delivery, $descriptor] = $this->prepareTransfer();
        $reflection = new \ReflectionClass($delivery);
        try {
            $reflection->getProperty('ended')->setValue($delivery, true);
            $delivery->acknowledge($descriptor);
            $ready = $reflection->getProperty('stream')->getValue($delivery)->current();
            $this->assertSame(2, $ready->payload['foreground']['current_foreground_response_seq']);
            PreparedEventStoreSeeder::appendMany(static::getContainer()->get(PreparedTransitionEventStoreInterface::class), [
                RunEvent::forAppend($descriptor->runId, 1, 'history_tail_discarded', ['after_turn_no' => 0]),
            ]);
            $reflection->getMethod('completeReady')->invoke($delivery, $ready, $descriptor->bootstrapId);
            $updated = $reflection->getProperty('stream')->getValue($delivery)->current();
            $this->assertSame(RuntimeEventTypeEnum::SessionReady->value, $updated->type);
            $this->assertSame($descriptor->bootstrapId, $updated->payload['bootstrap_id']);
            $this->assertGreaterThan($ready->payload['canonical_seq'], $updated->payload['canonical_seq']);
            $this->assertSame(['current_foreground_response_seq' => null, 'pending_foreground_requests' => []], $updated->payload['foreground']);
        } finally {
            $delivery->cancelForRun($descriptor->runId);
        }
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
        ]);
        $producer = $container->get(SessionBootstrapProducer::class);
        $producer->prepare($run);
        $descriptor = $producer->seal();
        $this->sockets = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $this->emitter = new RuntimeEventEmitter(new TestLogger());
        (new \ReflectionProperty($this->emitter, 'stdout'))->setValue($this->emitter, $this->sockets[0]);
        $delivery = new SessionBootstrapDelivery($container->get(SessionBootstrapSpoolStore::class),
            $container->get(SessionBootstrapTransfer::class), $this->emitter, new TestLogger());
        $delivery->begin($run, 'attach-command');
        $delivery->expect('attach-request');
        $this->assertTrue($delivery->observe(new RuntimeEvent(RuntimeEventTypeEnum::BootstrapAvailable->value, $run, 0,
            $descriptor->toArray() + ['request_id' => 'attach-request'])));

        return [$delivery, $descriptor];
    }
}
