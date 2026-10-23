<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;

/** Counts resolutions while retaining the real resolver and its database reads. */
final class CountingToolBatchRunStoragePaths implements ToolBatchRunStoragePathsInterface
{
    public int $calls = 0;

    public function __construct(private readonly ToolBatchRunStoragePathsInterface $paths)
    {
    }

    public function resolveToolBatchesDirectory(string $runId): string
    {
        ++$this->calls;

        return $this->paths->resolveToolBatchesDirectory($runId);
    }

    public function withStorageWriteLock(string $directory, callable $write): mixed
    {
        return $this->paths->withStorageWriteLock($directory, $write);
    }
}
