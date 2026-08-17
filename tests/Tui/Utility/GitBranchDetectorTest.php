<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Utility;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Utility\GitBranchDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(GitBranchDetector::class)]
final class GitBranchDetectorTest extends TestCase
{
    public function testNonRepoDirectoryReturnsEmptyString(): void
    {
        // Must be outside any git repository (OS temp, not project var/tmp).
        $dir = TestDirectoryIsolation::createOsTempDir('git-branch-nonrepo');

        try {
            $previous = getcwd();
            chdir($dir);
            try {
                $this->assertSame('', GitBranchDetector::detect());
            } finally {
                chdir($previous);
            }
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    public function testRepoReturnsCurrentBranch(): void
    {
        $root = TestDirectoryIsolation::createOsTempDir('git-branch-repo');
        $repo = $root.'/repo';

        try {
            mkdir($repo);

            $init = new Process(['git', 'init', '-b', 'testbranch'], $repo);
            $init->run();
            $this->assertTrue($init->isSuccessful(), $init->getErrorOutput());

            file_put_contents($repo.'/file.txt', "x\n");

            $add = new Process(['git', 'add', 'file.txt'], $repo);
            $add->run();
            $this->assertTrue($add->isSuccessful(), $add->getErrorOutput());

            $commit = new Process(
                ['git', '-c', 'user.email=test@example.com', '-c', 'user.name=Test', 'commit', '-m', 'init'],
                $repo,
            );
            $commit->run();
            $this->assertTrue($commit->isSuccessful(), $commit->getErrorOutput());

            $previous = getcwd();
            chdir($repo);
            try {
                $this->assertSame('testbranch', GitBranchDetector::detect());
            } finally {
                chdir($previous);
            }
        } finally {
            TestDirectoryIsolation::removeDirectory($root);
        }
    }
}
