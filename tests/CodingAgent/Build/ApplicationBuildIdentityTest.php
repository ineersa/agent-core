<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Build;

use Ineersa\CodingAgent\Build\ApplicationBuildIdentity;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Contract: source, env, and generated identity files resolve a stable
 * display version with commit identity for hatfield --version.
 */
final class ApplicationBuildIdentityTest extends TestCase
{
    public function testSourceModeReportsDevVersionWithCommit(): void
    {
        $identity = ApplicationBuildIdentity::resolve(ProjectDir::get());
        $this->assertSame('dev', $identity->version);
        $this->assertNotSame('', $identity->commit);
        $this->assertStringContainsString('dev (commit ', $identity->displayVersion());
    }

    public function testGeneratedIdentityFileWins(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('build-identity');
        try {
            $path = $dir.'/'.ApplicationBuildIdentity::GENERATED_RELATIVE_PATH;
            TestDirectoryIsolation::ensureDirectory(\dirname($path));
            file_put_contents(
                $path,
                ApplicationBuildIdentity::generatePhpSource('1.2.3', 'abcdef123456'),
            );

            $identity = ApplicationBuildIdentity::resolve($dir);
            $this->assertSame('1.2.3', $identity->version);
            $this->assertSame('abcdef123456', $identity->commit);
            $this->assertSame('1.2.3 (commit abcdef123456)', $identity->displayVersion());
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }
}
