<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeExecutionService;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeResultArtifactStore;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Migrations\StartupDatabaseMigrator;
use Ineersa\CodingAgent\Session\Exception\ToolPayloadException;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionToolPayloadStore;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;
use Ineersa\CodingAgent\Tests\Support\CodeModeBackendFixture;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Tests\E2E\TuiE2eDatabaseEnv;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Validator\Validator\ValidatorInterface;

require dirname(__DIR__, 3).'/vendor/autoload.php';
[$unused, $directory, $role] = $argv;
if ('owner' === $role) {
    TestDirectoryIsolation::createHatfieldTree($directory, withSessions: true);
    $paths = TuiE2eDatabaseEnv::allocateIsolatedPaths(dirname(__DIR__, 3), $directory, 'storage-delete');
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
                if (!$lock->acquire(false)) {
                    // Positive proof that deletion reached the real held storage lock.
                    file_put_contents($this->directory.'/delete-blocked', 'yes');
                }

                return $lock;
            }
        };
        $store = new HatfieldSessionStore($c->get(AppConfig::class), $c->get(EntityManagerInterface::class), $c->get(EventDispatcherInterface::class), $locks);
        $store->deleteSession(trim(file_get_contents($directory.'/run')));
        file_put_contents($directory.'/deleted', 'yes');
    } elseif ('writer' === $role) {
        $run = trim(file_get_contents($directory.'/run'));
        $identity = trim(file_get_contents($directory.'/identity'));
        $filesystem = new class($directory) extends Filesystem {
            private bool $first = true;

            public function __construct(private string $directory)
            {
            }

            public function dumpFile(string $filename, $content): void
            {
                if (!$this->first) {
                    parent::dumpFile($filename, $content);

                    return;
                }
                $this->first = false;
                file_put_contents($this->directory.'/writer-held', 'yes');
                $barrier = fopen($this->directory.'/release', 'rb');
                try {
                    if (!flock($barrier, \LOCK_SH)) {
                        throw new RuntimeException('Storage writer barrier failed.');
                    }
                    parent::dumpFile($filename, $content);
                } finally {
                    fclose($barrier);
                }
            }
        };
        $paths = $c->get(ToolBatchRunStoragePathsInterface::class);
        $payloads = (new SessionToolPayloadStore($paths, $c->get('serializer'), $c->get(ValidatorInterface::class), $filesystem, $c->get(LockFactory::class)))->forRun($run);
        $invocation = $c->get(DeferredToolCompletionRepositoryInterface::class)->findInvocationReferenceByDeferredId($identity);
        $result = new ToolCallResult($run, 1, 'step', 1, 'original-execution', 'cm', 0, ['tool_name' => 'code_mode', 'content' => [['type' => 'text', 'text' => 'writer terminal']]]);
        $payloads->publish($result);
        $barrier = fopen($directory.'/late', 'rb');
        try {
            if (!flock($barrier, \LOCK_SH)) {
                throw new RuntimeException('Late writer barrier failed.');
            }
        } finally {
            fclose($barrier);
        }
        $denied = [];
        foreach (['generic' => static fn () => $payloads->publish($result), 'processed' => static fn () => $payloads->retainProcessedResult($invocation, $result), 'native' => static fn () => $c->get(CodeModeResultArtifactStore::class)->publishRaw($result)] as $kind => $write) {
            try {
                $write();
                throw new RuntimeException('Late '.$kind.' publication unexpectedly succeeded.');
            } catch (ToolPayloadException $error) {
                $denied[] = $kind.':'.$error->getMessage();
            }
        }
        $c->get(CodeModeExecutionService::class)->preserve($identity, 'late raw value', null);
        file_put_contents($directory.'/denied', json_encode($denied, \JSON_THROW_ON_ERROR));
    } else {
        ($c->get(StartupDatabaseMigrator::class))();
        $f = new CodeModeBackendFixture($c, 'return 42;');
        $id = $c->get(StackToolExecutionContextAccessor::class)->with(new ToolContext($f->state->runId, 1, 'cm', 'code_mode', new NullCancellationToken(), 8, 0, null, 'step'), static fn () => $c->get(CodeModeExecutionService::class)->begin());
        file_put_contents($directory.'/run', $f->state->runId);
        file_put_contents($directory.'/identity', $id);
        $release = fopen($directory.'/release', 'w+b');
        $late = fopen($directory.'/late', 'w+b');
        flock($release, \LOCK_EX);
        flock($late, \LOCK_EX);
        $writer = new Process([\PHP_BINARY, __FILE__, $directory, 'writer'], env: ['HATFIELD_SESSION_ID' => false]);
        $writer->start();
        $wait = static function (string $file, Process $process): void {
            $deadline = microtime(true) + 5;
            while (!is_file($file)) {
                if (!$process->isRunning() || microtime(true) >= $deadline) {
                    throw new RuntimeException('Storage barrier failed: '.$file.' '.$process->getErrorOutput());
                }
                usleep(10000);
            }
        };
        $wait($directory.'/writer-held', $writer);
        $deleter = new Process([\PHP_BINARY, __FILE__, $directory, 'deleter'], env: ['HATFIELD_SESSION_ID' => false]);
        $deleter->start();
        $wait($directory.'/delete-blocked', $deleter);
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
        echo json_encode(['blocked' => true, 'denied' => json_decode(file_get_contents($directory.'/denied'), true, flags: \JSON_THROW_ON_ERROR), 'directory_exists' => is_dir($c->get(HatfieldSessionStore::class)->resolveSessionsBasePath().'/'.$f->state->runId), 'session_exists' => $c->get(HatfieldSessionStore::class)->exists($f->state->runId)], \JSON_THROW_ON_ERROR)."\n";
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
