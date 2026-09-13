<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionHumanInputSuspension;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Tool\Arguments\CodeModeArgumentsDTO;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionExceptionInterface;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

/**
 * Owns one PHP script subprocess and bridges tool() calls to RegistryBackedToolbox.
 *
 * The script loads only the minimal bootstrap (no application autoloader). Nested
 * tool calls reuse the existing toolbox rewrite/validation/handler path under a
 * nested ToolContext. The outer ToolRuntime cancellation token is polled while
 * waiting on the child and nested tool work.
 */
final readonly class CodeModeHostBridge
{
    private const int DEFAULT_GRACE_SECONDS = 5;
    private const int POLL_INTERVAL_MICROS = 20_000;
    private const int MAX_UNIX_SOCKET_PATH_BYTES = 100;
    private const int STDERR_TAIL_CHARS = 4000;
    private const int STDOUT_TAIL_CHARS = 4000;
    private const int SCRIPT_WRAPPER_PREFIX_LINES = 3;
    private const string TOOLBOX_LOCATOR_KEY = 'toolbox';

    public function __construct(
        private StackToolExecutionContextAccessor $contextAccessor,
        private ToolRuntime $toolRuntime,
        private ContainerInterface $toolboxLocator,
        private RuntimeProcessConfig $runtimeProcessConfig,
        private Filesystem $filesystem = new Filesystem(),
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function execute(
        string $script,
        int $timeoutSeconds = CodeModeArgumentsDTO::DEFAULT_TIMEOUT_SECONDS,
        int $memoryLimitMb = CodeModeArgumentsDTO::DEFAULT_MEMORY_LIMIT_MB,
    ): mixed
    {
        return $this->toolRuntime->run(function () use ($script, $timeoutSeconds, $memoryLimitMb): mixed {
            $parentContext = $this->contextAccessor->current();
            $cancelToken = $parentContext?->cancellationToken() ?? new NullCancellationToken();
            $timeoutSeconds = $this->resolveScriptWallSeconds($timeoutSeconds, $parentContext?->timeoutSeconds());
            $memoryLimit = $this->formatMemoryLimit($memoryLimitMb);

            $workspace = $this->createWorkspace();
            $process = null;
            $server = null;
            $connection = null;
            $output = new \stdClass();
            $output->stdout = '';
            $output->stderr = '';

            try {
                $this->writeScript($workspace['script'], $script);
                $server = $this->createSocketServer($workspace['socket']);
                $process = $this->startProcess($workspace, $cancelToken, $memoryLimit);
                $connection = $this->acceptConnection($server, $process, $output, $cancelToken, $timeoutSeconds, $workspace['startedAtNs']);

                return $this->serve($connection, $process, $output, $parentContext, $cancelToken, $timeoutSeconds, $workspace['startedAtNs']);
            } finally {
                $this->stopProcess($process);
                $this->closeResource($connection);
                $this->closeResource($server);
                $this->cleanupWorkspace($workspace);
            }
        });
    }

    /**
     * @param resource  $connection
     * @param \stdClass $output
     */
    private function serve(
        mixed $connection,
        Process $process,
        object $output,
        ?ToolContext $parentContext,
        CancellationTokenInterface $cancelToken,
        ?int $timeoutSeconds,
        int $startedAtNs,
    ): mixed {
        $waiter = function () use ($connection, $process, $output, $cancelToken, $timeoutSeconds, $startedAtNs): void {
            $this->waitWhileBlocked($connection, $process, $output, $cancelToken, $timeoutSeconds, $startedAtNs);
        };

        while (true) {
            $this->assertNotCancelledOrTimedOut($cancelToken, $timeoutSeconds, $startedAtNs);
            $this->drainProcessOutput($process, $output);

            if (!$this->waitForReadable($connection, $cancelToken, $timeoutSeconds, $startedAtNs)) {
                $this->drainProcessOutput($process, $output);
                if (!$process->isRunning()) {
                    // No buffered bytes left after the child exited.
                    throw $this->earlyExitException($process, $output);
                }

                continue;
            }

            $frame = CodeModeIpc::read($connection, $waiter);
            if (null === $frame) {
                $this->drainProcessOutput($process, $output);
                if (!$process->isRunning()) {
                    throw $this->earlyExitException($process, $output);
                }

                // Socket EOF can race process death (for example OOM). Poll briefly
                // so stderr can be drained into the early-exit path instead of a
                // generic closed-connection message.
                throw $this->connectionClosedException($process, $output, $cancelToken, $timeoutSeconds, $startedAtNs);
            }

            $type = $frame['type'] ?? null;
            if ('tool' === $type) {
                $this->handleToolRequest($connection, $frame, $parentContext, $cancelToken, $timeoutSeconds, $startedAtNs, $waiter);
                continue;
            }

            if ('return' === $type) {
                $this->drainProcessOutput($process, $output);
                if (($frame['ok'] ?? false) === true) {
                    try {
                        $result = CodeModeValueCodec::assertEncodable($frame['result'] ?? null, 'Code-mode script return value');
                    } catch (\RuntimeException $exception) {
                        throw new ToolCallException($this->normalizeDiagnosticPaths($exception->getMessage()), retryable: false, previous: $exception);
                    }

                    return $this->packExecutionResult($result, $output);
                }

                $message = \is_string($frame['error'] ?? null) ? $frame['error'] : 'Code-mode script failed.';
                throw new ToolCallException($this->normalizeDiagnosticPaths($message), retryable: false);
            }

            throw new ToolCallException(\sprintf('Unsupported code-mode IPC frame type: %s.', get_debug_type($type)), retryable: false);
        }
    }

    /**
     * @param resource             $connection
     * @param array<string, mixed> $frame
     */
    private function handleToolRequest(
        mixed $connection,
        array $frame,
        ?ToolContext $parentContext,
        CancellationTokenInterface $cancelToken,
        int $timeoutSeconds,
        int $startedAtNs,
        ?callable $waiter,
    ): void {
        $id = $frame['id'] ?? null;
        $name = $frame['name'] ?? null;
        $arguments = $frame['arguments'] ?? [];

        if (!\is_string($id) || '' === $id) {
            throw new ToolCallException('Code-mode tool request requires a non-empty id.', retryable: false);
        }
        if (!\is_string($name) || '' === trim($name)) {
            CodeModeIpc::write($connection, [
                'id' => $id,
                'ok' => false,
                'error' => 'Tool name must be a non-empty string.',
            ], $waiter);

            return;
        }
        if (!\is_array($arguments)) {
            CodeModeIpc::write($connection, [
                'id' => $id,
                'ok' => false,
                'error' => 'Tool arguments must be a JSON object.',
            ], $waiter);

            return;
        }

        /* @var array<string, mixed> $arguments */
        try {
            if ($cancelToken->isCancellationRequested()) {
                throw new ToolCallException('Code-mode tool call cancelled before start.', retryable: false);
            }

            if (CodeModeUnsupportedTools::contains($name)) {
                throw new ToolCallException(CodeModeUnsupportedTools::rejectionMessage($name), retryable: false);
            }

            $result = $this->invokeTool($name, $arguments, $parentContext, $cancelToken, $timeoutSeconds, $startedAtNs);
            CodeModeIpc::write($connection, [
                'id' => $id,
                'ok' => true,
                'result' => $this->normalizeResult($result),
            ], $waiter);
        } catch (\Throwable $exception) {
            CodeModeIpc::write($connection, [
                'id' => $id,
                'ok' => false,
                'error' => $this->formatException($exception),
            ], $waiter);
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function invokeTool(
        string $name,
        array $arguments,
        ?ToolContext $parentContext,
        CancellationTokenInterface $cancelToken,
        int $timeoutSeconds,
        int $startedAtNs,
    ): mixed {
        $remainingSeconds = $this->remainingScriptBudgetSeconds($timeoutSeconds, $startedAtNs);
        if (null === $remainingSeconds) {
            throw new ToolCallException(\sprintf('Code-mode execution timed out after %d seconds.', $timeoutSeconds), retryable: false);
        }

        $toolCallId = 'code-mode-'.bin2hex(random_bytes(8));
        $nestedContext = new ToolContext(
            runId: $parentContext?->runId() ?? '',
            turnNo: $parentContext?->turnNo() ?? 0,
            toolCallId: $toolCallId,
            toolName: $name,
            cancellationToken: $cancelToken,
            timeoutSeconds: $remainingSeconds,
            orderIndex: 0,
            executionMode: ToolExecutionMode::Sequential,
            batchToolCallCount: 1,
            humanInputAnswer: null,
            stepId: $parentContext?->stepId(),
            parentModel: $parentContext?->parentModel(),
        );

        return $this->contextAccessor->with($nestedContext, function () use ($name, $arguments, $toolCallId): mixed {
            try {
                $toolboxResult = $this->toolbox()->execute(new ToolCall($toolCallId, $name, $arguments));
            } catch (ToolNotFoundException $exception) {
                throw new ToolCallException($exception->getMessage(), retryable: false, previous: $exception);
            } catch (ToolExecutionExceptionInterface $exception) {
                $previous = $exception->getPrevious();
                if ($previous instanceof ToolCallException) {
                    throw $previous;
                }

                throw new ToolCallException($previous?->getMessage() ?? $exception->getMessage(), retryable: false, previous: $previous ?? $exception);
            }

            return $toolboxResult->getResult();
        });
    }

    private function normalizeResult(mixed $result): mixed
    {
        if ($result instanceof DeferredToolCompletionOutcome) {
            throw new ToolCallException('Deferred tool completions are not supported inside code_mode.', retryable: false);
        }

        if ($result instanceof ToolExecutionHumanInputSuspension) {
            throw new ToolCallException('Human-input suspensions are not supported inside code_mode.', retryable: false);
        }

        // Nested code_mode may return a diagnostics envelope. Preserve only the
        // nested script value for IPC; nested stdout/stderr stay in that child.
        if ($result instanceof CodeModeExecutionResult) {
            $result = $result->result;
        }

        try {
            return CodeModeValueCodec::assertEncodable($result, 'Nested tool result');
        } catch (\RuntimeException $exception) {
            throw new ToolCallException($exception->getMessage(), retryable: false, previous: $exception);
        }
    }

    private function formatException(\Throwable $exception): string
    {
        if ($exception instanceof ToolCallException) {
            $message = $exception->getMessage();
            $hint = $exception->hint();
            if (null !== $hint && '' !== $hint) {
                return $message."\nHint: ".$hint;
            }

            return $message;
        }

        return $exception->getMessage();
    }

    /**
     * @return array{dir: string, script: string, socket: string, startedAtNs: int}
     */
    private function createWorkspace(): array
    {
        $tempRoot = sys_get_temp_dir();
        $marker = tempnam($tempRoot, 'hcm');
        if (false === $marker) {
            throw new ToolCallException('Failed to allocate a temporary code-mode workspace.', retryable: true);
        }

        // tempnam creates a file; replace it with a short-lived directory for script + socket.
        $this->filesystem->remove($marker);
        $dir = $marker;
        try {
            $this->filesystem->mkdir($dir, 0o700);
        } catch (\Throwable $exception) {
            throw new ToolCallException(\sprintf('Failed to create code-mode workspace "%s".', $dir), retryable: true, previous: $exception);
        }

        $socket = $dir.'/s.sock';
        if (\strlen($socket) > self::MAX_UNIX_SOCKET_PATH_BYTES) {
            $this->filesystem->remove($dir);
            throw new ToolCallException(\sprintf('Code-mode socket path exceeds %d bytes: %s', self::MAX_UNIX_SOCKET_PATH_BYTES, $socket), retryable: false);
        }

        return [
            'dir' => $dir,
            'script' => $dir.'/script.php',
            'socket' => $socket,
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
            $this->filesystem->dumpFile($target, $contents);
        } catch (\Throwable $exception) {
            throw new ToolCallException('Failed to materialize code-mode bootstrap for subprocess execution.', retryable: true, previous: $exception);
        }

        return $target;
    }

    private function materializeValueCodec(string $workspaceDir): string
    {
        $source = __DIR__.'/CodeModeValueCodec.php';
        $target = $workspaceDir.'/CodeModeValueCodec.php';

        try {
            $contents = file_get_contents($source);
            if (false === $contents) {
                throw new \RuntimeException(\sprintf('Unable to read code-mode value codec from "%s".', $source));
            }
            $this->filesystem->dumpFile($target, $contents);
        } catch (\Throwable $exception) {
            throw new ToolCallException('Failed to materialize code-mode value codec for subprocess execution.', retryable: true, previous: $exception);
        }

        return $target;
    }

    /**
     * Materialize the installed HelgeSverre TOON package for the child process.
     *
     * Resolves the package root from the host-loaded class file so PHAR and
     * source trees both work without shipping a forked copy under Resources/.
     */
    private function materializeToonLibrary(string $workspaceDir): string
    {
        $targetRoot = $workspaceDir.'/toon';

        try {
            $sourceRoot = $this->installedToonSourceRoot();
            $this->filesystem->mkdir($targetRoot);

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $relative = substr($file->getPathname(), \strlen($sourceRoot) + 1);
                $this->filesystem->copy($file->getPathname(), $targetRoot.'/'.$relative, true);
            }

            // Preserve upstream MIT license beside the materialized sources.
            $license = \dirname($sourceRoot).'/LICENSE';
            if (is_file($license)) {
                $this->filesystem->copy($license, $targetRoot.'/LICENSE', true);
            }
        } catch (\Throwable $exception) {
            throw new ToolCallException('Failed to materialize code-mode TOON library for subprocess execution.', retryable: true, previous: $exception);
        }

        return $targetRoot;
    }

    private function installedToonSourceRoot(): string
    {
        $toonFile = (new \ReflectionClass(\HelgeSverre\Toon\Toon::class))->getFileName();
        if (!\is_string($toonFile) || '' === $toonFile) {
            throw new \RuntimeException('Unable to resolve HelgeSverre\\Toon\\Toon source path on the host.');
        }

        $sourceRoot = \dirname($toonFile);
        if (!is_dir($sourceRoot)) {
            throw new \RuntimeException(\sprintf('Installed TOON source root is missing at "%s".', $sourceRoot));
        }

        return $sourceRoot;
    }

    /**
     * @return resource
     */
    private function createSocketServer(string $socketPath): mixed
    {
        if ($this->filesystem->exists($socketPath)) {
            $this->filesystem->remove($socketPath);
        }

        $server = @stream_socket_server('unix://'.$socketPath, $errno, $errstr);
        if (false === $server) {
            throw new ToolCallException(\sprintf('Failed to create code-mode socket: [%d] %s', $errno, $errstr), retryable: true);
        }

        stream_set_blocking($server, false);

        return $server;
    }

    /**
     * @param array{dir: string, script: string, socket: string, startedAtNs: int} $workspace
     */
    private function startProcess(array $workspace, CancellationTokenInterface $cancelToken, string $memoryLimit): Process
    {
        if ($cancelToken->isCancellationRequested()) {
            throw new ToolCallException('Code-mode execution cancelled before start.', retryable: false);
        }

        $bootstrap = $this->materializeBootstrap($workspace['dir']);
        $toonRoot = $this->materializeToonLibrary($workspace['dir']);
        $valueCodec = $this->materializeValueCodec($workspace['dir']);
        $process = new Process(
            [$this->phpCliBinary(), $bootstrap],
            $this->runtimeProcessConfig->runtimeCwd(),
            [
                'HATFIELD_CODE_MODE_SOCKET' => $workspace['socket'],
                'HATFIELD_CODE_MODE_SCRIPT' => $workspace['script'],
                'HATFIELD_CODE_MODE_TOON_ROOT' => $toonRoot,
                'HATFIELD_CODE_MODE_VALUE_CODEC' => $valueCodec,
                'HATFIELD_CODE_MODE_MEMORY_LIMIT' => $memoryLimit,
            ],
        );
        $process->setTimeout(null);
        $process->setIdleTimeout(null);
        // Keep stderr available for fatal tails. Drain/discard stdout while waiting
        // so a chatty script cannot grow an unbounded host-side buffer.

        try {
            $process->start();
        } catch (ProcessStartFailedException|ProcessRuntimeException $exception) {
            throw new ToolCallException('Failed to start code-mode PHP subprocess: '.$exception->getMessage(), retryable: true, previous: $exception);
        }

        return $process;
    }

    /**
     * @param resource $server
     *
     * @return resource
     */
    /**
     * @param resource  $server
     * @param \stdClass $output
     *
     * @return resource
     */
    private function acceptConnection(
        mixed $server,
        Process $process,
        object $output,
        CancellationTokenInterface $cancelToken,
        ?int $timeoutSeconds,
        int $startedAtNs,
    ): mixed {
        while (true) {
            $this->assertNotCancelledOrTimedOut($cancelToken, $timeoutSeconds, $startedAtNs);
            $this->drainProcessOutput($process, $output);
            $connection = @stream_socket_accept($server, 0.0);
            if (false !== $connection) {
                stream_set_blocking($connection, false);

                return $connection;
            }

            // Accept before treating child exit as failure. A fast script can
            // connect, write its return frame, and exit while the connection is
            // still queued on the listening socket.
            if (!$process->isRunning()) {
                $this->drainProcessOutput($process, $output);
                $connection = @stream_socket_accept($server, 0.0);
                if (false !== $connection) {
                    stream_set_blocking($connection, false);

                    return $connection;
                }

                throw $this->earlyExitException($process, $output);
            }

            usleep(self::POLL_INTERVAL_MICROS);
        }
    }

    /**
     * @param resource $connection
     */
    private function waitForReadable(
        mixed $connection,
        CancellationTokenInterface $cancelToken,
        ?int $timeoutSeconds,
        int $startedAtNs,
    ): bool {
        $this->assertNotCancelledOrTimedOut($cancelToken, $timeoutSeconds, $startedAtNs);

        $read = [$connection];
        $write = null;
        $except = null;
        $selected = @stream_select($read, $write, $except, 0, self::POLL_INTERVAL_MICROS);
        if (false === $selected) {
            throw new ToolCallException('Failed while waiting for code-mode IPC data.', retryable: true);
        }

        return $selected > 0;
    }

    /**
     * @param resource  $connection
     * @param \stdClass $output
     */
    private function waitWhileBlocked(
        mixed $connection,
        Process $process,
        object $output,
        CancellationTokenInterface $cancelToken,
        ?int $timeoutSeconds,
        int $startedAtNs,
    ): void {
        $this->assertNotCancelledOrTimedOut($cancelToken, $timeoutSeconds, $startedAtNs);
        $this->drainProcessOutput($process, $output);

        $read = [$connection];
        $write = [$connection];
        $except = null;
        $selected = @stream_select($read, $write, $except, 0, self::POLL_INTERVAL_MICROS);
        if (false === $selected) {
            throw new ToolCallException('Failed while waiting for code-mode IPC progress.', retryable: true);
        }

        $this->drainProcessOutput($process, $output);
        if (0 === $selected && !$process->isRunning()) {
            // Progress wait during an in-flight frame; peer death mid-frame is fatal.
            throw $this->earlyExitException($process, $output);
        }
    }

    private function assertNotCancelledOrTimedOut(
        CancellationTokenInterface $cancelToken,
        ?int $timeoutSeconds,
        int $startedAtNs,
    ): void {
        if ($cancelToken->isCancellationRequested()) {
            throw new ToolCallException('Code-mode execution cancelled.', retryable: false);
        }

        if (null !== $timeoutSeconds && $timeoutSeconds > 0) {
            $elapsedNs = hrtime(true) - $startedAtNs;
            if ($elapsedNs >= $timeoutSeconds * 1_000_000_000) {
                throw new ToolCallException(\sprintf('Code-mode execution timed out after %d seconds.', $timeoutSeconds), retryable: false);
            }
        }
    }

    /**
     * Prefer the remaining parent tool budget when present and smaller than the
     * requested script wall limit. Nested calls later receive the remaining
     * script budget as cooperative ToolContext metadata.
     */
    private function resolveScriptWallSeconds(int $requestedTimeoutSeconds, ?int $parentTimeoutSeconds): int
    {
        $requestedTimeoutSeconds = max(1, $requestedTimeoutSeconds);
        if (null !== $parentTimeoutSeconds && $parentTimeoutSeconds > 0) {
            return min($parentTimeoutSeconds, $requestedTimeoutSeconds);
        }

        return $requestedTimeoutSeconds;
    }

    private function formatMemoryLimit(int $memoryLimitMb): string
    {
        return max(1, $memoryLimitMb).'M';
    }

    /**
     * Remaining whole seconds of the script wall budget for nested ToolContext.
     *
     * Returns null when the budget is already exhausted. Nested handlers remain
     * cooperative: a blocking handler can still overrun until it returns.
     */
    private function remainingScriptBudgetSeconds(int $timeoutSeconds, int $startedAtNs): ?int
    {
        if ($timeoutSeconds <= 0) {
            return null;
        }

        $elapsedSeconds = intdiv(hrtime(true) - $startedAtNs, 1_000_000_000);
        $remaining = $timeoutSeconds - $elapsedSeconds;
        if ($remaining <= 0) {
            return null;
        }

        return $remaining;
    }

    /**
     * @param \stdClass $output
     */
    private function connectionClosedException(
        Process $process,
        object $output,
        CancellationTokenInterface $cancelToken,
        int $timeoutSeconds,
        int $startedAtNs,
    ): ToolCallException {
        $deadlineNs = hrtime(true) + 200_000_000;
        while ($process->isRunning() && hrtime(true) < $deadlineNs) {
            $this->assertNotCancelledOrTimedOut($cancelToken, $timeoutSeconds, $startedAtNs);
            $this->drainProcessOutput($process, $output);
            usleep(self::POLL_INTERVAL_MICROS);
        }

        $this->drainProcessOutput($process, $output);
        if (!$process->isRunning()) {
            return $this->earlyExitException($process, $output);
        }

        return new ToolCallException('Code-mode script closed the host connection before returning a value.', retryable: false);
    }

    /**
     * @param \stdClass $output
     */
    private function earlyExitException(Process $process, object $output): ToolCallException
    {
        $this->drainProcessOutput($process, $output);

        $exitCode = $process->getExitCode();
        $message = \sprintf(
            'Code-mode PHP subprocess exited without returning a value (exit code %s).',
            null === $exitCode ? 'unknown' : (string) $exitCode,
        );

        $stdoutTail = trim((string) $output->stdout);
        $stderrTail = trim((string) $output->stderr);
        if ('' !== $stdoutTail) {
            $message .= "\nstdout:\n".$this->normalizeDiagnosticPaths($stdoutTail);
        }
        if ('' !== $stderrTail) {
            $message .= "\nstderr:\n".$this->normalizeDiagnosticPaths($stderrTail);
        }

        return new ToolCallException($message, retryable: false);
    }

    /**
     * @param \stdClass $output
     */
    private function drainProcessOutput(Process $process, object $output): void
    {
        try {
            $stdoutChunk = $process->getIncrementalOutput();
            if ('' === $stdoutChunk && !$process->isRunning()) {
                $full = $process->getOutput();
                if (\strlen($full) > \strlen((string) $output->stdout)) {
                    $stdoutChunk = substr($full, \strlen((string) $output->stdout));
                }
            }

            $stderrChunk = $process->getIncrementalErrorOutput();
            if ('' === $stderrChunk && !$process->isRunning()) {
                // Final drain: Incremental can miss already-buffered stderr after exit.
                $full = $process->getErrorOutput();
                if (\strlen($full) > \strlen((string) $output->stderr)) {
                    $stderrChunk = substr($full, \strlen((string) $output->stderr));
                }
            }
        } catch (\Symfony\Component\Process\Exception\LogicException) {
            return;
        }

        if ('' !== $stdoutChunk) {
            $output->stdout .= $stdoutChunk;
            if (\strlen((string) $output->stdout) > self::STDOUT_TAIL_CHARS) {
                $output->stdout = substr((string) $output->stdout, -self::STDOUT_TAIL_CHARS);
            }
        }

        if ('' !== $stderrChunk) {
            $output->stderr .= $stderrChunk;
            if (\strlen((string) $output->stderr) > self::STDERR_TAIL_CHARS) {
                $output->stderr = substr((string) $output->stderr, -self::STDERR_TAIL_CHARS);
            }
        }
    }

    /**
     * @param \stdClass $output
     */
    private function packExecutionResult(mixed $result, object $output): mixed
    {
        $diagnostics = [];
        $stdout = trim((string) $output->stdout);
        $stderr = trim((string) $output->stderr);
        if ('' !== $stdout) {
            $diagnostics['stdout'] = $this->normalizeDiagnosticPaths($stdout);
        }
        if ('' !== $stderr) {
            $diagnostics['stderr'] = $this->normalizeDiagnosticPaths($stderr);
        }

        if ([] === $diagnostics) {
            return $result;
        }

        return new CodeModeExecutionResult($result, $diagnostics);
    }

    private function normalizeDiagnosticPaths(string $text): string
    {
        $prefixLines = self::SCRIPT_WRAPPER_PREFIX_LINES;
        $normalized = preg_replace_callback(
            '#(?:phar://)?[^\s"\']+/(script|bootstrap)\.php(?:\((\d+)\)| on line (\d+))#',
            static function (array $matches) use ($prefixLines): string {
                $file = $matches[1];
                $lineToken = $matches[2] ?? '';
                if ('' === $lineToken) {
                    $lineToken = $matches[3] ?? '0';
                }
                $line = (int) $lineToken;
                if ('script' === $file && $line > $prefixLines) {
                    $line -= $prefixLines;
                }

                return $file.'.php('.$line.')';
            },
            $text,
        );

        return \is_string($normalized) ? $normalized : $text;
    }

    private function stopProcess(?Process $process): void
    {
        if (null === $process) {
            return;
        }

        try {
            if ($process->isRunning()) {
                $process->stop(self::DEFAULT_GRACE_SECONDS);
            }
        } catch (ProcessRuntimeException $exception) {
            $this->logger->warning('code_mode.process_stop_failed', [
                'component' => 'tool.code_mode',
                'event_type' => 'code_mode.process_stop_failed',
                'error_type' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function closeResource(mixed $resource): void
    {
        if (\is_resource($resource) && !@fclose($resource)) {
            $this->logger->warning('code_mode.resource_close_failed', [
                'component' => 'tool.code_mode',
                'event_type' => 'code_mode.resource_close_failed',
            ]);
        }
    }

    /**
     * @param array{dir: string, script: string, socket: string, startedAtNs: int} $workspace
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

    private function toolbox(): ToolboxInterface
    {
        if (!$this->toolboxLocator->has(self::TOOLBOX_LOCATOR_KEY)) {
            throw new \LogicException('Code-mode toolbox locator is missing the toolbox entry.');
        }

        $toolbox = $this->toolboxLocator->get(self::TOOLBOX_LOCATOR_KEY);
        if (!$toolbox instanceof ToolboxInterface) {
            throw new \LogicException(\sprintf('Code-mode toolbox locator must return ToolboxInterface, got %s.', get_debug_type($toolbox)));
        }

        return $toolbox;
    }

    /**
     * Resolve a PHP CLI interpreter that can execute a materialized bootstrap file.
     *
     * Reuses {@see RuntimeProcessConfig::executableCommand()} packaging rules:
     * multi-arg commands provide the interpreter as argv[0]; fused native
     * single-arg executables do not expose a separate PHP CLI and are unsupported
     * for code_mode script subprocesses.
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

        if (1 === \count($command)) {
            throw new ToolCallException('code_mode requires a PHP CLI interpreter to run script subprocesses. Fused native/static executables are unsupported for this tool.', retryable: false);
        }

        throw new ToolCallException('PHP CLI binary is unavailable for code-mode subprocess execution.', retryable: false);
    }
}
