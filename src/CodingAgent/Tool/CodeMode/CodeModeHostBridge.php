<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\CodingAgent\Agent\Tool\AgentResumeToolHandler;
use Ineersa\CodingAgent\Agent\Tool\AgentSteerTool;
use Ineersa\CodingAgent\Agent\Tool\ForkToolHandler;
use Ineersa\CodingAgent\Agent\Tool\NotifyParentTool;
use Ineersa\CodingAgent\Agent\Tool\SubagentToolHandler;
use Ineersa\CodingAgent\Application\Message\DTO\BackgroundCodeModeInvocationDTO;
use Ineersa\CodingAgent\Entity\DeferredToolCompletionRepository;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;
use Ineersa\CodingAgent\Tool\Arguments\CodeModeArgumentsDTO;
use Ineersa\CodingAgent\Tool\AskHumanTool;
use Ineersa\CodingAgent\Tool\CodeMode\DTO\CodeModeReplyDTO;
use Ineersa\CodingAgent\Tool\CodeModeTool;
use Ineersa\SqliteQueue\Client;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\UuidV7;
use Symfony\Component\Validator\Validation;

/** Launches session-owned PHP; a shared worker delivers its durable return. */
final readonly class CodeModeHostBridge
{
    public function __construct(
        private StackToolExecutionContextAccessor $contextAccessor,
        private RuntimeProcessConfig $runtimeProcessConfig,
        private DeferredToolCompletionRepository $deferred,
        private ToolBatchStoreInterface $batches,
        private MessageBusInterface $commandBus,
        private CodeModeProcessOwner $processOwner,
        private CodeModeRuntimeMaterializer $runtime,
        private ToolBatchRunStoragePathsInterface $paths,
        private CodeModeReplyTransportFactory $replies,
        private ToolPayloadStoreInterface $payloads,
        private Filesystem $filesystem = new Filesystem(),
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function execute(string $script, ?int $timeoutSeconds = null, int $memoryLimitMb = CodeModeArgumentsDTO::DEFAULT_MEMORY_LIMIT_MB, bool $backgrounded = false): DeferredToolCompletionOutcome|ToolResult
    {
        $context = $this->contextAccessor->current();
        if (null === $context || '' === $context->runId() || CodeModeTool::NAME !== $context->toolName()) {
            throw new ToolCallException('Code-mode launch requires a valid session invocation.', retryable: false);
        }
        $endpoint = $_SERVER['HATFIELD_SQLITE_QUEUE_ENDPOINT'] ?? $_ENV['HATFIELD_SQLITE_QUEUE_ENDPOINT'] ?? getenv('HATFIELD_SQLITE_QUEUE_ENDPOINT');
        if (!\is_string($endpoint) || '' === $endpoint) {
            throw new ToolCallException('Code-mode session broker unavailable.', retryable: false);
        }
        try {
            Client::connect($endpoint)->close();
        } catch (\Exception $error) {
            throw new ToolCallException('Code-mode session broker unavailable.', retryable: false, previous: $error);
        }
        $token = $context->cancellationToken();
        $standalone = null === $context->stepId();
        if ($standalone && ($backgrounded || '' === $context->toolCallId())) {
            throw new ToolCallException('Standalone code-mode requires a foreground invocation.', retryable: false);
        }
        $id = $standalone ? $context->toolCallId() : $this->prepareDeferred($context);
        if ($token->isCancellationRequested()) {
            throw new ToolCallException('Code-mode execution cancelled before start.', retryable: false);
        }
        $workspace = $this->createWorkspace();
        $owned = false;
        try {
            $this->writeScript($workspace['script'], $script);
            $this->startProcess($workspace, $token, $this->formatMemoryLimit($memoryLimitMb), $context->runId(), $id, $endpoint, [
                'explicitTimeout' => $timeoutSeconds,
                'callerTimeout' => $context->timeoutSeconds(),
                'retainOutput' => $context->retainOutput(),
                'attachmentOwner' => $context->attachmentOwnerToolCallId(),
            ], $standalone ? $context->turnNo() : null);
            $owned = true;
            if ($standalone) {
                return $this->awaitStandalone($context, $id, $endpoint);
            }
            if ($backgrounded) {
                $this->commandBus->dispatch(new BackgroundCodeModeInvocationDTO($context->runId(), $id, $context->toolCallId()));
            }

            return new DeferredToolCompletionOutcome($id);
        } finally {
            if (!$owned && null === $this->processOwner->invocation($context->runId(), $id)) {
                $this->cleanupWorkspace($workspace);
            }
        }
    }

    private function awaitStandalone(ToolContext $context, string $id, string $endpoint): ToolResult
    {
        $runId = $context->runId();
        $queue = CodeModeReplyTransportFactory::standaloneQueueId($runId, $id);
        $transport = $this->replies->create($queue);
        $client = Client::connect($endpoint);
        $deadline = $this->processOwner->deadline($runId, $id, false);
        $exitedAt = null;
        try {
            while (true) {
                foreach ($transport->get() as $delivery) {
                    $message = $delivery->getMessage();
                    if (!$message instanceof CodeModeReplyDTO || $id !== $message->callId || $runId !== $message->payload->runId) {
                        $transport->reject($delivery);
                        continue;
                    }
                    $payload = $this->payloads->read($message->payload);
                    if (!$payload instanceof ToolCallResult || !\is_array($payload->result)) {
                        throw new ToolCallException('Invalid standalone code-mode reply.', retryable: false);
                    }
                    $transport->ack($delivery);
                    $this->payloads->deleteCall($runId, $message->payload->toolCallId);

                    return new ToolResult($id, CodeModeTool::NAME, $payload->result['content'], $payload->result['details'], $payload->isError);
                }
                $failure = $this->processOwner->observeTerminal($runId, $id, $deadline,
                    $context->cancellationToken()->isCancellationRequested() ? 'cancelled' : null,
                    static function () use (&$exitedAt): ?array {
                        $exitedAt ??= hrtime(true);

                        return null;
                    });
                if (null !== $failure) {
                    throw new ToolCallException($failure['error'] ?? 'Code-mode subprocess failed.', retryable: false);
                }
                if (null !== $exitedAt && hrtime(true) - $exitedAt >= 60_000_000_000) {
                    throw new ToolCallException('Code-mode subprocess exited without a delivered result.', retryable: false);
                }
                $client->wait('code_mode_reply.'.$queue, 100);
            }
        } finally {
            $client->close();
            $transport->close();
        }
    }

    /**
     * Register only an actual top-level handler invocation, after budget validation.
     *
     * Nested and no-context invocations have no deferred caller. Existing
     * registrations retain their identity when the same intent is delivered again.
     */
    private function prepareDeferred(ToolContext $parentContext): string
    {
        if ($this->contextAccessor->depth() > 1) {
            throw new ToolCallException('Code-mode launch requires canonical invocation correlation.', retryable: false);
        }
        $call = $this->batches->loadCall($parentContext->runId(), $parentContext->turnNo(), $parentContext->stepId(), $parentContext->toolCallId());
        if (!$call instanceof ExecuteToolCall || 'code_mode' !== $call->toolName) {
            throw new ToolCallException('Code-mode registration requires a canonical batch invocation.', retryable: false);
        }
        $metadata = $this->deferred->findMetadataByRunAndToolCall($call->runId(), $call->toolCallId);
        if (null === $metadata) {
            return $this->deferred->preparePending(ToolCallResultFactory::correlationFromExecuteToolCall($call, UuidV7::v7()->toRfc4122()))->deferredId;
        }
        $stored = $metadata->reference;
        if (
            'code_mode' !== $stored->toolName
            || [$stored->runId, $stored->turnNo, $stored->stepId, $stored->attempt, $stored->idempotencyKey, $stored->toolCallId, $stored->orderIndex]
            !== [$call->runId(), $call->turnNo(), $call->stepId(), $call->attempt(), $call->idempotencyKey(), $call->toolCallId, $call->orderIndex]
        ) {
            throw new ToolCallException('Code-mode registration does not match its canonical batch invocation.', retryable: false);
        }

        return $metadata->deferredId;
    }

    /**
     * @return array{dir: string, script: string, startedAtNs: int}
     */
    private function createWorkspace(): array
    {
        $tempRoot = sys_get_temp_dir();
        $marker = tempnam($tempRoot, 'hcm');
        if (false === $marker) {
            throw new ToolCallException('Failed to allocate a temporary code-mode workspace.', retryable: true);
        }

        // tempnam creates a file; replace it with a short-lived directory for script and diagnostics.
        $this->filesystem->remove($marker);
        $dir = $marker;
        try {
            $this->filesystem->mkdir($dir, 0o700);
        } catch (\Throwable $exception) {
            throw new ToolCallException(\sprintf('Failed to create code-mode workspace "%s".', $dir), retryable: true, previous: $exception);
        }

        return [
            'dir' => $dir,
            'script' => $dir.'/script.php',
            'startedAtNs' => hrtime(true),
        ];
    }

    private function writeScript(string $path, string $script): void
    {
        $body = "<?php\ndeclare(strict_types=1);\nreturn (static function () {\n".$script."\n})();\n";
        try {
            $this->filesystem->dumpFile($path, $body);
        } catch (\Throwable $exception) {
            throw new ToolCallException(\sprintf('Failed to write code-mode script "%s".', $path), retryable: true, previous: $exception);
        }
    }

    private function materializeBootstrap(string $workspaceDir): string
    {
        $source = __DIR__.'/Resources/bootstrap.php.inc';
        $target = $workspaceDir.'/bootstrap.php';

        try {
            $contents = file_get_contents($source);
            if (false === $contents) {
                throw new \RuntimeException(\sprintf('Unable to read code-mode bootstrap from "%s".', $source));
            }
            $this->filesystem->dumpFile($target, str_replace('/* @unsupported-tools@ */ []', var_export([
                SubagentToolHandler::NAME,
                ForkToolHandler::NAME,
                AgentResumeToolHandler::NAME,
                AgentSteerTool::NAME,
                NotifyParentTool::NAME,
                AskHumanTool::NAME,
            ], true), $contents));
        } catch (\Throwable $exception) {
            throw new ToolCallException('Failed to materialize code-mode bootstrap for subprocess execution.', retryable: true, previous: $exception);
        }

        return $target;
    }

    /**
     * @param array{dir: string, script: string, startedAtNs: int}                                           $workspace
     * @param array{explicitTimeout: ?int, callerTimeout: ?int, retainOutput: bool, attachmentOwner: string} $policy
     */
    private function startProcess(array $workspace, CancellationTokenInterface $cancelToken, string $memoryLimit, string $runId, string $invocationId, string $endpoint, array $policy, ?int $standaloneTurn): void
    {
        if ($cancelToken->isCancellationRequested()) {
            throw new ToolCallException('Code-mode execution cancelled before start.', retryable: false);
        }

        $bootstrap = $this->materializeBootstrap($workspace['dir']);
        $this->runtime->materialize($workspace['dir'].'/runtime');
        // Keep stderr for warnings/fatals, but suppress display_errors and Xdebug
        // stacks so each warning is not mirrored into stdout with a call stack.
        // Process's pipe reader can aggregate arbitrarily many bytes before
        // callbacks run. Redirect into the private workspace instead; no full
        // diagnostic stream enters Process buffers. Only fixed argv is shell
        // quoted, never user script source. exec keeps the owned PID on PHP.
        $command = [
            $this->phpCliBinary(),
            '-d', 'display_errors=0',
            '-d', 'display_startup_errors=0',
            '-d', 'html_errors=0',
            '-d', 'xdebug.mode=off',
            '-d', 'log_errors=1',
            '-d', 'error_log=',
            $bootstrap,
        ];
        $shell = 'exec '.implode(' ', array_map(escapeshellarg(...), $command))
            .' > '.escapeshellarg($workspace['dir'].'/stdout.log')
            .' 2> '.escapeshellarg($workspace['dir'].'/stderr.log');
        $cwd = $this->runtimeProcessConfig->runtimeCwd();
        $environment = [
            'HATFIELD_CODE_MODE_SCRIPT' => $workspace['script'],
            'HATFIELD_CODE_MODE_RUN' => $runId,
            'HATFIELD_CODE_MODE_BATCH_DIR' => $this->paths->resolveToolBatchesDirectory($runId),
            'HATFIELD_CODE_MODE_MEMORY_LIMIT' => $memoryLimit,
        ];
        $environment['HATFIELD_SQLITE_QUEUE_ENDPOINT'] = $endpoint;
        $environment['HATFIELD_CODE_MODE_INVOCATION'] = $invocationId;
        $environment['HATFIELD_CODE_MODE_ROOT'] = $invocationId;
        $assignments = '';
        foreach ($environment as $name => $value) {
            $assignments .= $name.'='.escapeshellarg($value).' ';
        }
        $this->processOwner->launch($runId, $invocationId, $workspace['dir'], 'cd '.escapeshellarg($cwd).' && '.$assignments.$shell, $workspace['startedAtNs'], policy: $policy, admit: null === $standaloneTurn ? fn () => $this->deferred->markReady($invocationId) : null, standaloneTurn: $standaloneTurn,
            afterExit: 'HATFIELD_SQLITE_QUEUE_ENDPOINT='.escapeshellarg($endpoint).' '.implode(' ', array_map(escapeshellarg(...), [
                ...$this->runtimeProcessConfig->executableCommand(), CodeModeWakeup::FLAG, $runId, $invocationId,
            ])),
        );
    }

    private function formatMemoryLimit(int $memoryLimitMb): string
    {
        return max(1, $memoryLimitMb).'M';
    }

    /**
     * @param array{dir: string, script: string, startedAtNs: int} $workspace
     */
    private function cleanupWorkspace(array $workspace): void
    {
        try {
            $this->filesystem->remove($workspace['dir']);
        } catch (\Throwable $exception) {
            $this->logger->warning('code_mode.workspace_cleanup_failed', [
                'component' => 'tool.code_mode',
                'event_type' => 'code_mode.workspace_cleanup_failed',
                'error_type' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Resolve a PHP CLI interpreter that can execute a materialized bootstrap file.
     *
     * Checkout/PHAR packaging reuses argv[0] from
     * {@see RuntimeProcessConfig::executableCommand()}. Fused native/static
     * builds resolve `php` from PATH instead of requiring a hardcoded absolute
     * path. The fused binary itself is not a general PHP CLI.
     */
    private function phpCliBinary(): string
    {
        $command = $this->runtimeProcessConfig->executableCommand();
        if (\count($command) >= 2) {
            $binary = $command[0];
            if ('' !== $binary) {
                return $binary;
            }
        }

        $finder = new ExecutableFinder();
        $fromPath = $finder->find('php');
        if (\is_string($fromPath) && '' !== $fromPath) {
            return $fromPath;
        }

        throw new ToolCallException('code_mode requires an installed PHP CLI on PATH (command `php`) to run script subprocesses. Fused native/static Hatfield binaries do not provide that interpreter.', retryable: false);
    }
}
