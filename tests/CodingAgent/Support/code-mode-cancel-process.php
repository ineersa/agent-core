<?php

declare(strict_types=1);

use Ineersa\AgentCore\Application\Handler\ExecuteToolCallWorker;
use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolResultProcessorInterface;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeAcknowledgementHandler;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeExecutionService;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeInterruptionHookSubscriber;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeResultArtifactStore;
use Ineersa\CodingAgent\Application\CodeMode\DTO\CodeModeOwnerDTO;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Migrations\StartupDatabaseMigrator;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Runtime\Controller\CodeModeControllerSessionLifecycleListener;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Session\Event\ControllerSessionShutdownEvent;
use Ineersa\CodingAgent\Tests\Support\CodeModeBackendFixture;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tool\Arguments\CodeModeArgumentsDTO;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeDiagnosticsToolResultProcessor;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeHostBridge;
use Ineersa\CodingAgent\Tool\CodeModeTool;
use Ineersa\CodingAgent\Tool\OutputCap;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Ineersa\Tui\Tests\E2E\TuiE2eDatabaseEnv;
use Psr\Container\ContainerInterface;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult as ToolboxResult;
use Symfony\AI\Platform\Result\ToolCall as ToolboxCall;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 3).'/vendor/autoload.php';

[$unused, $directory, $role] = $argv;
if ('owner' === $role) {
    TestDirectoryIsolation::createHatfieldTree($directory, withSessions: true);
    $paths = TuiE2eDatabaseEnv::allocateIsolatedPaths(dirname(__DIR__, 3), $directory, 'code-mode-cancel');
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
        $nested = new class($directory, $contexts) implements ToolboxInterface {
            public function __construct(private string $directory, private StackToolExecutionContextAccessor $contexts)
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
                    file_put_contents($this->directory.'/nested-cancelled', json_encode($this->contexts->current()?->cancellationToken()->isCancellationRequested(), \JSON_THROW_ON_ERROR));

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
        $bridge = new CodeModeHostBridge($contexts, $c->get(ToolRuntime::class), $locator, $c->get(RuntimeProcessConfig::class), $execution, $c->get(OutputCap::class));
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
        $workerHandler = new ExecuteToolCallWorker($bus, new TestLogger(), $executor, $c->get(DeferredToolCompletionRepositoryInterface::class), $results, $c->get(RunOperationalStatusReaderInterface::class), payloads: $c->get(ToolPayloadStoreInterface::class));
        $result = $workerHandler($call);
        file_put_contents($directory.'/worker-done', json_encode([
            'processors' => $counter->count,
            'publications' => count($bus->messages),
            'published' => array_map(static fn ($message): string => $message::class, $bus->messages),
            'error' => $result?->isError,
        ], \JSON_THROW_ON_ERROR));
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
            throw new RuntimeException('Nested readiness failed: '.$worker->getErrorOutput().$worker->getOutput());
        }
        usleep(10_000);
    }
    $blocked = json_decode(file_get_contents($directory.'/blocked'), true, flags: \JSON_THROW_ON_ERROR);
    $row = $executions->invocation($f->state->runId, 'cm');
    $handler = $c->get(CodeModeAcknowledgementHandler::class);
    $ack = $handler->handle(new CodeModeOwnerDTO($f->state->runId, $row['identity'], 'cm', true), $f->state);
    $state = $f->commit($f->state, $ack);
    $deferred = $c->get(DeferredToolCompletionRepositoryInterface::class);
    $cancelled = $state->with(['status' => RunStatus::Cancelling]);
    $actions = $c->get(CodeModeInterruptionHookSubscriber::class)->prepareAfterTurnCommit(AfterTurnCommitHookContext::fromRunState($cancelled, [], 0), $state->lastSeq);
    $f->commit($state, new HandlerResult($cancelled, postCommitActions: $actions));
    // Nested handler remains behind the lock, so only the captured fence survives.
    $c->get(RunOperationalProjectionRepository::class)->replace($state->with(['turnNo' => 2, 'status' => RunStatus::Running]));
    flock($barrier, \LOCK_UN);
    fclose($barrier);
    $barrier = null;
    $worker->wait();
    if (!$worker->isSuccessful()) {
        throw new RuntimeException('Host failed: '.$worker->getErrorOutput().$worker->getOutput());
    }
    $done = json_decode(file_get_contents($directory.'/worker-done'), true, flags: \JSON_THROW_ON_ERROR);
    $row = $executions->invocation($state->runId, 'cm');
    $rawReference = $execution->resultReference($row['raw_reference']);
    $c->get(CodeModeControllerSessionLifecycleListener::class)->shutdown(new ControllerSessionShutdownEvent($state->runId));
    echo json_encode([
        'worker' => $done,
        'nested_cancelled' => json_decode(file_get_contents($directory.'/nested-cancelled'), true, flags: \JSON_THROW_ON_ERROR),
        'parent_status' => $c->get(RunOperationalStatusReaderInterface::class)->findOperationalStatus($state->runId)?->status->value,
        'reason' => $row['interruption_reason'],
        'deferred_status' => $deferred->status($row['identity']),
        'raw_exists' => is_file($artifacts->path($rawReference)),
        'workspace_cleaned' => !is_dir(dirname($blocked['script'])),
        'batch_cleaned' => null === $batches->loadSchedule($state->runId, 1, 'step'),
    ], \JSON_THROW_ON_ERROR)."\n";
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
