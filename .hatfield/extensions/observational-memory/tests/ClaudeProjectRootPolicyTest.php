<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\HatfieldExt\ObservationalMemory\Claude\ClaudeProjectRootPolicy;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmTestCase;

final class ClaudeProjectRootPolicyTest extends OmTestCase
{
    public function testAllowsOnlyTheCheckoutRoot(): void
    {
        mkdir($this->directory.'/repo/.git', 0o750, true);
        mkdir($this->directory.'/repo/src/nested', 0o750, true);
        // A linked worktree or submodule root has a .git file; it is a root here, and the worktree policy decides separately.
        mkdir($this->directory.'/repo/src/linked');
        file_put_contents($this->directory.'/repo/src/linked/.git', "gitdir: /elsewhere\n");

        $this->assertTrue(ClaudeProjectRootPolicy::allows($this->directory.'/repo'));
        $this->assertFalse(ClaudeProjectRootPolicy::allows($this->directory.'/repo/src'));
        $this->assertFalse(ClaudeProjectRootPolicy::allows($this->directory.'/repo/src/nested'));
        $this->assertTrue(ClaudeProjectRootPolicy::allows($this->directory.'/repo/src/linked'));
    }

    public function testAllowsDirectoriesOutsideGit(): void
    {
        // Project temp directories live inside this repository's checkout.
        $directory = TestDirectoryIsolation::createOsTempDir('om-root-policy');
        try {
            mkdir($directory.'/plain');
            $this->assertTrue(ClaudeProjectRootPolicy::allows($directory.'/plain'));
        } finally {
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }
}
