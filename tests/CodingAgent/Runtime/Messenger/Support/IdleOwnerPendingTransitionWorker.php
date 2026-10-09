<?php

declare(strict_types=1);

/**
 * Test-only run_control worker subprocess for idle-owner pending-intent recovery.
 *
 * Creates an unfinished captured transition without a wake command, then boots a
 * fresh test kernel with HATFIELD_SESSION_ID set and runs the configured Messenger
 * Worker (receiver locator + event dispatcher). No user command is injected.
 * The WorkerStartedEvent path must recover, publish, and stop naturally.
 *
 * @internal
 */
require dirname(__DIR__, 5).'/vendor/autoload.php';

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\OwnedRunIdsProvider;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatch;
use Ineersa\CodingAgent\Entity\DeferredSubagentChild;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Migrations\StartupDatabaseMigrator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\JsonlAppendJournal;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Uid\Uuid;

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
$fork = 'a-fork-'.$run;
$deferred = 'b-deferred-'.$run;
$nestedDeferred = 'c-nested-deferred-'.$run;
$nestedFork = 'd-nested-fork-'.$run;
$artifacts = $setup->get(AgentArtifactRegistry::class);
foreach ([[$run, $fork], [$run, $deferred], [$fork, $nestedDeferred], [$deferred, $nestedFork]] as [$parent, $childRun]) {
    $artifacts->create($parent, 'artifact-'.$childRun, $childRun, 'test', AgentArtifactKindEnum::Fork);
}
$manager = $setup->get(EntityManagerInterface::class);
foreach ([[$run, $deferred], [$fork, $nestedDeferred]] as [$parent, $childRun]) {
    $batch = new DeferredSubagentBatch();
    $batch->lifecycleId = Uuid::v7()->toRfc4122();
    $batch->parentRunId = $parent;
    $batch->parentToolCallId = 'pending-child';
    $manager->persist($batch);
    $child = new DeferredSubagentChild();
    $child->batchLifecycleId = $batch->lifecycleId;
    $child->childRunId = $childRun;
    $child->launchModel = 'test/model';
    $child->launchReasoning = 'none';
    $manager->persist($child);
}
$manager->flush();
$runs = $setup->get(OwnedRunIdsProvider::class)->forOwner($run);
$events = $setup->get(PreparedTransitionEventStoreInterface::class);
$requests = [];
foreach ($runs as $ownedRun) {
    $request = new ExecuteLlmStep($ownedRun, 1, 'idle', 1, 'idle-request-'.$ownedRun, 'tools');
    $requests[$ownedRun] = $request;
    $events->appendTransition([], ['run_id' => $ownedRun, 'predecessor_seq' => 0, 'effects' => [$request]]);
}
$foreign = $sessions->createSession('unrelated pending transition');
$events->appendTransition([], ['run_id' => $foreign, 'predecessor_seq' => 0, 'effects' => [new ExecuteLlmStep($foreign, 1, 'foreign', 1, 'foreign-request', 'tools')]]);
$path = $sessions->resolveSessionsBasePath().'/'.$run.'/events.jsonl';

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
file_put_contents($marker.'.before', json_encode([
    'run_id' => $run,
    'pending_identity' => $pendingIdentity,
    'cut' => $cut,
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
}, priority: -1024);
$events = $container->get(PreparedTransitionEventStoreInterface::class);
$dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event) use ($events, $runs): void {
    foreach ($runs as $ownedRun) {
        if (null !== $events->verifiedPendingTransition($ownedRun)) {
            return;
        }
    }
    // Positive natural stop after startup and idle listeners recover every owned run.
    $event->getWorker()->stop();
}, priority: -1024);

$worker->run(['sleep' => 1000]);
if (!$started) {
    throw new RuntimeException('Configured WorkerStartedEvent path did not run.');
}

$events = $container->get(PreparedTransitionEventStoreInterface::class);
if (null !== $events->verifiedPendingTransition($run)) {
    throw new RuntimeException('Pending intent must be finished after actual worker startup.');
}
if (!is_file($path) || is_file($path.'.append.pending.json')) {
    throw new RuntimeException('Committed cut must publish and remove the pending intent files.');
}

$sent = $llm->getSent();
if ([] === $sent) {
    throw new RuntimeException('Original continuation must be published on the execution bus.');
}
foreach ($sent as $envelope) {
    $message = $envelope->getMessage();
    if (!$message instanceof ExecuteLlmStep || $message != ($requests[$message->runId()] ?? null)) {
        throw new RuntimeException('Recovery must send the captured request unchanged.');
    }
}
if (count($sent) !== count($requests)) {
    throw new RuntimeException('Startup and idle recovery must deliver each owned request exactly once.');
}
if (null === $events->verifiedPendingTransition($foreign)) {
    throw new RuntimeException('Recovery must leave another owner\'s pending transition untouched.');
}

file_put_contents($marker, json_encode([
    'ok' => true,
    'run_id' => $run,
    'pending_identity' => $pendingIdentity,
    'delivery_count' => count($sent),
    'owned_runs' => $runs,
    'foreign_pending' => true,
    'cut' => filesize($path),
    'worker_started' => true,
], \JSON_THROW_ON_ERROR)."\n");

$kernel->shutdown();
exit(0);
