<?php

declare(strict_types=1);

use Ineersa\AgentCore\Application\Handler\CompleteDeferredToolCallHandler;
use Ineersa\AgentCore\Application\Handler\ExecuteToolCallWorker;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolResultProcessorInterface;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Message\ToolPayloadMessage;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\DTO\ProcessedToolResultDTO;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeAcknowledgementHandler;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeExecutionService;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeInterruptionHookSubscriber;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeProcessedResultSubscriber;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeResultArtifactStore;
use Ineersa\CodingAgent\Application\CodeMode\DTO\CodeModeOwnerDTO;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Entity\BackgroundCompletionReceiptRepository;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Migrations\StartupDatabaseMigrator;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
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
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 3).'/vendor/autoload.php';

[$unused, $directory, $role] = $argv;
$scenario = $argv[3] ?? 'normal';
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
        $subscriber = new CodeModeProcessedResultSubscriber(
            $executions,
            $artifacts,
            $c->get(DeferredToolCompletionRepositoryInterface::class),
            $execution,
            $c->get(RunLockManager::class),
            $bus,
            $c->get(CompleteDeferredToolCallHandler::class),
        );
        $dispatcher->addListener(ProcessedToolResultDTO::class, $subscriber);
        $workerHandler = new ExecuteToolCallWorker($bus, new TestLogger(), $executor, $c->get(DeferredToolCompletionRepositoryInterface::class), $results, $c->get(RunOperationalStatusReaderInterface::class), eventDispatcher: $dispatcher, payloads: $c->get(ToolPayloadStoreInterface::class));
        $result = $workerHandler($call);
        file_put_contents($directory.'/worker-done', json_encode(['processors' => $counter->count, 'result' => $result?->result, 'error' => $result?->isError, 'publications' => count($bus->messages), 'published' => array_map(static fn ($message): string => $message::class, $bus->messages)], \JSON_THROW_ON_ERROR));
        // Persist the sealed deferred publication into the shared owner transport
        // after the host finishes, using the real command bus rather than TestMessageBus.
        if ([] !== $bus->messages) {
            file_put_contents($directory.'/worker-publications', serialize($bus->messages));
        }
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
    $worker = new Process([\PHP_BINARY, __FILE__, $directory, 'worker', $scenario], $directory);
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
    $handler = $c->get(CodeModeAcknowledgementHandler::class);
    $message = new CodeModeOwnerDTO($f->state->runId, $row['identity'], 'cm', true);
    $ack = $handler->handle($message, $f->state);
    $state = $f->commit($f->state, $ack);
    $deferred = $c->get(DeferredToolCompletionRepositoryInterface::class);
    $evidence = ['ack_events' => array_column($ack->events, 'type'),
        'ack_acknowledged' => null !== $deferred->acknowledgedAt($row['identity']),
        'ack_status' => $deferred->status($row['identity']),
        'worker_alive_after_ack' => $worker->isRunning(), 'script_live_after_ack' => is_file($blocked['script']),
        'socket_live_after_ack' => file_exists(dirname($blocked['script']).'/s.sock'),
        'host_pid' => $blocked['host'], 'owner_pid' => getmypid(),
        'duplicate_ack_events' => count($handler->handle($message, $state)->events),
        'schedule_in_flight' => $batches->loadSchedule($state->runId, 1, 'step')?->inFlight];
    if ('cancel' === $scenario) {
        $cancelled = $state->with(['status' => RunStatus::Cancelling]);
        $actions = $c->get(CodeModeInterruptionHookSubscriber::class)->prepareAfterTurnCommit(AfterTurnCommitHookContext::fromRunState($cancelled, [], 0), $state->lastSeq);
        $f->commit($state, new HandlerResult($cancelled, postCommitActions: $actions));
        // The nested handler is still behind the lock, so it cannot have observed
        // the transient parent status. Only the captured invocation fence survives.
        $c->get(RunOperationalProjectionRepository::class)->replace($state->with(['turnNo' => 2, 'status' => RunStatus::Running]));
    }
    flock($barrier, \LOCK_UN);
    fclose($barrier);
    $barrier = null;
    $worker->wait();
    if (!$worker->isSuccessful()) {
        throw new RuntimeException('Host failed: '.$worker->getErrorOutput().$worker->getOutput());
    }
    $done = json_decode(file_get_contents($directory.'/worker-done'), true, flags: \JSON_THROW_ON_ERROR);
    if ('cancel' === $scenario) {
        $row = $executions->invocation($state->runId, 'cm');
        $rawReference = $execution->resultReference($row['raw_reference']);
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

        return;
    }
    $commandBus = $c->get('agent.command.bus');
    $transport = $c->get('messenger.transport.run_control');
    $transport->reset();
    // Worker publications used an isolated TestMessageBus. Replay the exact
    // CompleteDeferredToolCall onto the owner bus so sealing and consumption run.
    $publications = is_file($directory.'/worker-publications')
        ? unserialize(file_get_contents($directory.'/worker-publications'), ['allowed_classes' => true])
        : [];
    if (!is_array($publications) || [] === $publications) {
        throw new RuntimeException('Worker did not publish a deferred completion.');
    }
    foreach ($publications as $publication) {
        if (!$publication instanceof CompleteDeferredToolCall) {
            throw new RuntimeException('Worker published an unexpected message: '.$publication::class);
        }
        $commandBus->dispatch($publication);
    }
    $seen = 0;
    $noticeIdentity = 'background-'.$row['identity'];
    $accepted = null;
    for ($i = 0; $i < 32; ++$i) {
        $sent = $transport->getSent();
        $accepted ??= $deferred->findAcceptedResultReferenceByDeferredId($row['identity']);
        if ($seen >= count($sent)
            && null !== $accepted
            && null !== $c->get(BackgroundCompletionReceiptRepository::class)->receipt($noticeIdentity)
            && 'completed' === $deferred->status($row['identity'])) {
            break;
        }
        for (; $seen < count($sent); ++$seen) {
            $envelope = $sent[$seen];
            $payload = $envelope->getMessage();
            if ($payload instanceof CompleteDeferredToolCall) {
                throw new RuntimeException('Inline deferred bodies cannot be received.');
            }
            if ($payload instanceof ToolPayloadMessage
                || $payload instanceof ToolCallResult
                || $payload instanceof DeliverBackgroundCompletionDTO) {
                $commandBus->dispatch($envelope->with(new ReceivedStamp('run_control')));
            }
        }
        usleep(10_000);
    }
    // Nested ToolCallResult finalizes asynchronously. Redispatch completion so
    // settle/delete can remove the retained settled batch after finalized=1.
    foreach ($publications as $publication) {
        $commandBus->dispatch($publication);
    }
    for ($i = 0; $i < 16; ++$i) {
        $sent = $transport->getSent();
        $progress = false;
        foreach ($sent as $envelope) {
            $payload = $envelope->getMessage();
            if ($payload instanceof ToolPayloadMessage || $payload instanceof ToolCallResult || $payload instanceof DeliverBackgroundCompletionDTO) {
                $commandBus->dispatch($envelope->with(new ReceivedStamp('run_control')));
                $progress = true;
            }
        }
        if (!$progress && null === $batches->loadSchedule($state->runId, 1, 'step')) {
            break;
        }
        usleep(5_000);
    }
    $accepted ??= $deferred->findAcceptedResultReferenceByDeferredId($row['identity']);
    if (null === $accepted) {
        throw new RuntimeException('Shared terminal acceptance missing after host completion.');
    }
    $acceptedResult = $artifacts->read($accepted);
    $row = $executions->invocation($state->runId, 'cm');
    $rawRef = $execution->resultReference($row['raw_reference']);
    $rawPath = $artifacts->path($rawRef);
    $raw = $artifacts->read($rawRef);
    $automaticCleanup = null === $batches->loadSchedule($state->runId, 1, 'step')
        && null === $batches->loadCallReference($state->runId, 1, 'step', 'cm');
    $receipt = $c->get(BackgroundCompletionReceiptRepository::class)->receipt($noticeIdentity)
        ?? throw new RuntimeException('Background-completion admission did not persist a receipt.');
    $events = $c->get(PreparedTransitionEventStoreInterface::class)->allFor($state->runId);
    $admissionEvents = array_values(array_filter(
        array_column($events, 'type'),
        static fn (string $type): bool => in_array($type, ['agent_command_queued', 'agent_command_applied', 'tool_execution_end'], true),
    ));
    $transport->reset();
    $commandBus->dispatch(new DeliverBackgroundCompletionDTO($state->runId, $noticeIdentity, $receipt->text));
    $duplicateNotice = 0;
    foreach ($transport->getSent() as $envelope) {
        if ($envelope->getMessage() instanceof DeliverBackgroundCompletionDTO) {
            $before = count($c->get(PreparedTransitionEventStoreInterface::class)->allFor($state->runId));
            $commandBus->dispatch($envelope->with(new ReceivedStamp('run_control')));
            $after = count($c->get(PreparedTransitionEventStoreInterface::class)->allFor($state->runId));
            $duplicateNotice += $after - $before;
        }
    }
    $terminalBefore = count(array_filter($events, static fn ($event): bool => 'tool_execution_end' === $event->type));
    foreach ($publications as $publication) {
        $commandBus->dispatch($publication);
    }
    foreach ($transport->getSent() as $envelope) {
        $payload = $envelope->getMessage();
        if ($payload instanceof ToolPayloadMessage || $payload instanceof ToolCallResult) {
            $commandBus->dispatch($envelope->with(new ReceivedStamp('run_control')));
        }
    }
    $terminalAfter = count(array_filter(
        $c->get(PreparedTransitionEventStoreInterface::class)->allFor($state->runId),
        static fn ($event): bool => 'tool_execution_end' === $event->type,
    ));
    echo json_encode([...$evidence, 'worker' => $done, 'notice' => $receipt->text,
        'admission_events' => $admissionEvents,
        'duplicate_terminal_effects' => max(0, $terminalAfter - $terminalBefore),
        'duplicate_notice_events' => $duplicateNotice,
        'automatic_batch_cleanup' => $automaticCleanup,
        'accepted_result' => 'completed' === $deferred->status($row['identity']),
        'deferred_status' => $deferred->status($row['identity']),
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
