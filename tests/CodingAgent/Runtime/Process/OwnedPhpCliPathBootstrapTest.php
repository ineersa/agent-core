<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Process;

use Ineersa\CodingAgent\Runtime\Process\OwnedPhpCliPathBootstrap;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Contract: fused natives that advertise PHP_BINARY=hatfield-php prepend a
 * secure adjacent helper directory to PATH. Missing helpers fail closed.
 * Host/build PHP is never used as fallback.
 */
final class OwnedPhpCliPathBootstrapTest extends TestCase
{
    private string $originalPath = '';

    protected function setUp(): void
    {
        $path = getenv('PATH');
        $this->originalPath = false === $path ? '' : $path;
    }

    protected function tearDown(): void
    {
        if ('' === $this->originalPath) {
            putenv('PATH');
            unset($_ENV['PATH'], $_SERVER['PATH']);
        } else {
            putenv('PATH='.$this->originalPath);
            $_ENV['PATH'] = $this->originalPath;
            $_SERVER['PATH'] = $this->originalPath;
        }
        parent::tearDown();
    }

    public function testEnsureForNoopsWhenSelfEmpty(): void
    {
        putenv('PATH=/usr/bin:/bin');
        $_ENV['PATH'] = '/usr/bin:/bin';
        $_SERVER['PATH'] = '/usr/bin:/bin';
        $this->assertFalse(OwnedPhpCliPathBootstrap::ensureFor('', OwnedPhpCliPathBootstrap::CLI_BASENAME));
        $this->assertSame('/usr/bin:/bin', getenv('PATH'));
    }

    public function testEnsureForNoopsWhenPhpBinaryIsNotOwnedBasename(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('owned-php-noop');
        try {
            $fused = $dir.'/hatfield.linux-amd64';
            file_put_contents($fused, "#!/bin/sh\nexit 0\n");
            chmod($fused, 0755);
            putenv('PATH=/usr/bin:/bin');
            $_ENV['PATH'] = '/usr/bin:/bin';
            $_SERVER['PATH'] = '/usr/bin:/bin';
            $this->assertFalse(OwnedPhpCliPathBootstrap::ensureFor($fused, \PHP_BINARY));
            $this->assertSame('/usr/bin:/bin', getenv('PATH'));
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    public function testEnsureForPrependsAdjacentHatfieldPhp(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('owned-php-cli');
        try {
            $fused = $dir.'/hatfield.linux-amd64';
            $helper = $dir.'/hatfield-php';
            file_put_contents($fused, "#!/bin/sh\nexit 0\n");
            file_put_contents($helper, "#!/bin/sh\necho owned\n");
            chmod($fused, 0755);
            chmod($helper, 0755);

            putenv('PATH=/usr/bin:/bin');
            $_ENV['PATH'] = '/usr/bin:/bin';
            $_SERVER['PATH'] = '/usr/bin:/bin';
            $this->assertTrue(OwnedPhpCliPathBootstrap::ensureFor($fused, OwnedPhpCliPathBootstrap::CLI_BASENAME));
            $this->assertSame($dir.\PATH_SEPARATOR.'/usr/bin'.\PATH_SEPARATOR.'/bin', getenv('PATH'));
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    public function testEnsureForStagesCompanionPhpBasename(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('owned-php-companion');
        try {
            $fused = $dir.'/hatfield.linux-amd64';
            $companion = $dir.'/hatfield.linux-amd64.php';
            file_put_contents($fused, "#!/bin/sh\nexit 0\n");
            file_put_contents($companion, "#!/bin/sh\necho companion\n");
            chmod($fused, 0755);
            chmod($companion, 0755);

            putenv('PATH=/usr/bin:/bin');
            $_ENV['PATH'] = '/usr/bin:/bin';
            $_SERVER['PATH'] = '/usr/bin:/bin';
            $this->assertTrue(OwnedPhpCliPathBootstrap::ensureFor($fused, OwnedPhpCliPathBootstrap::CLI_BASENAME));
            $path = (string) getenv('PATH');
            $first = explode(\PATH_SEPARATOR, $path)[0];
            $this->assertDirectoryExists($first);
            $staged = $first.'/'.OwnedPhpCliPathBootstrap::CLI_BASENAME;
            $this->assertFileExists($staged);
            $this->assertSame(realpath($companion), realpath($staged));
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    public function testEnsureForFailsClosedWhenHelperMissing(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('owned-php-missing');
        try {
            $fused = $dir.'/hatfield.linux-amd64';
            file_put_contents($fused, "#!/bin/sh\nexit 0\n");
            chmod($fused, 0755);

            $process = new Process([
                \PHP_BINARY,
                '-r',
                'require $argv[1]; Ineersa\\CodingAgent\\Runtime\\Process\\OwnedPhpCliPathBootstrap::ensureFor($argv[2], "hatfield-php");',
                \dirname(__DIR__, 4).'/vendor/autoload.php',
                $fused,
            ]);
            $process->setTimeout(5);
            $process->run();
            $this->assertFalse($process->isSuccessful());
            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('requires adjacent owned PHP CLI', $process->getErrorOutput());
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }
}
