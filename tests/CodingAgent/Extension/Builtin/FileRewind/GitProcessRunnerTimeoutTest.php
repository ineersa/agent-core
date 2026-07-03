<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\FileRewind;

use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindConfig;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\GitProcessRunner;
use PHPUnit\Framework\TestCase;

final class GitProcessRunnerTimeoutTest extends TestCase
{
    public function testConfigGitTimeoutSecondsIsAvailableForRunnerConstruction(): void
    {
        $config = FileRewindConfig::fromSettings(['git_timeout_seconds' => 12]);
        self::assertSame(12, $config->gitTimeoutSeconds);
        $runner = new GitProcessRunner($config->gitTimeoutSeconds);
        self::assertTrue($runner->isGitAvailable());
    }

    public function testRunReturnsFailureForInvalidGitInvocation(): void
    {
        $runner = new GitProcessRunner(2);
        $result = $runner->run(['not-a-real-git-subcommand-xyz']);
        self::assertNotSame(0, $result->exitCode);
        self::assertNotSame('', $result->stderr);
    }
}
