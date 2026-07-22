<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\FileRewind\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\HatfieldExt\FileRewind\FileRewindLedgerStore;
use Ineersa\HatfieldExt\FileRewind\RewindProjectIdentity;
use PHPUnit\Framework\TestCase;

final class FileRewindLedgerStoreLockTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('file-rewind-ledger-lock');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    public function testSequentialAppendCheckpointPersistsToLedger(): void
    {
        $identity = RewindProjectIdentity::fromProjectRoot($this->projectDir);
        $store = new FileRewindLedgerStore($this->projectDir);
        $store->appendCheckpoint($identity, ['run_id' => 'r1', 'turn_no' => 1]);
        $store->appendCheckpoint($identity, ['run_id' => 'r1', 'turn_no' => 2]);
        $rows = $store->readCheckpoints($identity);
        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows[0]['turn_no']);
        $this->assertSame(2, $rows[1]['turn_no']);
    }
}
