<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\FileRewind;

use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindLedgerStore;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\RewindProjectIdentity;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
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
        self::assertCount(2, $rows);
        self::assertSame(1, $rows[0]['turn_no']);
        self::assertSame(2, $rows[1]['turn_no']);
    }

    public function testRunTerminatesHungGitInvocationAfterTimeout(): void
    {
        $binDir = \Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation::createOsTempDir('fake-git-bin');
        $gitScript = $binDir.'/git';
        $script = <<<'SH'
#!/bin/sh
if [ "$1" = "__hang__" ]; then
  sleep 10
  exit 0
fi
exec git "$@"
SH;
        file_put_contents($gitScript, $script);
        chmod($gitScript, 0755);
        $oldPath = getenv('PATH') ?: '/usr/bin';
        putenv('PATH='.$binDir.':'.$oldPath);
        try {
            $runner = new GitProcessRunner(1);
            $start = microtime(true);
            $result = $runner->run(['__hang__']);
            $elapsed = microtime(true) - $start;
            self::assertSame(124, $result->exitCode);
            self::assertStringContainsString('timed out', $result->stderr);
            self::assertLessThan(5.0, $elapsed, 'Hung git should be terminated near configured timeout');
        } finally {
            putenv('PATH='.$oldPath);
            \Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation::removeDirectory($binDir);
        }
    }

}
