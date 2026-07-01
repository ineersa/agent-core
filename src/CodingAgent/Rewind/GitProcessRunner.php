<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Rewind;

/**
 * Runs git with explicit GIT_DIR / GIT_WORK_TREE / optional GIT_INDEX_FILE.
 */
final class GitProcessRunner
{
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

        $baseEnv = getenv();
        $mergedEnv = array_merge(\is_array($baseEnv) ? $baseEnv : [], $env);
        $procEnv = [];
        foreach ($mergedEnv as $k => $v) {
            if (!\is_string($k)) {
                continue;
            }
            if (\is_string($v)) {
                $procEnv[$k] = $v;
            } elseif (\is_int($v) || \is_float($v)) {
                $procEnv[$k] = (string) $v;
            }
        }

        $process = proc_open($cmd, $descriptor, $pipes, null, $procEnv);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Failed to start git process.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        if (false === $stdout) {
            $stdout = '';
        }
        if (false === $stderr) {
            $stderr = '';
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return new GitProcessResult($exit, $stdout, $stderr);
    }

    public function isGitAvailable(): bool
    {
        $r = $this->run(['--version']);

        return 0 === $r->exitCode;
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
