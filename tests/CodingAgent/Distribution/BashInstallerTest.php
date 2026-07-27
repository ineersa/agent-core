<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Distribution;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Contract: the bash installer verifies exact SHA256SUMS entries and never
 * installs on checksum mismatch; success path installs an executable hatfield.
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
            $this->assertFileDoesNotExist($installDir.'/hatfield');
        } finally {
            $this->stopServer($server);
            TestDirectoryIsolation::removeDirectory($fixture);
            TestDirectoryIsolation::removeDirectory($installDir);
        }
    }

    /**
     * @return array{url: string, pid: int, docroot: string}|null
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
        // Wait briefly for bind.
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
