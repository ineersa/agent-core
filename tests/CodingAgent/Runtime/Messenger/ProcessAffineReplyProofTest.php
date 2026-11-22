<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Ineersa\CodingAgent\Runtime\Messenger\FiberMessageExecutionStrategy;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueueBrokerSupervisor;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueuePaths;
use Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\SqliteQueue\Messenger\Transport;
use Ineersa\SqliteQueue\Messenger\TransportFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Process\Process;

final class ProcessAffineReplyProofTest extends TestCase
{
    public function testSecondWorkerCompletesOnlyTheWaitingIncarnation(): void
    {
        $directory = TestDirectoryIsolation::createOsTempDir('affine-reply');
        $paths = SessionSqliteQueuePaths::forSession($directory, 'affine');
        $locator = $this->createStub(AppExecutableLocator::class);
        $entry = \dirname(__DIR__, 4).'/bin/console';
        $locator->method('command')->willReturn([\PHP_BINARY, $entry]);
        $locator->method('path')->willReturn($entry);
        $broker = new SessionSqliteQueueBrokerSupervisor(new NullLogger(), new RuntimeProcessConfig($locator, $directory));
        // Exec a fresh PHP runtime; forking PHPUnit inherits active event-loop state.
        $producer = new Process([\PHP_BINARY, '-r', <<<'PHP'
            require $argv[1];
            require $argv[2];
            $test = new \Ineersa\CodingAgent\Tests\Runtime\Messenger\ProcessAffineReplyProofTest('testSecondWorkerCompletesOnlyTheWaitingIncarnation');
            $test->produce(new \Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueuePaths('affine', $argv[3], $argv[4]));
            PHP, \dirname(__DIR__, 4).'/vendor/autoload.php', __FILE__, $paths->databasePath, $paths->endpointPath], timeout: 8);
        try {
            $broker->start($paths);
            $producer->start();
            $pid = $producer->getPid();
            $this->assertNotNull($pid);
            $this->consume($paths, $pid);
            $this->assertSame(0, $producer->wait(), $producer->getErrorOutput());
        } finally {
            $broker->shutdownUntil(microtime(true) + 5.0);
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function produce(SessionSqliteQueuePaths $paths): void
    {
        $work = $this->transport($paths, 'code_mode_requests');
        $reply = $this->transport($paths, 'tool_reply_slot_a');
        $ownReply = $this->transport($paths, 'tool_reply_slot_b');
        $events = new EventDispatcher();
        $worker = $this->worker(['code_mode_requests' => $work, 'tool_reply' => $ownReply], $events, [
            AffineProofWork::class => [static function (AffineProofWork $message) use ($reply): void {
                $pid = (int) getmypid();
                $reply->send(new Envelope(new AffineProofReply('previous-incarnation', $message->invocation, $pid, 99)));
                $reply->send(new Envelope(new AffineProofReply($message->incarnation, $message->invocation, $pid, 42)));
            }],
        ]);
        $handled = false;
        $events->addListener(WorkerMessageHandledEvent::class, static function () use ($worker, &$handled): void {
            $handled = true;
            $worker->stop();
        });
        $events->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event) use ($work, $ownReply): void {
            if ($event->isWorkerIdle()) {
                $work->waitAny([$work->queueName(), $ownReply->queueName()], 1000, new TimeoutCancellation(2));
            }
        });
        $worker->run(['sleep' => 0, 'time_limit' => 6]);
        $this->assertTrue($handled);
    }

    private function consume(SessionSqliteQueuePaths $paths, int $producerPid): void
    {
        $tool = $this->transport($paths, 'tool');
        $work = $this->transport($paths, 'code_mode_requests');
        $reply = $this->transport($paths, 'tool_reply_slot_a');
        $events = new EventDispatcher();
        $pending = [];
        $received = [];
        $ready = false;
        $value = null;
        $handled = [];
        $worker = $this->worker(['tool' => $tool, 'tool_reply' => $reply], $events, [
            AffineProofInvocation::class => [static function (AffineProofInvocation $message) use (&$pending, &$value): void {
                $pending[$message->invocation] = $future = new DeferredFuture();
                try {
                    $value = $future->getFuture()->await(new TimeoutCancellation(5));
                } finally {
                    unset($pending[$message->invocation]);
                }
            }],
            AffineProofReply::class => [static function (AffineProofReply $message) use (&$pending, &$received): void {
                $received[] = $message;
                if ('current-incarnation' === $message->incarnation) {
                    $pending[$message->invocation]->complete($message->value);
                }
            }],
        ]);
        $events->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event) use ($tool, $reply, $work, &$pending, &$ready): void {
            if (!$event->isWorkerIdle()) {
                return;
            }
            // The publisher is released only at the idle WAIT boundary, with the caller suspended.
            if (!$ready && isset($pending['occurrence'])) {
                $work->send(new Envelope(new AffineProofWork('current-incarnation', 'occurrence')));
                $ready = $tool->waitAny([$tool->queueName(), $reply->queueName()], 4000, new TimeoutCancellation(5));
                self::assertTrue($ready, 'WAIT must report reply readiness, not expire and fall back to a receive');

                return;
            }
            $tool->waitAny([$tool->queueName(), $reply->queueName()], 1000, new TimeoutCancellation(2));
        });
        $events->addListener(WorkerMessageHandledEvent::class, static function (WorkerMessageHandledEvent $event) use ($worker, &$handled): void {
            $handled[] = $event->getReceiverName();
            if (3 === \count($handled)) {
                $worker->stop();
            }
        });
        $tool->send(new Envelope(new AffineProofInvocation('occurrence')));
        $worker->run(['sleep' => 0, 'time_limit' => 6]);
        $this->assertTrue($ready);
        $this->assertSame(42, $value);
        $this->assertSame([], $pending);
        $this->assertCount(3, $handled);
        $this->assertSame(2, \count(array_keys($handled, 'tool_reply', true)));
        $this->assertSame(['previous-incarnation', 'current-incarnation'], array_column($received, 'incarnation'));
        $this->assertSame([$producerPid, $producerPid], array_column($received, 'producerPid'));
        $this->assertNotSame(getmypid(), $producerPid);
    }

    /** @param array<string, Transport> $receivers @param array<class-string, list<\Closure>> $handlers */
    private function worker(array $receivers, EventDispatcher $events, array $handlers): Worker
    {
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator($handlers))]);
        $routable = new RoutableMessageBus(new ServiceLocator([]), $bus);
        $events->addListener(WorkerMessageFailedEvent::class, static function (WorkerMessageFailedEvent $event): never {
            throw $event->getThrowable();
        });

        return new Worker($receivers, $routable, $events, messageExecutionStrategy: new FiberMessageExecutionStrategy($routable));
    }

    private function transport(SessionSqliteQueuePaths $paths, string $queue): Transport
    {
        $transport = (new TransportFactory())->createTransport('sqlite-queue://'.$queue.'?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
        $this->assertInstanceOf(Transport::class, $transport);

        return $transport;
    }
}

final readonly class AffineProofInvocation
{
    public function __construct(public string $invocation)
    {
    }
}

final readonly class AffineProofWork
{
    public function __construct(public string $incarnation, public string $invocation)
    {
    }
}

final readonly class AffineProofReply
{
    public function __construct(public string $incarnation, public string $invocation, public int $producerPid, public int $value)
    {
    }
}
