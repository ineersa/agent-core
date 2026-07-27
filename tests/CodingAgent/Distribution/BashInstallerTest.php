<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Distribution;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Contract: the bash installer verifies exact SHA256SUMS entries, smokes the
 * candidate before replace, never installs on checksum/smoke failure, leaves
 * previous installs untouched, and leaves no temp residue.
 *
 * Uses a test-local HTTP fixture server (PHP built-in) — the same
 * HATFIELD_INSTALLER_BASE_URL seam is legitimate for private mirrors.
 */
final class BashInstallerTest extends TestCase
{
    public function testInstallerSucceedsWithMatchingChecksum(): void
    {
        $root = ProjectDir::get();
        $fixture = TestDirectoryIsolation::createProjectTempDir('installer-ok');
        $installDir = TestDirectoryIsolation::createProjectTempDir('installer-dest');
        $server = null;
        try {
            $asset = 'hatfield.phar';
            $payload = "#!/usr/bin/env php\n<?php\necho \"Hatfield 9.9.9 (commit deadbeef)\\n\";\n";
            file_put_contents($fixture.'/'.$asset, $payload);
            $hash = hash_file('sha256', $fixture.'/'.$asset);
            $this->assertNotFalse($hash);
            file_put_contents($fixture.'/SHA256SUMS', $hash.'  '.$asset."\n");

            $server = $this->startServer($fixture);
            $installer = $root.'/installer/bash-installer';
            $this->assertTrue(is_executable($installer), 'installer/bash-installer must be executable');

            $process = new Process([
                'bash', $installer,
                '--install-dir='.$installDir,
                '--version=v9.9.9',
            ], $root, [
                'HATFIELD_INSTALLER_BASE_URL' => $server['url'],
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            ]);
            $process->setTimeout(30);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput()."\n".$process->getOutput());
            $this->assertFileExists($installDir.'/hatfield');
            $this->assertTrue(is_executable($installDir.'/hatfield'));
            $this->assertNoInstallTemps($installDir);
        } finally {
            $this->stopServer($server);
            TestDirectoryIsolation::removeDirectory($fixture);
            TestDirectoryIsolation::removeDirectory($installDir);
        }
    }

    public function testInstallerFailsClosedOnChecksumMismatch(): void
    {
        $root = ProjectDir::get();
        $fixture = TestDirectoryIsolation::createProjectTempDir('installer-bad');
        $installDir = TestDirectoryIsolation::createProjectTempDir('installer-dest-bad');
        $server = null;
        try {
            // Pre-existing install that must survive.
            $previous = "#!/usr/bin/env php\n<?php\necho \"Hatfield old (commit abc)\\n\";\n";
            file_put_contents($installDir.'/hatfield', $previous);
            chmod($installDir.'/hatfield', 0755);

            $asset = 'hatfield.phar';
            file_put_contents($fixture.'/'.$asset, "#!/usr/bin/env php\n<?php\necho \"bad\";\n");
            // Deliberately wrong checksum.
            file_put_contents(
                $fixture.'/SHA256SUMS',
                str_repeat('a', 64).'  '.$asset."\n",
            );

            $server = $this->startServer($fixture);
            $process = new Process([
                'bash', $root.'/installer/bash-installer',
                '--install-dir='.$installDir,
            ], $root, [
                'HATFIELD_INSTALLER_BASE_URL' => $server['url'],
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            ]);
            $process->setTimeout(30);
            $process->run();
            $this->assertFalse($process->isSuccessful(), 'Installer must fail on checksum mismatch');
            $this->assertStringContainsString('checksum mismatch', $process->getOutput().$process->getErrorOutput());
            $this->assertFileExists($installDir.'/hatfield');
            $this->assertSame($previous, (string) file_get_contents($installDir.'/hatfield'));
            $this->assertNoInstallTemps($installDir);
        } finally {
            $this->stopServer($server);
            TestDirectoryIsolation::removeDirectory($fixture);
            TestDirectoryIsolation::removeDirectory($installDir);
        }
    }

    public function testInstallerFailsClosedOnPostDownloadSmokeFailure(): void
    {
        $root = ProjectDir::get();
        $fixture = TestDirectoryIsolation::createProjectTempDir('installer-smoke-fail');
        $installDir = TestDirectoryIsolation::createProjectTempDir('installer-dest-smoke');
        $server = null;
        try {
            $previous = "#!/usr/bin/env php\n<?php\necho \"Hatfield previous (commit keepme)\\n\";\n";
            file_put_contents($installDir.'/hatfield', $previous);
            chmod($installDir.'/hatfield', 0755);

            $asset = 'hatfield.phar';
            // Valid PHP that does not report Hatfield — candidate smoke must fail.
            $payload = "#!/usr/bin/env php\n<?php\necho \"not-the-product\\n\";\n";
            file_put_contents($fixture.'/'.$asset, $payload);
            $hash = hash_file('sha256', $fixture.'/'.$asset);
            $this->assertNotFalse($hash);
            file_put_contents($fixture.'/SHA256SUMS', $hash.'  '.$asset."\n");

            $server = $this->startServer($fixture);
            $process = new Process([
                'bash', $root.'/installer/bash-installer',
                '--install-dir='.$installDir,
            ], $root, [
                'HATFIELD_INSTALLER_BASE_URL' => $server['url'],
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            ]);
            $process->setTimeout(30);
            $process->run();
            $combined = $process->getOutput().$process->getErrorOutput();
            $this->assertFalse($process->isSuccessful(), 'Installer must fail on candidate smoke failure');
            $this->assertTrue(
                str_contains($combined, 'candidate --version')
                || str_contains($combined, 'did not report Hatfield')
                || str_contains($combined, 'previous install left unchanged'),
                'Expected smoke-failure diagnostic, got: '.$combined,
            );
            $this->assertFileExists($installDir.'/hatfield');
            $this->assertSame($previous, (string) file_get_contents($installDir.'/hatfield'));
            $this->assertNoInstallTemps($installDir);
        } finally {
            $this->stopServer($server);
            TestDirectoryIsolation::removeDirectory($fixture);
            TestDirectoryIsolation::removeDirectory($installDir);
        }
    }

    private function assertNoInstallTemps(string $installDir): void
    {
        $temps = glob($installDir.'/.hatfield-install.*') ?: [];
        $this->assertSame([], $temps, 'Install-dir temporary destinations must be cleaned up');
    }

    /**
     * @return array{url: string, pid: int, docroot: string}
     */
    private function startServer(string $docroot): array
    {
        $port = random_int(18000, 18999);
        $cmd = \sprintf(
            'php -S 127.0.0.1:%d -t %s >/dev/null 2>&1 & echo $!',
            $port,
            escapeshellarg($docroot),
        );
        $pid = (int) trim((string) shell_exec($cmd));
        $this->assertGreaterThan(0, $pid, 'Failed to start fixture HTTP server');
        usleep(200_000);

        return ['url' => 'http://127.0.0.1:'.$port, 'pid' => $pid, 'docroot' => $docroot];
    }

    /**
     * @param array{url: string, pid: int, docroot: string}|null $server
     */
    private function stopServer(?array $server): void
    {
        if (null === $server) {
            return;
        }
        if ($server['pid'] > 0) {
            posix_kill($server['pid'], \SIGTERM);
        }
    }
}
