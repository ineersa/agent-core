<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Store;

final class TaskBoardLock
{
    public function __construct(
        private readonly string $lockPath,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function withLock(callable $callback): mixed
    {
        $dir = \dirname($this->lockPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $handle = fopen($this->lockPath, 'c+b');
        if (false === $handle) {
            throw new \RuntimeException('Failed to open task workflow lock: '.$this->lockPath);
        }

        try {
            if (!flock($handle, \LOCK_EX)) {
                throw new \RuntimeException('Failed to acquire task workflow lock: '.$this->lockPath);
            }

            return $callback();
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    public static function lockPathForRoot(string $taskRoot): string
    {
        return rtrim($taskRoot, '/').'/.task-workflow.lock';
    }
}
