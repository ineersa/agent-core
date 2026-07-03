<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\FileRewind;

use Ineersa\CodingAgent\Extension\Builtin\FileRewind\GitProcessRunner;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
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
            self::assertSame(124, $result->exitCode);
            self::assertStringContainsString('timed out', $result->stderr);
            self::assertLessThan(5.0, $elapsed, 'Hung git should be terminated near configured timeout');
        } finally {
            putenv('PATH='.$oldPath);
            TestDirectoryIsolation::removeDirectory($binDir);
        }
    }
}
