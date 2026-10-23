<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Support;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;

final class ParentSessionToolBatchRunStoragePaths implements ToolBatchRunStoragePathsInterface
{
    public function __construct(private readonly HatfieldSessionStore $hatfieldSessionStore)
    {
    }

    public function resolveToolBatchesDirectory(string $runId): string
    {
        return $this->hatfieldSessionStore->resolveSessionsBasePath().'/'.$runId.'/runtime/tool-batches';
    }

    public function withStorageWriteLock(string $directory, callable $write): mixed
    {
        $owner = explode('/', substr($directory, \strlen($this->hatfieldSessionStore->resolveSessionsBasePath()) + 1), 2)[0];

        return $this->hatfieldSessionStore->withStorageWriteLock($owner, $write);
    }
}
