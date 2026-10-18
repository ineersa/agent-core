<?php

declare(strict_types=1);

use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Migrations\StartupDatabaseMigrator;
use Ineersa\CodingAgent\Runtime\Controller\HeadlessController;
use Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter;
use Ineersa\CodingAgent\Runtime\Messenger\SerializedBrokerSendersLocator;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Session\Event\ControllerSessionShutdownEvent;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\Support\ControllerReplayBackgroundProcessSeeder;
use Ineersa\CodingAgent\Tests\Support\OwnedProcessTree;
use Revolt\EventLoop;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/** @var array<int, string> $argv */
require $argv[1].'/vendor/autoload.php';

// Supply application identity without tagging the owned QA process for signals.
$_SERVER['HATFIELD_SESSION_ID'] = getenv('HATFIELD_REPLAY_SESSION_ID');
$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
($container->get(StartupDatabaseMigrator::class))();
$proof = $argv[2];
$terminalMode = $argv[3] ?? 'normal';
$container->get('event_dispatcher')->addListener(ControllerSessionStartingEvent::class, static function () use ($container, $proof, $terminalMode): void {
    $transport = $container->get('messenger.transport.extension_agent');
    $message = new stdClass();
    $message->job = 'startup-job';
    $envelope = new Envelope($message, [new TransportNamesStamp(['extension_agent'])]);
    $locator = $container->get('messenger.senders_locator');
    $publisher = iterator_to_array($locator->getSenders($envelope))['extension_agent'];
    $publisher->send($envelope);
    $received = iterator_to_array($transport->get());
    if (1 !== count($received)) {
        throw new RuntimeException('Startup hook must publish to the ready live broker.');
    }
    $transport->ack($received[0]);
    $consumers = array_values(array_filter(OwnedProcessTree::descendants(getmypid()), static fn (array $child): bool => str_contains($child['cmdline'], 'messenger:consume')));
    $result = ['received' => $received[0]->getMessage()->job, 'consumers' => $consumers,
        'publisher_wrapped' => $locator instanceof SerializedBrokerSendersLocator && $publisher !== $transport,
        'receiver_unchanged' => $container->get('messenger.receiver_locator')->get('extension_agent') === $transport];
    if ('normal' === $terminalMode) {
        file_put_contents($proof, json_encode($result, \JSON_THROW_ON_ERROR));

        return;
    }
    // Run after startup launches real consumers. Close the reader or deliver
    // an OS signal while an open, backpressured reader suspends terminal drain.
    EventLoop::queue(static function () use ($container, $proof, $result, $terminalMode): void {
        $tree = OwnedProcessTree::descendants(getmypid());
        $seed = ControllerReplayBackgroundProcessSeeder::seedAcceptedFinished(getcwd(), getenv('HATFIELD_TEST_DATABASE_PATH'), $_SERVER['HATFIELD_SESSION_ID'], 'terminal-disconnect');
        $lifecycle = false;
        $container->get('event_dispatcher')->addListener(ControllerSessionShutdownEvent::class, static function () use (&$lifecycle): void { $lifecycle = true; });
        [$writer, $reader] = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $emitter = $container->get(RuntimeEventEmitter::class);
        (new ReflectionProperty($emitter, 'stdout'))->setValue($emitter, $writer);
        $signal = match ($terminalMode) {
            'sigterm-terminal' => \SIGTERM, 'sigint-terminal' => \SIGINT, default => null,
        };
        $signalObserved = false;
        $readWatcher = null;
        $wire = '';
        if (null === $signal) {
            fclose($reader);
        } else {
            stream_set_blocking($writer, false);
            stream_set_blocking($reader, false);
            for ($attempt = 0; $attempt < 16 && 0 !== fwrite($writer, str_repeat('x', 65536)); ++$attempt) {
            }
            EventLoop::onSignal($signal, static function (string $watcher) use ($reader, &$signalObserved, &$readWatcher, &$wire): void {
                EventLoop::cancel($watcher);
                $signalObserved = true;
                // Queue release after all callbacks for the actual OS signal.
                EventLoop::queue(static function () use ($reader, &$readWatcher, &$wire): void {
                    $readWatcher = EventLoop::onReadable($reader, static function () use ($reader, &$wire): void { $wire .= stream_get_contents($reader); });
                });
            });
            // Readability proves the buffer is full without consuming it. This
            // I/O callback runs after signal watchers activate and shutdown awaits.
            EventLoop::onReadable($reader, static function (string $watcher) use ($emitter, $writer, $signal): void {
                EventLoop::cancel($watcher);
                $drain = (new ReflectionProperty($emitter, 'terminalDrain'))->getValue($emitter);
                if (null === $drain || $drain->getFuture()->isComplete() || 0 !== fwrite($writer, 'x')) {
                    throw new RuntimeException('Signal barrier requires an active backpressured terminal drain.');
                }
                if (0 === posix_geteuid() || false !== getenv('HATFIELD_SESSION_ID') || !posix_kill(getmypid(), $signal)) {
                    throw new RuntimeException('Cannot signal an unsafe or unavailable QA fixture.');
                }
            });
        }
        (new ReflectionMethod(HeadlessController::class, 'shutdown'))->invoke($container->get(HeadlessController::class), new RuntimeEvent('protocol.error', '', 0, ['fatal' => true, 'error' => 'fixture broker failure']));
        if (null !== $signal) {
            $wire .= stream_get_contents($reader);
            EventLoop::cancel($readWatcher);
            fclose($reader);
        }
        $result += ['tree' => $tree, 'lifecycle' => $lifecycle,
            'signal_observed' => $signalObserved, 'terminal_written' => str_contains($wire, 'fixture broker failure'),
            'background_removed' => !ControllerReplayBackgroundProcessSeeder::recordExists(getcwd(), getenv('HATFIELD_TEST_DATABASE_PATH'), $seed['id']) && !file_exists($seed['log']) && !file_exists($seed['status']) && !file_exists($seed['pid'])];
        file_put_contents($proof, json_encode($result, \JSON_THROW_ON_ERROR));
        fclose($writer);
        EventLoop::getDriver()->stop();
    });
});
try {
    $exitCode = $container->get(HeadlessController::class)->run();
} finally {
    $kernel->shutdown();
}
exit($exitCode);
