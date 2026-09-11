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
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Psr\Container\ContainerInterface;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionExceptionInterface;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Result\ToolCall;
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
    private const string TOOLBOX_LOCATOR_KEY = 'toolbox';

    public function __construct(
        private StackToolExecutionContextAccessor $contextAccessor,
        private ToolRuntime $toolRuntime,
        private ContainerInterface $toolboxLocator,
        private string $projectDir,
    ) {
    }

    public function execute(string $script): mixed
    {
        return $this->toolRuntime->run(function () use ($script): mixed {
            $parentContext = $this->contextAccessor->current();
            $cancelToken = $parentContext?->cancellationToken() ?? new NullCancellationToken();
            $timeoutSeconds = $parentContext?->timeoutSeconds();

            $workspace = $this->createWorkspace();
            $process = null;
            $server = null;
            $connection = null;

            try {
                $this->writeScript($workspace['script'], $script);
                $server = $this->createSocketServer($workspace['socket']);
                $process = $this->startProcess($workspace, $cancelToken, $timeoutSeconds);
                $connection = $this->acceptConnection($server, $process, $cancelToken, $timeoutSeconds, $workspace['startedAtNs']);

                return $this->serve($connection, $process, $parentContext, $cancelToken, $timeoutSeconds, $workspace['startedAtNs']);
            } finally {
                $this->stopProcess($process);
                $this->closeResource($connection);
                $this->closeResource($server);
                $this->cleanupWorkspace($workspace);
            }
        });
    }

    /**
     * @param resource $connection
     */
    private function serve(
        mixed $connection,
        Process $process,
        ?ToolContext $parentContext,
        CancellationTokenInterface $cancelToken,
        ?int $timeoutSeconds,
        int $startedAtNs,
    ): mixed {
        while (true) {
            $this->assertStillRunning($process, $cancelToken, $timeoutSeconds, $startedAtNs);

            $ready = $this->waitForReadable($connection, $process, $cancelToken, $timeoutSeconds, $startedAtNs);
            if (!$ready) {
                continue;
            }

            $frame = CodeModeIpc::read($connection);
            if (null === $frame) {
                throw new ToolCallException('Code-mode script closed the host connection before returning a value.', retryable: false);
            }

            $type = $frame['type'] ?? null;
            if ('tool' === $type) {
                $this->handleToolRequest($connection, $frame, $parentContext, $cancelToken);
                continue;
            }

            if ('return' === $type) {
                if (($frame['ok'] ?? false) === true) {
                    return $frame['result'] ?? null;
                }

                $message = \is_string($frame['error'] ?? null) ? $frame['error'] : 'Code-mode script failed.';
                throw new ToolCallException($message, retryable: false);
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
            ]);

            return;
        }
        if (!\is_array($arguments)) {
            CodeModeIpc::write($connection, [
                'id' => $id,
                'ok' => false,
                'error' => 'Tool arguments must be a JSON object.',
            ]);

            return;
        }

        /* @var array<string, mixed> $arguments */
        try {
            if ($cancelToken->isCancellationRequested()) {
                throw new ToolCallException('Code-mode tool call cancelled before start.', retryable: false);
            }

            $result = $this->invokeTool($name, $arguments, $parentContext, $cancelToken);
            CodeModeIpc::write($connection, [
                'id' => $id,
                'ok' => true,
                'result' => $this->normalizeResult($result),
            ]);
        } catch (\Throwable $exception) {
            CodeModeIpc::write($connection, [
                'id' => $id,
                'ok' => false,
                'error' => $this->formatException($exception),
            ]);
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
    ): mixed {
        $toolCallId = 'code-mode-'.bin2hex(random_bytes(8));
        $nestedContext = new ToolContext(
            runId: $parentContext?->runId() ?? '',
            turnNo: $parentContext?->turnNo() ?? 0,
            toolCallId: $toolCallId,
            toolName: $name,
            cancellationToken: $cancelToken,
            timeoutSeconds: $parentContext?->timeoutSeconds(),
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

        if (\is_resource($result)) {
            throw new ToolCallException('Tool results containing resources cannot be returned to code_mode scripts.', retryable: false);
        }

        if (\is_object($result)) {
            // Force a JSON round-trip so unsupported objects fail here rather than
            // corrupting the IPC connection with a partial encode later.
            json_encode($result, \JSON_THROW_ON_ERROR);
        }

        return $result;
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
        $root = $this->projectDir.'/var/tmp';
        if (!is_dir($root) && !@mkdir($root, 0o700, true) && !is_dir($root)) {
            throw new ToolCallException(\sprintf('Failed to create code-mode temp root "%s".', $root), retryable: true);
        }

        $dir = $root.'/code-mode-'.bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0o700) && !is_dir($dir)) {
            throw new ToolCallException(\sprintf('Failed to create code-mode workspace "%s".', $dir), retryable: true);
        }

        return [
            'dir' => $dir,
            'script' => $dir.'/script.php',
            'socket' => $dir.'/bridge.sock',
            'startedAtNs' => hrtime(true),
        ];
    }

    private function writeScript(string $path, string $script): void
    {
        $body = "<?php\ndeclare(strict_types=1);\nreturn (static function () {\n".$script."\n})();\n";
        if (false === @file_put_contents($path, $body)) {
            throw new ToolCallException(\sprintf('Failed to write code-mode script "%s".', $path), retryable: true);
        }
    }

    /**
     * @return resource
     */
    private function createSocketServer(string $socketPath): mixed
    {
        if (file_exists($socketPath)) {
            @unlink($socketPath);
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
    private function startProcess(
        array $workspace,
        CancellationTokenInterface $cancelToken,
        ?int $timeoutSeconds,
    ): Process {
        if ($cancelToken->isCancellationRequested()) {
            throw new ToolCallException('Code-mode execution cancelled before start.', retryable: false);
        }

        $phpBinary = \defined('PHP_BINARY') ? (string) \constant('PHP_BINARY') : '';
        if ('' === $phpBinary) {
            throw new ToolCallException('PHP binary is unavailable for code-mode subprocess execution.', retryable: false);
        }

        $bootstrap = __DIR__.'/Resources/bootstrap.php';
        $process = new Process(
            [$phpBinary, $bootstrap],
            $this->projectDir,
            [
                'HATFIELD_CODE_MODE_SOCKET' => $workspace['socket'],
                'HATFIELD_CODE_MODE_SCRIPT' => $workspace['script'],
            ],
        );
        $process->setTimeout(null);
        $process->setIdleTimeout(null);

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
    private function acceptConnection(
        mixed $server,
        Process $process,
        CancellationTokenInterface $cancelToken,
        ?int $timeoutSeconds,
        int $startedAtNs,
    ): mixed {
        while (true) {
            $this->assertStillRunning($process, $cancelToken, $timeoutSeconds, $startedAtNs);

            $connection = @stream_socket_accept($server, 0.0);
            if (false !== $connection) {
                stream_set_blocking($connection, true);

                return $connection;
            }

            usleep(self::POLL_INTERVAL_MICROS);
        }
    }

    /**
     * @param resource $connection
     */
    private function waitForReadable(
        mixed $connection,
        Process $process,
        CancellationTokenInterface $cancelToken,
        ?int $timeoutSeconds,
        int $startedAtNs,
    ): bool {
        $this->assertStillRunning($process, $cancelToken, $timeoutSeconds, $startedAtNs);

        $read = [$connection];
        $write = null;
        $except = null;
        $selected = @stream_select($read, $write, $except, 0, self::POLL_INTERVAL_MICROS);
        if (false === $selected) {
            throw new ToolCallException('Failed while waiting for code-mode IPC data.', retryable: true);
        }

        return $selected > 0;
    }

    private function assertStillRunning(
        Process $process,
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

        if (!$process->isRunning()) {
            $stderr = trim($this->safeErrorOutput($process));
            $message = \sprintf(
                'Code-mode PHP subprocess exited early (exit code %s).',
                null === $process->getExitCode() ? 'unknown' : (string) $process->getExitCode(),
            );
            if ('' !== $stderr) {
                $message .= ' '.$stderr;
            }

            throw new ToolCallException($message, retryable: false);
        }
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
        } catch (ProcessRuntimeException) {
            // Best-effort teardown of the owned child.
        }
    }

    private function closeResource(mixed $resource): void
    {
        if (\is_resource($resource)) {
            @fclose($resource);
        }
    }

    /**
     * @param array{dir: string, script: string, socket: string, startedAtNs: int} $workspace
     */
    private function cleanupWorkspace(array $workspace): void
    {
        @unlink($workspace['script']);
        @unlink($workspace['socket']);
        @rmdir($workspace['dir']);
    }

    private function safeErrorOutput(Process $process): string
    {
        try {
            return $process->getErrorOutput();
        } catch (\Symfony\Component\Process\Exception\LogicException) {
            return '';
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
}
