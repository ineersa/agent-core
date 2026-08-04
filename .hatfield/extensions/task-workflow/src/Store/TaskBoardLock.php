<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Store;

use Ineersa\Hatfield\ExtensionApi\Tool\ToolCancellationTokenInterface;

final class TaskBoardLock
{
    private const int POLL_INTERVAL_MICROS = 50_000;

    public function __construct(
        private readonly string $lockPath,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T                       $callback
     * @param ToolCancellationTokenInterface|null $cancellationToken Cooperative cancel during lock wait
     * @param int|null                            $deadlineNs        Absolute hrtime(true) deadline, or null
     *
     * @return T|array{cancelled?: true, timed_out?: true, timeout_seconds?: int, message: string}
     */
    public function withLock(
        callable $callback,
        ?ToolCancellationTokenInterface $cancellationToken = null,
        ?int $deadlineNs = null,
        ?int $timeoutSeconds = null,
    ): mixed {
        $dir = \dirname($this->lockPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $handle = fopen($this->lockPath, 'c+b');
        if (false === $handle) {
            throw new \RuntimeException('Failed to open task workflow lock: '.$this->lockPath);
        }

        $acquired = false;
        try {
            while (true) {
                if (null !== $cancellationToken && $cancellationToken->isCancellationRequested()) {
                    return [
                        'cancelled' => true,
                        'message' => 'Cancelled while waiting for task board lock.',
                    ];
                }

                if (null !== $deadlineNs && hrtime(true) >= $deadlineNs) {
                    return [
                        'timed_out' => true,
                        'timeout_seconds' => $timeoutSeconds ?? 0,
                        'message' => 'Timed out while waiting for task board lock.',
                    ];
                }

                // Nonblocking acquire so Escape/deadline can interrupt waiters.
                if (flock($handle, \LOCK_EX | \LOCK_NB)) {
                    $acquired = true;
                    break;
                }

                usleep(self::POLL_INTERVAL_MICROS);
            }

            // Re-check after acquire so a cancel that arrives between wait and
            // critical section never mutates board state under a held lock.
            if (null !== $cancellationToken && $cancellationToken->isCancellationRequested()) {
                return [
                    'cancelled' => true,
                    'message' => 'Cancelled after acquiring task board lock.',
                ];
            }
            if (null !== $deadlineNs && hrtime(true) >= $deadlineNs) {
                return [
                    'timed_out' => true,
                    'timeout_seconds' => $timeoutSeconds ?? 0,
                    'message' => 'Timed out after acquiring task board lock.',
                ];
            }

            return $callback();
        } finally {
            if ($acquired) {
                flock($handle, \LOCK_UN);
            }
            fclose($handle);
        }
    }

    public static function lockPathForRoot(string $taskRoot): string
    {
        return rtrim($taskRoot, '/').'/.task-workflow.lock';
    }
}
