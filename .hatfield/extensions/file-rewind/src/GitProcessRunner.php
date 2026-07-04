<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\FileRewind;

/**
 * Runs git with explicit GIT_DIR / GIT_WORK_TREE / optional GIT_INDEX_FILE.
 */
final class GitProcessRunner
{
    private const int POLL_MICROSECONDS = 50_000;

    public function __construct(
        private readonly int $timeoutSeconds = FileRewindConfig::DEFAULT_GIT_TIMEOUT_SECONDS,
    ) {
    }

    /**
     * @param list<string>          $args git arguments after "git"
     * @param array<string, string> $env  extra env vars
     */
    public function run(array $args, array $env = []): GitProcessResult
    {
        $cmd = array_merge(['git'], $args);
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $baseEnv = \is_array(getenv()) ? getenv() : [];
        $safeKeys = ['PATH', 'HOME', 'LANG', 'LC_ALL', 'LC_CTYPE', 'TMPDIR', 'USER', 'LOGNAME', 'SHELL'];
        $procEnv = [];
        foreach ($safeKeys as $key) {
            $v = $baseEnv[$key] ?? null;
            if (\is_string($v) && '' !== $v) {
                $procEnv[$key] = $v;
            }
        }
        foreach ($env as $k => $v) {
            if (!\is_string($k) || !\is_string($v)) {
                continue;
            }
            if (str_starts_with($k, 'GIT_')) {
                $procEnv[$k] = $v;
            }
        }

        $process = proc_open($cmd, $descriptor, $pipes, null, $procEnv);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Failed to start git process.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + max(1, $this->timeoutSeconds);

        while (true) {
            $stdout .= $this->readAvailable($pipes[1]);
            $stderr .= $this->readAvailable($pipes[2]);

            $status = proc_get_status($process);
            if (!$status['running']) {
                $stdout .= $this->drainPipe($pipes[1]);
                $stderr .= $this->drainPipe($pipes[2]);
                $exit = $status['exitcode'];

                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return new GitProcessResult($exit, $stdout, $stderr);
            }

            if (microtime(true) >= $deadline) {
                $this->terminateProcess($process);
                $stdout .= $this->drainPipe($pipes[1]);
                $stderr .= $this->drainPipe($pipes[2]);
                if ('' !== $stderr && !str_ends_with($stderr, "\n")) {
                    $stderr .= "\n";
                }
                $stderr .= \sprintf('git process timed out after %d seconds', $this->timeoutSeconds);

                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return new GitProcessResult(124, $stdout, $stderr);
            }

            usleep(self::POLL_MICROSECONDS);
        }
    }

    public function isGitAvailable(): bool
    {
        $r = $this->run(['--version']);

        return 0 === $r->exitCode;
    }

    private function readAvailable(mixed $pipe): string
    {
        if (!\is_resource($pipe)) {
            return '';
        }
        $chunk = stream_get_contents($pipe);
        if (false === $chunk || '' === $chunk) {
            return '';
        }

        return $chunk;
    }

    private function drainPipe(mixed $pipe): string
    {
        if (!\is_resource($pipe)) {
            return '';
        }
        $out = '';
        while (true) {
            $chunk = fread($pipe, 8192);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $out .= $chunk;
        }

        return $out;
    }

    /**
     * @param resource $process
     */
    private function terminateProcess($process): void
    {
        $status = proc_get_status($process);
        if (!$status['running']) {
            return;
        }
        $pid = $status['pid'];
        if ($pid > 0 && \function_exists('posix_kill')) {
            posix_kill($pid, \SIGTERM);
            usleep(100_000);
            $status = proc_get_status($process);
            if ($status['running']) {
                posix_kill($pid, \SIGKILL);
            }
        }
        proc_terminate($process, 9);
    }
}

final readonly class GitProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {
    }

    public function stdoutTrimmed(): string
    {
        return trim($this->stdout);
    }
}
