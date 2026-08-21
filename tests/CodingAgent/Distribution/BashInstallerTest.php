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

    public function testInstallerRejectsEmptyVersionEqualsForm(): void
    {
        $root = ProjectDir::get();
        $process = new Process(
            ['bash', $root.'/installer/bash-installer', '--version='],
            $root,
            ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
        );
        $process->setTimeout(10);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $combined = $process->getOutput().$process->getErrorOutput();
        $this->assertStringContainsString('requires a non-empty value', $combined);
    }

    public function testInstallerRejectsPathTraversalVersionBeforeDownload(): void
    {
        $root = ProjectDir::get();
        $process = new Process(
            ['bash', $root.'/installer/bash-installer', '--version=../../x'],
            $root,
            [
                // No fixture server: path traversal must fail at validation, before network.
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            ],
        );
        $process->setTimeout(10);
        $process->run();
        $combined = $process->getOutput().$process->getErrorOutput();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('invalid --version', $combined);
        $this->assertStringNotContainsString('Download', $combined);
        $this->assertStringNotContainsString('cURL is installed', $combined);
        $this->assertStringNotContainsString('wget is installed', $combined);
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

    public function testInstallerVersionSmokesUseDisposableCacheLogHomeAndCwd(): void
    {
        $root = ProjectDir::get();
        $fixture = TestDirectoryIsolation::createProjectTempDir('installer-smoke-iso');
        $installDir = TestDirectoryIsolation::createProjectTempDir('installer-dest-iso');
        $tmpdir = TestDirectoryIsolation::createProjectTempDir('installer-tmpdir');
        $callerProject = TestDirectoryIsolation::createProjectTempDir('installer-caller-project');
        $persistentCache = TestDirectoryIsolation::createProjectTempDir('installer-persistent-cache');
        $proofLog = $fixture.'/smoke-proof.jsonl';
        $server = null;

        try {
            TestDirectoryIsolation::createHatfieldTree($callerProject);
            $callerCacheBefore = $this->listRelativeFiles($callerProject.'/.hatfield');
            $persistentBefore = $this->listRelativeFiles($persistentCache);

            $asset = 'hatfield.phar';
            // Fixture records smoke env for both candidate and install-dir smokes.
            // It is not a real Hatfield PHAR — only --version is exercised.
            $payload = <<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);
$log = getenv('HATFIELD_SMOKE_PROOF_LOG');
if (is_string($log) && '' !== $log) {
    $row = [
        'HATFIELD_CACHE_DIR' => getenv('HATFIELD_CACHE_DIR') ?: '',
        'HATFIELD_LOG_DIR' => getenv('HATFIELD_LOG_DIR') ?: '',
        'HOME' => getenv('HOME') ?: '',
        'CWD' => getcwd() ?: '',
    ];
    file_put_contents($log, json_encode($row, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
    foreach (['HATFIELD_CACHE_DIR', 'HATFIELD_LOG_DIR'] as $key) {
        $dir = getenv($key);
        if (is_string($dir) && '' !== $dir && is_dir($dir)) {
            file_put_contents(rtrim($dir, '/').'/.smoke-marker', '1');
        }
    }
}
echo "Hatfield 9.9.9 (commit deadbeef)\n";
PHP;
            file_put_contents($fixture.'/'.$asset, $payload);
            $hash = hash_file('sha256', $fixture.'/'.$asset);
            $this->assertNotFalse($hash);
            file_put_contents($fixture.'/SHA256SUMS', $hash.'  '.$asset."\n");

            $server = $this->startServer($fixture);
            $process = new Process([
                'bash', $root.'/installer/bash-installer',
                '--install-dir='.$installDir,
                '--version=v9.9.9',
            ], $callerProject, [
                'HATFIELD_INSTALLER_BASE_URL' => $server['url'],
                'HATFIELD_SMOKE_PROOF_LOG' => $proofLog,
                'TMPDIR' => $tmpdir,
                'XDG_CACHE_HOME' => $persistentCache,
                'HOME' => $callerProject.'/home-should-not-be-used-for-smoke',
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            ]);
            $process->setTimeout(30);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput()."\n".$process->getOutput());
            $this->assertFileExists($installDir.'/hatfield');
            $this->assertNoInstallTemps($installDir);

            $this->assertFileExists($proofLog);
            $lines = array_values(array_filter(explode("\n", trim((string) file_get_contents($proofLog)))));
            $this->assertCount(2, $lines, 'Both candidate and install-dir smokes must record env');

            $tmpdirReal = realpath($tmpdir);
            $this->assertNotFalse($tmpdirReal);
            foreach ($lines as $line) {
                $row = json_decode($line, true);
                $this->assertIsArray($row);
                foreach (['HATFIELD_CACHE_DIR', 'HATFIELD_LOG_DIR', 'HOME', 'CWD'] as $key) {
                    $this->assertArrayHasKey($key, $row);
                    $this->assertNotSame('', $row[$key], $key.' must be set for smoke');
                    $this->assertStringStartsWith(
                        $tmpdirReal,
                        (string) realpath($row[$key]) ?: $row[$key],
                        $key.' must live under installer TMPDIR tree during smoke: '.$row[$key],
                    );
                }
                // After successful install the trap removes TMPDIR_INSTALL; smoke roots are gone.
                $this->assertDirectoryDoesNotExist($row['HATFIELD_CACHE_DIR']);
                $this->assertDirectoryDoesNotExist($row['HATFIELD_LOG_DIR']);
                $this->assertDirectoryDoesNotExist($row['HOME']);
                $this->assertDirectoryDoesNotExist($row['CWD']);
            }

            // Caller project .hatfield and persistent XDG cache must be untouched.
            $this->assertSame($callerCacheBefore, $this->listRelativeFiles($callerProject.'/.hatfield'));
            $this->assertSame($persistentBefore, $this->listRelativeFiles($persistentCache));
            $this->assertDirectoryDoesNotExist($callerProject.'/.hatfield/cache');
        } finally {
            $this->stopServer($server);
            TestDirectoryIsolation::removeDirectory($fixture);
            TestDirectoryIsolation::removeDirectory($installDir);
            TestDirectoryIsolation::removeDirectory($tmpdir);
            TestDirectoryIsolation::removeDirectory($callerProject);
            TestDirectoryIsolation::removeDirectory($persistentCache);
        }
    }

    private function assertNoInstallTemps(string $installDir): void
    {
        $temps = glob($installDir.'/.hatfield-install.*') ?: [];
        $this->assertSame([], $temps, 'Install-dir temporary destinations must be cleaned up');
    }

    /**
     * @return list<string>
     */
    private function listRelativeFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = substr($file->getPathname(), \strlen($dir) + 1);
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return array{url: string, pid: int, docroot: string}
     */
    private function startServer(string $docroot): array
    {
        $port = random_int(18000, 18999);
        // Absolute lifetime ≤210s so a leaked fixture server cannot outlive the test runner.
        // PID ownership/cleanup stays with stopServer(); readiness is polled separately.
        $cmd = \sprintf(
            'timeout --kill-after=5s 210s php -S 127.0.0.1:%d -t %s >/dev/null 2>&1 & echo $!',
            $port,
            escapeshellarg($docroot),
        );
        $pid = (int) trim((string) shell_exec($cmd));
        $this->assertGreaterThan(0, $pid, 'Failed to start fixture HTTP server');

        $url = 'http://127.0.0.1:'.$port;
        $readyUrl = $url.'/SHA256SUMS';
        $deadline = microtime(true) + 5.0;
        $ready = false;
        while (microtime(true) < $deadline) {
            $body = @file_get_contents($readyUrl);
            if (false !== $body && '' !== $body) {
                $ready = true;
                break;
            }
            usleep(20_000);
        }
        $this->assertTrue($ready, 'Fixture HTTP server did not become ready at '.$readyUrl);

        return ['url' => $url, 'pid' => $pid, 'docroot' => $docroot];
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
