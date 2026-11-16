<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\Support;

use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Independent owned holder: stdout proves acquisition, stdin releases it.
 * A real external holder proves kernel lock ownership and visible holder PID/fds;
 * an in-process double cannot establish that cross-process diagnostic contract.
 * The five-second process timeout is only a safety cap, never synchronization;
 * tests advance an injected clock to expire the edit and release this holder via stdin.
 */
final class InodeLockHolder
{
    public readonly int $pid;
    private Process $process;
    private InputStream $input;

    public function __construct(string $path)
    {
        $this->input = new InputStream();
        $script = <<<'PHP'
$stream = fopen($argv[1], 'r+b');
if (false === $stream || !flock($stream, LOCK_EX | LOCK_NB)) { exit(2); }
fwrite(STDOUT, "LOCKED\n");
fflush(STDOUT);
fgets(STDIN);
flock($stream, LOCK_UN);
fclose($stream);
PHP;
        $this->process = new Process([\PHP_BINARY, '-r', $script, $path], \dirname($path), ['HATFIELD_SESSION_ID' => false], $this->input, 5);
        $this->process->start();
        $pid = $this->process->getPid();
        if (null === $pid) {
            throw new \RuntimeException('Lock-holder process did not start.');
        }
        $this->pid = $pid;
        try {
            if (!$this->process->waitUntil(fn (): bool => str_contains($this->process->getOutput(), "LOCKED\n"))) {
                throw new \RuntimeException('Lock holder exited before acquisition: '.$this->process->getErrorOutput());
            }
        } catch (\Throwable $e) {
            $this->input->close();
            $this->process->wait();
            throw $e;
        }
    }

    public function release(): void
    {
        $this->input->close();
        if (0 !== $this->process->wait()) {
            throw new \RuntimeException('Owned lock holder failed: '.$this->process->getErrorOutput());
        }
    }
}
