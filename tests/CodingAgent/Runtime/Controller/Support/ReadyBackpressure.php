<?php

declare(strict_types=1);

use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter;
use Ineersa\CodingAgent\Runtime\Controller\SessionBootstrapDelivery;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapProducer;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapSpoolStore;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapTransfer;
use Ineersa\CodingAgent\Session\Event\ControllerSessionShutdownEvent;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Revolt\EventLoop;

require dirname(__DIR__, 5).'/vendor/autoload.php';
$_ENV['HATFIELD_CWD'] = getcwd();
$_ENV['APP_SECRET'] = 'test-secret';
$_ENV['HATFIELD_CONSUMER_STDOUT_EVENTS'] = $_SERVER['HATFIELD_CONSUMER_STDOUT_EVENTS'] = '0';
putenv('HATFIELD_CONSUMER_STDOUT_EVENTS=0');
$kernel = new Kernel('test', false);
$emitter = null;
try {
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    $run = $container->get(HatfieldSessionStore::class)->createSession('ready backpressure');
    PreparedEventStoreSeeder::appendMany($container->get(PreparedTransitionEventStoreInterface::class), [
        RunEvent::forAppend($run, 0, 'run_started'),
        RunEvent::forAppend($run, 1, 'turn_advanced', ['turn_no' => 1]),
    ]);
    $producer = $container->get(SessionBootstrapProducer::class);
    $producer->prepare($run);
    $cut = $producer->seal();
    [$writer, $reader] = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
    $emitter = new RuntimeEventEmitter(new TestLogger());
    (new ReflectionProperty($emitter, 'stdout'))->setValue($emitter, $writer);
    $delivery = new SessionBootstrapDelivery($container->get(SessionBootstrapSpoolStore::class),
        $container->get(SessionBootstrapTransfer::class), $emitter, new TestLogger());
    $delivery->begin($run, 'attach');
    // The spool has already been consumed; ACK is the tested entry boundary.
    (new ReflectionProperty($delivery, 'descriptor'))->setValue($delivery, $cut);
    (new ReflectionProperty($delivery, 'ended'))->setValue($delivery, true);
    $written = null;
    for ($attempt = 0; $attempt < 16; ++$attempt) {
        $written = fwrite($writer, str_repeat('x', 65536));
        if (0 === $written) {
            break;
        }
    }
    if (0 !== $written) {
        throw new RuntimeException('Socket did not reach the required full-buffer barrier.');
    }
    echo "buffer-full\n";
    // No reader is ever scheduled. The queue callback must still be serviced;
    // the parent's process timeout only contains a blocking-write regression.
    $shutdown = 'shutdown' === ($argv[1] ?? null);
    EventLoop::queue(static function () use ($delivery, $emitter, $run, $shutdown): void {
        if ($shutdown) {
            $delivery->onShutdown(new ControllerSessionShutdownEvent($run));
        } else {
            $delivery->cancelForRun($run);
        }
        $emitter->shutdown();
        echo "cancel-serviced\n";
    });
    $delivery->acknowledge($cut);
    if (null === (new ReflectionProperty($delivery, 'timeout'))->getValue($delivery)) {
        throw new RuntimeException('Ready admission released its timeout before draining.');
    }
    EventLoop::run();
    echo "finished\n";
} finally {
    $emitter?->shutdown();
    if (isset($writer)) {
        fclose($writer);
        fclose($reader);
    }
    $kernel->shutdown();
}
