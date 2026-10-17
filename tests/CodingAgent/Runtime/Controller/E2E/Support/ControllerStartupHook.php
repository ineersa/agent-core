<?php

declare(strict_types=1);

use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Migrations\StartupDatabaseMigrator;
use Ineersa\CodingAgent\Runtime\Controller\HeadlessController;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\CodingAgent\Tests\Support\OwnedProcessTree;
use Symfony\Component\Messenger\Envelope;

/** @var array<int, string> $argv */
require $argv[1].'/vendor/autoload.php';

// Supply application identity without tagging the owned QA process for signals.
$_SERVER['HATFIELD_SESSION_ID'] = getenv('HATFIELD_REPLAY_SESSION_ID');
$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
($container->get(StartupDatabaseMigrator::class))();
$proof = $argv[2];
$container->get('event_dispatcher')->addListener(ControllerSessionStartingEvent::class, static function () use ($container, $proof): void {
    $transport = $container->get('messenger.transport.extension_agent');
    $message = new stdClass();
    $message->job = 'startup-job';
    $transport->send(new Envelope($message));
    $received = iterator_to_array($transport->get());
    if (1 !== count($received)) {
        throw new RuntimeException('Startup hook must publish to the ready live broker.');
    }
    $transport->ack($received[0]);
    $consumers = array_values(array_filter(OwnedProcessTree::descendants(getmypid()), static fn (array $child): bool => str_contains($child['cmdline'], 'messenger:consume')));
    file_put_contents($proof, json_encode(['received' => $received[0]->getMessage()->job, 'consumers' => $consumers], \JSON_THROW_ON_ERROR));
});
try {
    $exitCode = $container->get(HeadlessController::class)->run();
} finally {
    $kernel->shutdown();
}
exit($exitCode);
