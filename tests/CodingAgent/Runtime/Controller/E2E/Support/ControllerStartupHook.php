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
$closedTerminal = 'closed-terminal' === ($argv[3] ?? '');
$container->get('event_dispatcher')->addListener(ControllerSessionStartingEvent::class, static function () use ($container, $proof, $closedTerminal): void {
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
    if (!$closedTerminal) {
        file_put_contents($proof, json_encode($result, \JSON_THROW_ON_ERROR));

        return;
    }
    // Run after startup has launched real consumers. Closing the reader makes
    // the terminal drain fail while its owning controller shutdown is suspended.
    EventLoop::queue(static function () use ($container, $proof, $result): void {
        $tree = OwnedProcessTree::descendants(getmypid());
        $seed = ControllerReplayBackgroundProcessSeeder::seedAcceptedFinished(getcwd(), getenv('HATFIELD_TEST_DATABASE_PATH'), $_SERVER['HATFIELD_SESSION_ID'], 'terminal-disconnect');
        $lifecycle = false;
        $container->get('event_dispatcher')->addListener(ControllerSessionShutdownEvent::class, static function () use (&$lifecycle): void { $lifecycle = true; });
        [$writer, $reader] = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        fclose($reader);
        $emitter = $container->get(RuntimeEventEmitter::class);
        (new ReflectionProperty($emitter, 'stdout'))->setValue($emitter, $writer);
        (new ReflectionMethod(HeadlessController::class, 'shutdown'))->invoke($container->get(HeadlessController::class), new RuntimeEvent('protocol.error', '', 0, ['fatal' => true, 'error' => 'fixture broker failure']));
        $result += ['tree' => $tree, 'lifecycle' => $lifecycle,
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
