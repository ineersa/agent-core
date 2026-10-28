<?php

declare(strict_types=1);

require dirname(__DIR__, 5).'/vendor/autoload.php';

use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Process\Process;
use Amp\TimeoutCancellation;
use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\OwnedRunIdsProvider;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashLifecycleFailureSubscriber;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashOwnershipActionEnum;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashOwnershipService;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashOwnershipStatusEnum;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationClient;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationReplyPublisher;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationService;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationStatusEnum;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashWorkerLifecycleSubscriber;
use Ineersa\CodingAgent\Application\BackgroundProcess\ChangeBashOwnershipHandler;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashOwnershipCommandDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashOwnershipRequestDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashRegistrationReplyDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashRegistrationResultDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RegisterBashProcessCommandDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\RegisterBashProcessHandler;
use Ineersa\CodingAgent\Application\Pipeline\BackgroundCompletionHandler;
use Ineersa\CodingAgent\Entity\BackgroundCompletionReceiptRepository;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Migrations\StartupDatabaseMigrator;
use Ineersa\CodingAgent\Runtime\Controller\BackgroundProcessCompletionPoller;
use Ineersa\CodingAgent\Runtime\Controller\BackgroundProcessControllerSessionLifecycleListener;
use Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueueBrokerSupervisor;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueueEnv;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueuePaths;
use Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Runtime\Process\SourceTreeExecutableLocator;
use Ineersa\CodingAgent\Session\Event\ControllerSessionShutdownEvent;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\CodingAgent\Tool\BackgroundProcessProvisionalCleanupTask;
use Ineersa\SqliteQueue\Messenger\TransportFactory;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
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
        $work = '$s=stream_socket_client('.var_export($socket, true).'); fwrite($s,"ready\n"); fgets($s); echo "BACKGROUND_SURVIVED\n"; fwrite($s,"done\n"); fclose($s);';
        $result = $manager->start(escapeshellarg(\PHP_BINARY).' -r '.escapeshellarg($work), $runId, 'background-survival', backgroundRequested: true);
        if ('registration-failure' === $scenario) {
            try {
                $manager->start('exit 0', $runId, 'refused-registration');
                throw new LogicException('Second registration unexpectedly succeeded.');
            } catch (RuntimeException $error) {
                if (!str_contains($error->getMessage(), 'Bash registration refused: input_conflict')) {
                    throw $error;
                }
                fwrite(\STDERR, 'Expected registration refusal: '.$error->getMessage()."\n");
            }
            $record = $container->get(ProcessStore::class)->fetchByRecordId($result->id);
            $lifecycle = $container->get(ProcessLifecycle::class);
            if (null !== $lifecycle->readStatusFile($record->statusPath) || !$lifecycle->isAlive($result->pid)) {
                fwrite(\STDERR, "Sibling foreground launch was terminated by another registration failure.\n");
                throw new RuntimeException('Sibling foreground launch was terminated by another registration failure.');
            }
        }
        if ('lost' === $scenario) {
            $identity = $container->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT identity FROM bash_registration_receipt WHERE record_id = ?', [$result->id]);
            try {
                $container->get(BashRegistrationClient::class)->accept(new BashOwnershipRequestDTO($identity, $runId));
                throw new RuntimeException('Dropped acceptance reply unexpectedly resolved.');
            } catch (RuntimeException $error) {
                if (!str_contains($error->getMessage(), 'committed outcome is unknown')) {
                    throw $error;
                }
                // The owner committed acceptance but lost the normal reply.
                // Its simulated terminal publication failure reports uncertainty.
                fwrite(\STDERR, 'Acceptance reply lost after commit and redelivery: '.$error->getMessage()."\n");
            }
        } elseif (!in_array($scenario, ['foreground', 'maintenance', 'maintenance-race'], true)) {
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
$executable = new SourceTreeExecutableLocator(dirname(__DIR__, 5));
if ('lost' === $scenario) {
    $executable = new class($executable) implements AppExecutableLocator {
        public function __construct(private readonly AppExecutableLocator $inner)
        {
        }

        public function path(): string
        {
            return $this->inner->path();
        }

        public function command(): array
        {
            return [\PHP_BINARY, dirname(__DIR__, 3).'/Runtime/Messenger/Fixtures/short-lease-broker.php', $this->path()];
        }
    };
}
$broker = new SessionSqliteQueueBrokerSupervisor(new NullLogger(), new RuntimeProcessConfig($executable, $directory));
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
$maintenanceTask = null;
$lostCorrelation = null;
$acceptanceRedelivered = false;
$maintain = static function () use ($container, $manager, $owner): void {
    $task = new BackgroundProcessProvisionalCleanupTask($manager, $container->get(ProcessStore::class), $container->get(ProcessLifecycle::class), new NullLogger(), $container->get(OwnedRunIdsProvider::class), $owner);
    $task();
};
$finish = static function () use ($container): string {
    $connection = $container->get(EntityManagerInterface::class)->getConnection();
    $row = $connection->fetchAssociative('SELECT id, status_path FROM background_process');
    // Model a stale cached PID safely: the test-owned socket-held process stays
    // alive while durable completion is aged for real scheduled maintenance.
    // No OS PID reuse or unrelated process is involved.
    $connection->executeStatement("UPDATE background_process SET finished_at = '2020-01-01 00:00:00', exit_code = 0 WHERE id = ?", [$row['id']]);
    file_put_contents($row['status_path'], "0\n");

    return $row['status_path'];
};
$ownerTask = async(static function () use ($requests, $registration, $ownership, $publisher, $transportSession, $stop, $scenario, $container, $maintain, $finish, &$maintenanceTask, &$lostCorrelation, &$acceptanceRedelivered): void {
    while (!$stop->getCancellation()->isRequested()) {
        $requests->wait(1000, $stop->getCancellation());
        foreach ($requests->get() as $delivery) {
            $command = $delivery->getMessage();
            if ($command instanceof RegisterBashProcessCommandDTO) {
                if ('registration-failure' === $scenario && 'refused-registration' === $command->registration->toolCallId) {
                    $publisher->publish(new BashRegistrationReplyDTO($transportSession, $command->incarnation, $command->correlation, new BashRegistrationResultDTO(BashRegistrationStatusEnum::InputConflict)));
                } else {
                    $registration($command);
                }
            } elseif ($command instanceof BashOwnershipCommandDTO) {
                if ('maintenance-race' === $scenario && BashOwnershipActionEnum::Retire === $command->action) {
                    $status = $container->get(BashOwnershipService::class)->retire($command->request);
                    $finish();
                    $started = new DeferredFuture();
                    $maintenanceTask = async(static function () use ($maintain, $started): void {
                        $started->complete();
                        $maintain();
                    });
                    // Run deletion to its lock boundary before publishing the
                    // already-decided reply. No timing window chooses the order.
                    $started->getFuture()->await();
                    $publisher->publish(new BashRegistrationReplyDTO($transportSession, $command->incarnation, $command->correlation, ownership: $status));
                } elseif ('lost' === $scenario && BashOwnershipActionEnum::Accept === $command->action) {
                    if (BashOwnershipStatusEnum::Accepted !== $container->get(BashOwnershipService::class)->accept($command->request)) {
                        throw new RuntimeException('Acceptance did not survive metadata redelivery.');
                    }
                    if (null === $lostCorrelation) {
                        // Lose the normal reply after commit and leave the native
                        // delivery leased. Broker eligibility, not a delay, resumes it.
                        $lostCorrelation = $command->correlation;
                        continue;
                    }
                    if ($lostCorrelation !== $command->correlation) {
                        throw new RuntimeException('Expected the original acceptance request to redeliver.');
                    }
                    $acceptanceRedelivered = true;
                    // Inject terminal publication failure after real redelivery;
                    // use the production unknown-outcome response, not cancellation.
                    $failure = new BashLifecycleFailureSubscriber(new ServiceLocator([BashRegistrationReplyPublisher::class => static fn () => $publisher]), $transportSession);
                    $failure->onFailed(new WorkerMessageFailedEvent($delivery, 'run_control', new RuntimeException('Simulated acceptance-reply publication failure.')));
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
    $workerReady = $worker->getStdout()->read($cancel);
    if ("ready\n" !== $workerReady) {
        throw new RuntimeException('Worker did not reach its exit barrier: '.$workerReady.buffer($worker->getStdout(), $cancel).$stderr->await($cancel));
    }
    if ('maintenance' === $scenario) {
        $finish();
        $maintain();
    }
    $worker->getStdin()->write("exit\n");
    if (0 !== $workerExit->await($cancel)) {
        throw new RuntimeException('Worker failed: '.$stderr->await());
    }
    $launch = json_decode(file_get_contents($marker.'.launch'), true, flags: \JSON_THROW_ON_ERROR);
    $lifecycle = $container->get(ProcessLifecycle::class);
    if (in_array($scenario, ['maintenance', 'maintenance-race'], true)) {
        $maintenanceTask?->await($cancel);
        if (!$lifecycle->isAlive($launch['pid'])) {
            throw new RuntimeException('Retirement signalled a live cached PID after completion maintenance.');
        }
        if (null !== $container->get(ProcessStore::class)->fetchByRecordId($launch['id'])) {
            throw new RuntimeException('Real maintenance did not remove the completed row.');
        }
        $socket->write("release\n");
        if ("done\n" !== buffer($socket, $cancel)) {
            throw new RuntimeException('Preserved cached process did not complete after barrier release.');
        }
        file_put_contents($marker, json_encode(['survived' => true], \JSON_THROW_ON_ERROR));

        return;
    }
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
    if ([] !== $bus->messages) {
        PreparedEventStoreSeeder::append($container->get(PreparedTransitionEventStoreInterface::class), RunEvent::forAppend($owner, 0, 'run_started', ['payload' => ['messages' => []]]));
        foreach ($bus->messages as $completion) {
            $container->get(BackgroundCompletionHandler::class)($completion);
        }
    }
    (new ReflectionMethod($poller, 'poll'))->invoke($poller);
    $record = $store->snapshot($launch['id']);
    file_put_contents($marker, json_encode(['notifications' => count($bus->messages), 'text' => $bus->messages[0]->text ?? null, 'exit' => $record->exitCode, 'user_stop' => $record->stoppedByUser, 'notified' => null !== $record->completionNotifiedAt, 'acceptance_redelivered' => $acceptanceRedelivered], \JSON_THROW_ON_ERROR));
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
