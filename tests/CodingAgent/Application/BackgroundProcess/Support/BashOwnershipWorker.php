<?php

declare(strict_types=1);

require dirname(__DIR__, 5).'/vendor/autoload.php';

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Process\Process;
use Amp\TimeoutCancellation;
use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashOwnershipActionEnum;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashOwnershipService;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashOwnershipStatusEnum;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationClient;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationReplyPublisher;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationService;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashWorkerLifecycleSubscriber;
use Ineersa\CodingAgent\Application\BackgroundProcess\ChangeBashOwnershipHandler;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashOwnershipCommandDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashOwnershipRequestDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RegisterBashProcessCommandDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\RegisterBashProcessHandler;
use Ineersa\CodingAgent\Entity\BackgroundCompletionReceiptRepository;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Migrations\StartupDatabaseMigrator;
use Ineersa\CodingAgent\Runtime\Controller\BackgroundProcessCompletionPoller;
use Ineersa\CodingAgent\Runtime\Controller\BackgroundProcessControllerSessionLifecycleListener;
use Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueueBrokerSupervisor;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueueEnv;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueuePaths;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Runtime\Process\SourceTreeExecutableLocator;
use Ineersa\CodingAgent\Session\Event\ControllerSessionShutdownEvent;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\SqliteQueue\Messenger\TransportFactory;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\ByteStream\getStdin;
use function Amp\Future\awaitFirst;
use function Amp\Socket\listen;

[$script, $mode, $directory, $database, $marker] = $argv;
$scenario = $argv[5] ?? 'accepted';
chdir($directory);
StaticDriver::setKeepStaticConnections(false);
foreach (['APP_ENV' => 'test', 'APP_DEBUG' => '0', 'APP_SECRET' => 'test', 'HATFIELD_CWD' => $directory, 'HATFIELD_TEST_DATABASE_PATH' => $database] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$kernel = new Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$manager = $container->get(BackgroundProcessManager::class);

if ('worker' === $mode || 'replacement' === $mode) {
    // services_test omits this. Enable the exact production PHP exit callback.
    $manager->registerShutdownHandler();
    $transport = new InMemoryTransport();
    $address = $argv[6];
    $runId = $argv[7];
    $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([stdClass::class => [static function () use ($mode, $manager, $marker, $scenario, $container, $address, $runId): void {
        if ('replacement' === $mode) {
            return;
        }
        $socket = 'tcp://'.$address;
        $work = '$s=stream_socket_client('.var_export($socket, true).'); fwrite($s,"ready\n"); fgets($s); echo "BACKGROUND_SURVIVED\n"; fclose($s);';
        $result = $manager->start(escapeshellarg(\PHP_BINARY).' -r '.escapeshellarg($work), $runId, 'background-survival', backgroundRequested: true);
        if ('lost' === $scenario) {
            $identity = $container->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT identity FROM bash_registration_receipt WHERE record_id = ?', [$result->id]);
            $cancellation = new DeferredCancellation();
            $cancelTask = async(static function () use ($cancellation): void {
                getStdin()->read();
                $cancellation->cancel();
            });
            try {
                $container->get(BashRegistrationClient::class)->accept(new BashOwnershipRequestDTO($identity, $runId), $cancellation->getCancellation());
                throw new RuntimeException('Dropped acceptance reply unexpectedly resolved.');
            } catch (CancelledException $error) {
                // Parent cancelled only after the acceptance transaction committed.
                fwrite(\STDERR, "Acceptance reply lost after commit.\n");
            }
            $cancelTask->await();
        } elseif ('foreground' !== $scenario) {
            $manager->markBackgroundedForRecord($result->id, $runId);
        }
        file_put_contents($marker.'.launch', json_encode(['id' => $result->id, 'pid' => $result->pid], \JSON_THROW_ON_ERROR));
        fwrite(\STDOUT, "ready\n");
        getStdin()->read();
    }]]))]);
    $worker = new Worker(['tool' => $transport], $bus);
    $container->get(BashWorkerLifecycleSubscriber::class)->onStarted(new WorkerStartedEvent($worker));
    $transport->send(new Envelope(new stdClass()));
    $worker->run(['stop_when_empty' => true]);
    // Do not shut down the kernel first. The callback must reconcile using
    // the production manager and its still-live owner/reply pump.
    exit(0);
}

($container->get(StartupDatabaseMigrator::class))();
$owner = $container->get(HatfieldSessionStore::class)->createSession('worker ownership');
$transportSession = 'transport' === $scenario ? 'controller-test' : $owner;
$paths = SessionSqliteQueuePaths::forSession($directory.'/.hatfield/sessions', $transportSession);
$broker = new SessionSqliteQueueBrokerSupervisor(new NullLogger(), new RuntimeProcessConfig(new SourceTreeExecutableLocator(dirname(__DIR__, 5)), $directory));
$broker->start($paths);
$requests = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
$serializer = $container->get(SerializerInterface::class);
$validator = $container->get(ValidatorInterface::class);
$locks = $container->get(RunLockManager::class);
$publisher = new BashRegistrationReplyPublisher($paths->endpointPath, $transportSession, $serializer);
$registration = new RegisterBashProcessHandler(new BashRegistrationService($container->get(EntityManagerInterface::class), $container->get(ProcessStore::class), $locks, $serializer, $validator), $publisher, $locks, $validator, $transportSession);
$ownership = new ChangeBashOwnershipHandler($container->get(BashOwnershipService::class), $publisher, $locks, $validator, $transportSession);
$stop = new DeferredCancellation();
$worker = null;
$ownerTask = async(static function () use ($requests, $registration, $ownership, $stop, $scenario, $container, &$worker): void {
    while (!$stop->getCancellation()->isRequested()) {
        $requests->wait(1000, $stop->getCancellation());
        foreach ($requests->get() as $delivery) {
            $command = $delivery->getMessage();
            if ($command instanceof RegisterBashProcessCommandDTO) {
                $registration($command);
            } elseif ($command instanceof BashOwnershipCommandDTO) {
                if ('lost' === $scenario && BashOwnershipActionEnum::Accept === $command->action) {
                    $container->get(BashOwnershipService::class)->accept($command->request);
                    // Drop the response after the actual commit. This barrier
                    // cancels the waiter, never the metadata operation.
                    $worker->getStdin()->write("cancel\n");
                } else {
                    $ownership($command);
                }
            } else {
                throw new RuntimeException('Unexpected owner request.');
            }
            $requests->ack($delivery);
        }
    }
});
$server = listen('127.0.0.1:0');
$environment = [...getenv(), ...SessionSqliteQueueEnv::processEnv($paths), 'HATFIELD_SESSION_ID' => $transportSession];
$cancel = new TimeoutCancellation(8);
try {
    $worker = Process::start([\PHP_BINARY, __FILE__, 'worker', $directory, $database, $marker, $scenario, (string) $server->getAddress(), $owner], $directory, $environment);
    $stderr = async(buffer(...), $worker->getStderr());
    $workerExit = async($worker->join(...), $cancel);
    $socket = awaitFirst([async($server->accept(...), $cancel), $workerExit]);
    if (is_int($socket)) {
        throw new RuntimeException('Worker exited before readiness: '.$stderr->await());
    }
    if (null === $socket || "ready\n" !== $socket->read($cancel)) {
        throw new RuntimeException('Workload did not become ready.');
    }
    if ("ready\n" !== $worker->getStdout()->read($cancel)) {
        throw new RuntimeException('Worker did not reach its exit barrier.');
    }
    $worker->getStdin()->write("exit\n");
    if (0 !== $workerExit->await($cancel)) {
        throw new RuntimeException('Worker failed: '.$stderr->await());
    }
    $launch = json_decode(file_get_contents($marker.'.launch'), true, flags: \JSON_THROW_ON_ERROR);
    $lifecycle = $container->get(ProcessLifecycle::class);
    if ('foreground' !== $scenario && !$lifecycle->isAlive($launch['pid'])) {
        throw new RuntimeException('Accepted job died with its worker.');
    }
    $replacement = Process::start([\PHP_BINARY, __FILE__, 'replacement', $directory, $database, $marker, $scenario, (string) $server->getAddress(), $owner], $directory, $environment);
    $replacementStderr = async(buffer(...), $replacement->getStderr());
    if (0 !== $replacement->join($cancel) || ('foreground' !== $scenario && !$lifecycle->isAlive($launch['pid']))) {
        throw new RuntimeException('Replacement failed: '.$replacementStderr->await());
    }
    if ('session-stop' === $scenario) {
        $container->get(BackgroundProcessControllerSessionLifecycleListener::class)->onSessionShutdown(new ControllerSessionShutdownEvent($owner));
        while (null !== $socket->read($cancel)) {
        }
        file_put_contents($marker, json_encode(['removed' => null === $container->get(ProcessStore::class)->fetchByRecordId($launch['id'])], \JSON_THROW_ON_ERROR));

        return;
    }
    if ('foreground' !== $scenario) {
        $socket->write("release\n");
    }
    while (null !== $socket->read($cancel)) {
    }
    $store = $container->get(ProcessStore::class);
    $record = $store->fetchByRecordId($launch['id']);
    if ('foreground' === $scenario) {
        $identity = $container->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT identity FROM bash_registration_receipt WHERE record_id = ?', [$launch['id']]);
        if (BashOwnershipStatusEnum::Retired !== $container->get(BashOwnershipService::class)->accept(new BashOwnershipRequestDTO($identity, $owner))) {
            throw new RuntimeException('Late acceptance crossed the retirement fence.');
        }
    }
    // Status-file appearance is a positive native-wrapper completion predicate,
    // not a delay before assuming completion. Keep the process liveness proof.
    while (null === $lifecycle->readStatusFile($record->statusPath)) {
        $cancel->throwIfRequested();
        Amp\delay(0.001);
    }
    $bus = new TestMessageBus();
    $poller = new BackgroundProcessCompletionPoller($store, $manager, $bus, new RuntimeEventEmitter(new NullLogger()), new NullLogger(), $container->get(AgentArtifactRegistry::class), $container->get(BackgroundCompletionReceiptRepository::class), $owner);
    (new ReflectionMethod($poller, 'poll'))->invoke($poller);
    (new ReflectionMethod($poller, 'poll'))->invoke($poller);
    $record = $store->fetchByRecordId($launch['id']);
    file_put_contents($marker, json_encode(['notifications' => count($bus->messages), 'text' => $bus->messages[0]->text ?? null, 'exit' => $record->exitCode, 'user_stop' => $record->stoppedByUser, 'notified' => null !== $record->completionNotifiedAt], \JSON_THROW_ON_ERROR));
} finally {
    $stop->cancel();
    try {
        $ownerTask->await();
    } catch (Throwable $error) {
        if (!$stop->getCancellation()->isRequested()) {
            throw $error;
        }
        // Expected cancellation of the idle owner WAIT during owned teardown.
        fwrite(\STDERR, "Owner WAIT cancelled after worker completion.\n");
    }
    $requests->close();
    $publisher->reset();
    $server->close();
    $broker->shutdownUntil(microtime(true) + 5);
    $kernel->shutdown();
}
