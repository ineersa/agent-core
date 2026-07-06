<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\FileRewind\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\HatfieldExt\FileRewind\GitProcessRunner;
use PHPUnit\Framework\TestCase;

final class GitProcessRunnerTimeoutTest extends TestCase
{
    public function testRunTerminatesHungGitInvocationAfterTimeout(): void
    {
        $binDir = TestDirectoryIsolation::createOsTempDir('fake-git-bin');
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
            $this->assertSame(124, $result->exitCode);
            $this->assertStringContainsString('timed out', $result->stderr);
            $this->assertLessThan(5.0, $elapsed, 'Hung git should be terminated near configured timeout');
        } finally {
            putenv('PATH='.$oldPath);
            TestDirectoryIsolation::removeDirectory($binDir);
        }
    }
}
