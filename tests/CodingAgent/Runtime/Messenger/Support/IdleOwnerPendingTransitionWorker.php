<?php

declare(strict_types=1);

/**
 * Test-only run_control worker subprocess for idle-owner pending-intent recovery.
 *
 * Creates an unfinished captured transition with no armed invocation, then boots a
 * fresh test kernel with HATFIELD_SESSION_ID set and runs the configured Messenger
 * Worker (receiver locator + event dispatcher). No user command is injected.
 * The WorkerStartedEvent path must recover, publish, and stop naturally.
 *
 * @internal
 */
require dirname(__DIR__, 5).'/vendor/autoload.php';

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Ineersa\AgentCore\Application\Pipeline\SourceAcceptance;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecutionRequest;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Migrations\StartupDatabaseMigrator;
use Ineersa\CodingAgent\Session\DoctrineExecutionOperationStore;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\JsonlAppendJournal;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

[$script, $cwd, $database, $marker] = $argv + [null, null, null, null];
if (!is_string($cwd) || '' === $cwd || !is_string($database) || '' === $database || !is_string($marker) || '' === $marker) {
    fwrite(\STDERR, "usage: IdleOwnerPendingTransitionWorker.php <cwd> <database-relative> <marker-json>\n");
    exit(1);
}

chdir($cwd);
StaticDriver::setKeepStaticConnections(false);

$applyEnv = static function (array $values): void {
    foreach ($values as $name => $value) {
        if (false === $value) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            continue;
        }
        putenv($name.'='.$value);
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
};

$applyEnv([
    'APP_ENV' => 'test',
    'APP_DEBUG' => '0',
    'APP_SECRET' => 'test-secret',
    'HATFIELD_CWD' => $cwd,
    'HATFIELD_TEST_DATABASE_PATH' => $database,
    // Setup boots without an owner session so createSession can allocate one.
    'HATFIELD_SESSION_ID' => false,
]);

$boot = static function (): array {
    $kernel = new Kernel('test', false);
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');

    return [$kernel, $container];
};

[$setupKernel, $setup] = $boot();
($setup->get(StartupDatabaseMigrator::class))();
$sessions = $setup->get(HatfieldSessionStore::class);
$run = $sessions->createSession('idle owner pending transition');
$events = $setup->get(PreparedTransitionEventStoreInterface::class);
$operations = $setup->get(DoctrineExecutionOperationStore::class);
$acceptance = $setup->get(SourceAcceptance::class);
$source = new AdvanceRun($run, 1, 'idle-source', 1, 'idle-source-key');
$request = new ExecuteLlmStep($run, 1, 'idle', 1, 'idle-request', 'tools');
$path = $sessions->resolveSessionsBasePath().'/'.$run.'/events.jsonl';

$events->appendTransition([], [
    'run_id' => $run,
    'predecessor_seq' => 0,
    'source' => SourceAcceptance::identity($source),
    'effects' => [$request],
]);
$pending = $events->verifiedPendingTransition($run);
if (null === $pending) {
    throw new RuntimeException('Expected unfinished pending intent before worker startup.');
}
$pendingIdentity = $pending->identity;
$manifest = json_decode((string) file_get_contents($path.'.append.pending.json'), true, flags: \JSON_THROW_ON_ERROR);
$physical = filesize($path);
if (false === $physical) {
    throw new RuntimeException('Cannot inspect archive size before recovery.');
}
$cut = (new JsonlAppendJournal())->readableOffset($path, $physical);
if ($cut !== $manifest['offset']) {
    throw new RuntimeException('Archive cut must remain capped at the unfinished intent offset.');
}
if ([] !== $operations->pendingDeliveries($run, '')) {
    throw new RuntimeException('No invocation may be armed before idle-owner recovery.');
}
if ($acceptance->alreadyAccepted($source)) {
    throw new RuntimeException('Source identity must stay unpublished before recovery.');
}
file_put_contents($marker.'.before', json_encode([
    'run_id' => $run,
    'pending_identity' => $pendingIdentity,
    'cut' => $cut,
    'armed' => false,
    'source_accepted' => false,
], \JSON_THROW_ON_ERROR)."\n");
$setupKernel->shutdown();

// Fresh owner process identity: session id is known before worker container boot.
$applyEnv(['HATFIELD_SESSION_ID' => $run]);
[$kernel, $container] = $boot();

/** @var InMemoryTransport $llm */
$llm = $container->get('messenger.transport.llm');
/** @var InMemoryTransport $runControl */
$runControl = $container->get('messenger.transport.run_control');
if ([] !== $llm->getSent() || [] !== iterator_to_array($runControl->get())) {
    throw new RuntimeException('Recovery must start with empty transports and no injected command.');
}

$receiver = $container->get('messenger.receiver_locator')->get('run_control');
$dispatcher = $container->get('event_dispatcher');
$bus = $container->get('messenger.routable_message_bus');
$worker = new Worker(['run_control' => $receiver], $bus, $dispatcher);
$started = false;
$dispatcher->addListener(WorkerStartedEvent::class, static function (WorkerStartedEvent $event) use (&$started, $marker): void {
    $started = true;
    file_put_contents($marker.'.started', "worker_started\n");
    // Positive natural stop after the configured startup listeners finish.
    $event->getWorker()->stop();
}, priority: -1024);

$worker->run(['sleep' => 0]);
if (!$started) {
    throw new RuntimeException('Configured WorkerStartedEvent path did not run.');
}

$events = $container->get(PreparedTransitionEventStoreInterface::class);
$acceptance = $container->get(SourceAcceptance::class);
if (null !== $events->verifiedPendingTransition($run)) {
    throw new RuntimeException('Pending intent must be finished after actual worker startup.');
}
if (!$acceptance->alreadyAccepted($source)) {
    throw new RuntimeException('Original source identity must be accepted after recovery.');
}
if (!is_file($path) || is_file($path.'.append.pending.json')) {
    throw new RuntimeException('Committed cut must publish and remove the pending intent files.');
}

$sent = $llm->getSent();
if ([] === $sent) {
    throw new RuntimeException('Original continuation must be published on the execution bus.');
}
$ids = [];
$authorization = null;
$delivery = null;
foreach ($sent as $envelope) {
    $message = $envelope->getMessage();
    $stamp = $envelope->last(ExecutionAuthorizationStamp::class);
    if (!$message instanceof ExecutionRequest || !$stamp instanceof ExecutionAuthorizationStamp) {
        throw new RuntimeException('Published continuation must retain the original ExecutionRequest identity.');
    }
    if ($message->runId() !== $run || 'idle' !== $message->stepId() || 'idle-request' !== $message->idempotencyKey()) {
        throw new RuntimeException('Published continuation diverged from the captured request identity.');
    }
    $ids[] = $message->effectId;
    $delivery = $message;
    $authorization = $stamp;
}
if (1 !== count(array_unique($ids))) {
    throw new RuntimeException('Recovery and same-tick rediscovery must reuse one Armed identity.');
}

file_put_contents($marker, json_encode([
    'ok' => true,
    'run_id' => $run,
    'pending_identity' => $pendingIdentity,
    'effect_id' => $delivery->effectId,
    'request_hash' => $authorization->requestHash,
    'delivery_count' => count($sent),
    'cut' => filesize($path),
    'worker_started' => true,
], \JSON_THROW_ON_ERROR)."\n");

$kernel->shutdown();
exit(0);
