<?php

declare(strict_types=1);

use Ineersa\AgentCore\Application\Handler\ExecuteToolCallWorker;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolResultProcessorInterface;
use Ineersa\AgentCore\Domain\Tool\DTO\ProcessedToolResultDTO;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionRunHandler;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeExecutionService;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeOwnerHandler;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeProcessedResultSubscriber;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeResultArtifactStore;
use Ineersa\CodingAgent\Application\CodeMode\DTO\CodeModeOwnerDTO;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Migrations\StartupDatabaseMigrator;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Tests\Support\CodeModeBackendFixture;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tool\Arguments\CodeModeArgumentsDTO;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeDiagnosticsToolResultProcessor;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeHostBridge;
use Ineersa\CodingAgent\Tool\CodeModeTool;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Ineersa\Tui\Tests\E2E\TuiE2eDatabaseEnv;
use Psr\Container\ContainerInterface;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult as ToolboxResult;
use Symfony\AI\Platform\Result\ToolCall as ToolboxCall;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Process\Process;
use Symfony\Component\Serializer\SerializerInterface;

require dirname(__DIR__, 3).'/vendor/autoload.php';

[$unused, $directory, $role] = $argv;
if ('owner' === $role) {
    TestDirectoryIsolation::createHatfieldTree($directory, withSessions: true);
    $paths = TuiE2eDatabaseEnv::allocateIsolatedPaths(dirname(__DIR__, 3), $directory, 'code-mode');
    file_put_contents($directory.'/database-env', $paths['appEnv']);
}
foreach (['HATFIELD_CWD' => $directory, 'APP_SECRET' => 'test-secret', 'HATFIELD_TEST_DATABASE_PATH' => trim(file_get_contents($directory.'/database-env'))] as $key => $value) {
    $_ENV[$key] = $value;
    putenv($key.'='.$value);
}
chdir($directory);
$kernel = new Kernel('test', false);
$kernel->boot();
$worker = null;
$barrier = null;
try {
    $c = $kernel->getContainer()->get('test.service_container');
    $executions = $c->get(CodeModeExecutionRepository::class);
    $execution = $c->get(CodeModeExecutionService::class);
    $artifacts = $c->get(CodeModeResultArtifactStore::class);
    $batches = $c->get(ToolBatchStoreInterface::class);
    if ('worker' === $role) {
        $run = trim(file_get_contents($directory.'/run'));
        $call = $batches->loadCall($run, 1, 'step', 'cm');
        $contexts = $c->get(StackToolExecutionContextAccessor::class);
        $nested = new class($directory) implements ToolboxInterface {
            public function __construct(private string $directory)
            {
            }

            public function getTools(): array
            {
                return [];
            }

            public function execute(ToolboxCall $call): ToolboxResult
            {
                file_put_contents($this->directory.'/blocked', json_encode(['host' => getmypid(), ...$call->getArguments()], \JSON_THROW_ON_ERROR));
                $lock = fopen($this->directory.'/barrier', 'rb');
                try {
                    if (!flock($lock, \LOCK_SH)) {
                        throw new RuntimeException('Nested barrier failed.');
                    }

                    return new ToolboxResult($call, ['original' => 42]);
                } finally {
                    fclose($lock);
                }
            }
        };
        $locator = new class($nested) implements ContainerInterface {
            public function __construct(private ToolboxInterface $toolbox)
            {
            }

            public function has(string $id): bool
            {
                return 'toolbox' === $id;
            }

            public function get(string $id): mixed
            {
                return $this->toolbox;
            }
        };
        $bridge = new CodeModeHostBridge($contexts, $c->get(ToolRuntime::class), $locator, $c->get(RuntimeProcessConfig::class), $execution);
        $tool = new CodeModeTool($bridge, $execution);
        $toolbox = new class($tool) implements ToolboxInterface {
            public function __construct(private CodeModeTool $tool)
            {
            }

            public function getTools(): array
            {
                return [];
            }

            public function execute(ToolboxCall $call): ToolboxResult
            {
                return new ToolboxResult($call, ($this->tool)(new CodeModeArgumentsDTO(...$call->getArguments())));
            }
        };
        $counter = new class implements ToolResultProcessorInterface {
            public int $count = 0;

            public function process(ToolResult $result, ToolCall $call): ToolResult
            {
                ++$this->count;

                return $result;
            }
        };
        $results = new ToolExecutionResultStore();
        $executor = new ToolExecutor('sequential', 2, $results, $toolbox, $contexts, toolResultProcessors: [new CodeModeDiagnosticsToolResultProcessor(), $counter]);
        $bus = new TestMessageBus();
        $dispatcher = new EventDispatcher();
        $subscriber = new CodeModeProcessedResultSubscriber($executions, $artifacts, $c->get(RunLockManager::class), $c->get(SerializerInterface::class), $bus, $execution);
        $dispatcher->addListener(ProcessedToolResultDTO::class, $subscriber);
        $workerHandler = new ExecuteToolCallWorker($bus, new TestLogger(), $executor, $c->get(DeferredToolCompletionRepositoryInterface::class), $results, $c->get(RunOperationalStatusReaderInterface::class), eventDispatcher: $dispatcher);
        $result = $workerHandler($call);
        file_put_contents($directory.'/worker-done', json_encode(['processors' => $counter->count, 'result' => $result?->result, 'error' => $result?->isError, 'publications' => count($bus->messages)], \JSON_THROW_ON_ERROR));
        exit(0);
    }
    ($c->get(StartupDatabaseMigrator::class))();
    $script = 'echo "OUT"; fwrite(STDERR, "ERR"); $before=getmypid(); $nested=tool("fixture_block", ["script"=>__FILE__, "pid"=>$before]); return ["nested"=>$nested, "before"=>$before, "after"=>getmypid()];';
    $f = new CodeModeBackendFixture($c, $script);
    file_put_contents($directory.'/run', $f->state->runId);
    $barrier = fopen($directory.'/barrier', 'w+b');
    if (!flock($barrier, \LOCK_EX)) {
        throw new RuntimeException('Owner barrier failed.');
    }
    $worker = new Process([\PHP_BINARY, __FILE__, $directory, 'worker'], $directory);
    $worker->start();
    $deadline = hrtime(true) + 5_000_000_000;
    while (!is_file($directory.'/blocked')) {
        if (!$worker->isRunning() || hrtime(true) >= $deadline) {
            throw new RuntimeException('Nested readiness failed: '.$worker->getErrorOutput().$worker->getOutput().(is_file($directory.'/worker-done') ? file_get_contents($directory.'/worker-done') : ''));
        }
        usleep(10_000);
    }
    $blocked = json_decode(file_get_contents($directory.'/blocked'), true, flags: \JSON_THROW_ON_ERROR);
    $row = $executions->invocation($f->state->runId, 'cm');
    $handler = $c->get(CodeModeOwnerHandler::class);
    $message = new CodeModeOwnerDTO($f->state->runId, $row['identity'], 'cm', true);
    $ack = $handler->handle($message, $f->state);
    $state = $f->commit($f->state, $ack);
    $ackRow = $executions->invocation($state->runId, 'cm');
    $evidence = ['ack_events' => array_column($ack->events, 'type'), 'ack_detached' => (bool) $ackRow['detached'],
        'worker_alive_after_ack' => $worker->isRunning(), 'script_live_after_ack' => is_file($blocked['script']),
        'socket_live_after_ack' => file_exists(dirname($blocked['script']).'/s.sock'),
        'host_pid' => $blocked['host'], 'owner_pid' => getmypid(),
        'duplicate_ack_events' => count($handler->handle($message, $state)->events),
        'schedule_in_flight' => $batches->loadSchedule($state->runId, 1, 'step')?->inFlight];
    flock($barrier, \LOCK_UN);
    fclose($barrier);
    $barrier = null;
    $worker->wait();
    if (!$worker->isSuccessful()) {
        throw new RuntimeException('Host failed: '.$worker->getErrorOutput().$worker->getOutput());
    }
    $done = json_decode(file_get_contents($directory.'/worker-done'), true, flags: \JSON_THROW_ON_ERROR);
    $terminal = new CodeModeOwnerDTO($state->runId, $row['identity'], 'cm');
    $finished = $handler->handle($terminal, $state);
    $state = $f->commit($state, $finished);
    $notice = $finished->postCommitEffects[0];
    $admission = $c->get(BackgroundCompletionRunHandler::class)->handle($notice, $state);
    $state = $f->commit($state, $admission);
    $row = $executions->invocation($state->runId, 'cm');
    $rawRef = $execution->resultReference($row['raw_reference']);
    $rawPath = $artifacts->path($rawRef);
    $batches->delete($state->runId, 1, 'step');
    $raw = $artifacts->read($rawRef);
    echo json_encode([...$evidence, 'worker' => $done, 'notice' => $notice->text,
        'admission_events' => array_column($admission->events, 'type'),
        'duplicate_terminal_effects' => count($handler->handle($terminal, $state)->postCommitEffects),
        'duplicate_notice_events' => count($c->get(BackgroundCompletionRunHandler::class)->handle($notice, $state)->events),
        'raw' => $raw->result, 'raw_path' => $rawPath, 'raw_exists_after_batch_delete' => is_file($rawPath),
        'workspace_cleaned' => !is_dir(dirname($blocked['script']))], \JSON_THROW_ON_ERROR)."\n";
} finally {
    if (is_resource($barrier)) {
        flock($barrier, \LOCK_UN);
        fclose($barrier);
    }
    if ($worker instanceof Process && $worker->isRunning()) {
        $worker->stop(0);
    }
    $kernel->shutdown();
}
