<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\ApplicationDbTransactionInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DeferredToolInvocationFence;
use Ineersa\CodingAgent\Agent\Artifact\OwnedRunIdsProvider;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeExecutionService;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeResultArtifactStore;
use Ineersa\CodingAgent\Application\Deferred\DeferredControllerLossRecovery;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Migrations\StartupDatabaseMigrator;
use Ineersa\CodingAgent\Runtime\Controller\CodeModeControllerSessionLifecycleListener;
use Ineersa\CodingAgent\Session\Contract\RunHistorySourceProviderInterface;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\CodingAgent\Session\Exception\ToolPayloadException;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;
use Ineersa\CodingAgent\Tests\Support\CodeModeBackendFixture;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Tests\E2E\TuiE2eDatabaseEnv;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Serializer\SerializerInterface;

require dirname(__DIR__, 3).'/vendor/autoload.php';
[$unused, $directory, $role, $operation] = $argv;
if ('owner' === $role) {
    TestDirectoryIsolation::createHatfieldTree($directory, withSessions: true);
    $paths = TuiE2eDatabaseEnv::allocateIsolatedPaths(dirname(__DIR__, 3), $directory, 'transaction-delete');
    file_put_contents($directory.'/database-env', $paths['appEnv']);
}
foreach (['HATFIELD_CWD' => $directory, 'APP_SECRET' => 'test-secret', 'HATFIELD_TEST_DATABASE_PATH' => trim(file_get_contents($directory.'/database-env'))] as $key => $value) {
    $_ENV[$key] = $value;
    putenv($key.'='.$value);
}
chdir($directory);
$kernel = new Kernel('test', false);
$kernel->boot();
$writer = $deleter = $release = $late = null;
try {
    $c = $kernel->getContainer()->get('test.service_container');
    if ('deleter' === $role) {
        $locks = new class($c->get(LockFactory::class), $directory) extends LockFactory {
            public function __construct(private LockFactory $inner, private string $directory)
            {
            }

            public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
            {
                $lock = $this->inner->createLock($resource, $ttl, $autoRelease);
                file_put_contents($this->directory.($lock->acquire(false) ? '/delete-acquired' : '/delete-blocked'), 'yes');

                return $lock;
            }
        };
        $store = new HatfieldSessionStore($c->get(AppConfig::class), $c->get(EntityManagerInterface::class), $c->get(EventDispatcherInterface::class), $locks);
        $store->deleteSession(trim(file_get_contents($directory.'/run')));
    } elseif ('writer' === $role) {
        $run = trim(file_get_contents($directory.'/run'));
        $db = $c->get(Connection::class);
        $transactions = new class($c->get(ApplicationDbTransactionInterface::class), $db, $directory, $run) implements ApplicationDbTransactionInterface {
            private bool $first = true;

            public function __construct(private ApplicationDbTransactionInterface $inner, private Connection $db, private string $directory, private string $run)
            {
            }

            public function transactional(callable $callback): mixed
            {
                return $this->inner->transactional(function () use ($callback): mixed {
                    if ($this->first) {
                        $this->first = false;
                        // Hold the real SQLite writer before the production callback
                        // reaches nested immutable publication. Deletion must already
                        // be excluded by the outer storage admission, not a SQL timeout.
                        $this->db->executeStatement('UPDATE hatfield_session SET name = name WHERE id = ?', [$this->run]);
                        file_put_contents($this->directory.'/writer-db-held', 'yes');
                        $barrier = fopen($this->directory.'/release', 'rb');
                        try {
                            if (!flock($barrier, \LOCK_SH)) {
                                throw new RuntimeException('Transaction release barrier failed.');
                            }
                        } finally {
                            fclose($barrier);
                        }
                    }

                    return $callback();
                });
            }
        };
        $paths = $c->get(ToolBatchRunStoragePathsInterface::class);
        if ('registration' === $operation) {
            $service = new CodeModeExecutionService($c->get(StackToolExecutionContextAccessor::class), $c->get(ToolBatchStoreInterface::class), $c->get(ToolPayloadStoreInterface::class), $c->get(DeferredToolCompletionRepositoryInterface::class), $c->get(CodeModeExecutionRepository::class), $c->get(CodeModeResultArtifactStore::class), $c->get(RunLockManager::class), $c->get(SerializerInterface::class), $transactions, $c->get(DeferredToolInvocationFence::class), $c->get(RunHistorySourceProviderInterface::class), $paths);
            $c->get(StackToolExecutionContextAccessor::class)->with(new ToolContext($run, 1, 'cm', 'code_mode', new NullCancellationToken(), 8, 0, null, 'step'), $service->begin(...));
        } else {
            $listener = new CodeModeControllerSessionLifecycleListener($c->get(OwnedRunIdsProvider::class), $c->get(CodeModeExecutionRepository::class), $c->get(RunLockManager::class), $paths, $c->get(DeferredControllerLossRecovery::class));
            $listener->starting(new ControllerSessionStartingEvent($run));
        }
        file_put_contents($directory.'/writer-completed', 'yes');
        $barrier = fopen($directory.'/late', 'rb');
        try {
            if (!flock($barrier, \LOCK_SH)) {
                throw new RuntimeException('Late publication barrier failed.');
            }
        } finally {
            fclose($barrier);
        }
        $result = new ToolCallResult($run, 1, 'step', 1, 'original-execution', 'cm', 0, ['tool_name' => 'code_mode', 'content' => [['type' => 'text', 'text' => 'late terminal']]]);
        $denied = [];
        foreach (['generic' => static fn () => $c->get(ToolPayloadStoreInterface::class)->publish($result), 'native' => static fn () => $c->get(CodeModeResultArtifactStore::class)->publishRaw($result), 'diagnostic' => static fn () => $c->get(CodeModeResultArtifactStore::class)->retainDiagnostic($run, 'cm', $directory.'/diagnostic-source')] as $kind => $write) {
            try {
                $write();
                throw new RuntimeException('Deleted '.$kind.' storage was recreated.');
            } catch (ToolPayloadException $error) {
                $denied[] = $kind;
            }
        }
        file_put_contents($directory.'/denied', json_encode($denied, \JSON_THROW_ON_ERROR));
    } else {
        ($c->get(StartupDatabaseMigrator::class))();
        $fixture = new CodeModeBackendFixture($c, 'return 42;');
        $run = $fixture->state->runId;
        if ('recovery' === $operation) {
            $fixture->registerReadyHost();
        }
        file_put_contents($directory.'/run', $run);
        file_put_contents($directory.'/diagnostic-source', 'diagnostic bytes');
        $release = fopen($directory.'/release', 'w+b');
        $late = fopen($directory.'/late', 'w+b');
        flock($release, \LOCK_EX);
        flock($late, \LOCK_EX);
        $wait = static function (array $files, Process $process) use ($directory): void {
            $deadline = microtime(true) + 5;
            while (!array_filter($files, static fn (string $file): bool => is_file($directory.'/'.$file))) {
                if (!$process->isRunning() || microtime(true) >= $deadline) {
                    throw new RuntimeException('Transaction barrier failed: '.implode(',', $files).' '.$process->getErrorOutput().' '.$process->getOutput());
                }
                usleep(10000);
            }
        };
        $writer = new Process([\PHP_BINARY, __FILE__, $directory, 'writer', $operation], env: ['HATFIELD_SESSION_ID' => false]);
        $writer->start();
        $wait(['writer-db-held'], $writer);
        $deleter = new Process([\PHP_BINARY, __FILE__, $directory, 'deleter', $operation], env: ['HATFIELD_SESSION_ID' => false]);
        $deleter->start();
        $wait(['delete-blocked', 'delete-acquired'], $deleter);
        if (!is_file($directory.'/delete-blocked')) {
            throw new RuntimeException('Deletion acquired storage while the production metadata transaction held SQLite.');
        }
        flock($release, \LOCK_UN);
        $deleter->wait();
        if (!$deleter->isSuccessful()) {
            throw new RuntimeException($deleter->getErrorOutput());
        }
        flock($late, \LOCK_UN);
        $writer->wait();
        if (!$writer->isSuccessful()) {
            throw new RuntimeException($writer->getErrorOutput());
        }
        $db = $c->get(Connection::class);
        echo json_encode(['blocked' => true, 'completed' => is_file($directory.'/writer-completed'), 'denied' => json_decode(file_get_contents($directory.'/denied'), true, flags: \JSON_THROW_ON_ERROR), 'directory_exists' => is_dir($c->get(HatfieldSessionStore::class)->resolveSessionsBasePath().'/'.$run), 'session_exists' => $c->get(HatfieldSessionStore::class)->exists($run), 'native_rows' => (int) $db->fetchOne('SELECT COUNT(*) FROM code_mode_execution WHERE run_id = ?', [$run]), 'deferred_rows' => (int) $db->fetchOne('SELECT COUNT(*) FROM tool_deferred_completion WHERE run_id = ?', [$run])], \JSON_THROW_ON_ERROR)."\n";
    }
} finally {
    foreach ([$release, $late] as $barrier) {
        if (is_resource($barrier)) {
            fclose($barrier);
        }
    }
    foreach ([$writer, $deleter] as $process) {
        if ($process instanceof Process && $process->isRunning()) {
            $process->stop(0);
        }
    }
    $kernel->shutdown();
}
