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

    public function testStaticInstallerInstallsMatchedOwnedPhpCliPair(): void
    {
        $root = ProjectDir::get();
        $fixture = TestDirectoryIsolation::createProjectTempDir('installer-static-ok');
        $installDir = TestDirectoryIsolation::createProjectTempDir('installer-static-dest');
        $callerCwd = TestDirectoryIsolation::createProjectTempDir('installer-static-cwd');
        $server = null;
        try {
            $platform = $this->installerPlatform();
            $asset = 'hatfield.'.$platform;
            $cliAsset = $asset.'.php';
            $appPayload = "#!/bin/sh\necho \"Hatfield 9.9.9 (commit deadbeef)\"\n";
            $cliPayload = <<<'SH'
#!/bin/sh
if [ "$1" = "-r" ]; then
  eval "$2"
  exit 0
fi
echo "unexpected cli invocation" >&2
exit 1
SH;
            file_put_contents($fixture.'/'.$asset, $appPayload);
            file_put_contents($fixture.'/'.$cliAsset, $cliPayload);
            chmod($fixture.'/'.$asset, 0755);
            chmod($fixture.'/'.$cliAsset, 0755);
            $appHash = hash_file('sha256', $fixture.'/'.$asset);
            $cliHash = hash_file('sha256', $fixture.'/'.$cliAsset);
            $this->assertNotFalse($appHash);
            $this->assertNotFalse($cliHash);
            file_put_contents(
                $fixture.'/SHA256SUMS',
                $appHash.'  '.$asset."\n".$cliHash.'  '.$cliAsset."\n",
            );

            $server = $this->startServer($fixture);
            $process = new Process([
                'bash', $root.'/installer/bash-installer',
                '--install-dir='.$installDir,
                '--version=v9.9.9',
                '--static',
            ], $root, [
                'HATFIELD_INSTALLER_BASE_URL' => $server['url'],
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            ]);
            $process->setTimeout(30);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput()."\n".$process->getOutput());
            $this->assertFileExists($installDir.'/hatfield');
            $this->assertTrue(is_link($installDir.'/hatfield'));
            $runtimeApp = realpath($installDir.'/hatfield');
            $this->assertNotFalse($runtimeApp);
            $runtimeDir = \dirname($runtimeApp);
            $this->assertSame($appPayload, (string) file_get_contents($runtimeApp));
            $this->assertFileExists($runtimeDir.'/hatfield-php');
            $this->assertTrue(is_executable($installDir.'/hatfield'));
            $this->assertTrue(is_executable($runtimeDir.'/hatfield-php'));
            $this->assertSame($cliPayload, (string) file_get_contents($runtimeDir.'/hatfield-php'));
            $this->assertFileDoesNotExist($installDir.'/hatfield.'.$platform.'.php');
            $this->assertFileDoesNotExist($installDir.'/hatfield-php');
            $this->assertNoInstallTemps($installDir);

            // Entry symlink works from an unrelated CWD without install_dir on PATH.
            $fromCwd = new Process([$installDir.'/hatfield', '--version'], $callerCwd, [
                'PATH' => '/usr/bin:/bin',
            ]);
            $fromCwd->setTimeout(5);
            $fromCwd->mustRun();
            $this->assertStringContainsString('Hatfield 9.9.9', $fromCwd->getOutput());
        } finally {
            $this->stopServer($server);
            TestDirectoryIsolation::removeDirectory($fixture);
            TestDirectoryIsolation::removeDirectory($installDir);
            TestDirectoryIsolation::removeDirectory($callerCwd);
        }
    }

    public function testStaticInstallerPreservesPreviousPairOnCliChecksumMismatch(): void
    {
        $root = ProjectDir::get();
        $fixture = TestDirectoryIsolation::createProjectTempDir('installer-static-bad-cli');
        $installDir = TestDirectoryIsolation::createProjectTempDir('installer-static-dest-bad');
        $server = null;
        try {
            $previous = $this->seedStaticInstall($installDir, 'previous-app', 'previous-cli');

            $platform = $this->installerPlatform();
            $asset = 'hatfield.'.$platform;
            $cliAsset = $asset.'.php';
            file_put_contents($fixture.'/'.$asset, "#!/bin/sh\necho \"Hatfield new\"\n");
            file_put_contents($fixture.'/'.$cliAsset, "#!/bin/sh\necho bad-cli\n");
            $appHash = hash_file('sha256', $fixture.'/'.$asset);
            $this->assertNotFalse($appHash);
            file_put_contents(
                $fixture.'/SHA256SUMS',
                $appHash.'  '.$asset."\n".str_repeat('b', 64).'  '.$cliAsset."\n",
            );

            $server = $this->startServer($fixture);
            $process = new Process([
                'bash', $root.'/installer/bash-installer',
                '--install-dir='.$installDir,
                '--static',
            ], $root, [
                'HATFIELD_INSTALLER_BASE_URL' => $server['url'],
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            ]);
            $process->setTimeout(30);
            $process->run();
            $combined = $process->getOutput().$process->getErrorOutput();
            $this->assertFalse($process->isSuccessful());
            $this->assertStringContainsString('checksum mismatch', $combined);
            $this->assertStaticInstallUnchanged($installDir, $previous);
            $this->assertNoInstallTemps($installDir);
        } finally {
            $this->stopServer($server);
            TestDirectoryIsolation::removeDirectory($fixture);
            TestDirectoryIsolation::removeDirectory($installDir);
        }
    }

    public function testStaticInstallerPreservesPreviousPairOnForcedActivationFailure(): void
    {
        $root = ProjectDir::get();
        $fixture = TestDirectoryIsolation::createProjectTempDir('installer-static-fail-activation');
        $installDir = TestDirectoryIsolation::createProjectTempDir('installer-static-dest-fail-activation');
        $binDir = TestDirectoryIsolation::createProjectTempDir('installer-static-fail-activation-bin');
        $server = null;
        try {
            $previous = $this->seedStaticInstall($installDir, 'keep-app', 'keep-cli');

            $platform = $this->installerPlatform();
            $asset = 'hatfield.'.$platform;
            $cliAsset = $asset.'.php';
            $appPayload = "#!/bin/sh\necho \"Hatfield 9.9.9 (commit newbeef)\"\n";
            $cliPayload = <<<'SH'
#!/bin/sh
if [ "$1" = "-r" ]; then
  eval "$2"
  exit 0
fi
echo "unexpected cli invocation" >&2
exit 1
SH;
            file_put_contents($fixture.'/'.$asset, $appPayload);
            file_put_contents($fixture.'/'.$cliAsset, $cliPayload);
            chmod($fixture.'/'.$asset, 0755);
            chmod($fixture.'/'.$cliAsset, 0755);
            $appHash = hash_file('sha256', $fixture.'/'.$asset);
            $cliHash = hash_file('sha256', $fixture.'/'.$cliAsset);
            $this->assertNotFalse($appHash);
            $this->assertNotFalse($cliHash);
            file_put_contents(
                $fixture.'/SHA256SUMS',
                $appHash.'  '.$asset."\n".$cliHash.'  '.$cliAsset."\n",
            );

            // Test-local process injection: fail only the final activation rename.
            // No production env seam; other installer mv operations still use /bin/mv.
            $fakeMv = $binDir.'/mv';
            file_put_contents($fakeMv, <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
if [[ "${#}" -ge 2 ]]; then
  src="${*: -2:1}"
  dest="${*: -1}"
  if [[ "${src}" == *'/.hatfield-link.'* && "$(basename "${dest}")" == 'hatfield' ]]; then
    echo "mv: simulated activation failure" >&2
    exit 1
  fi
fi
exec /bin/mv "$@"
BASH);
            chmod($fakeMv, 0755);

            $server = $this->startServer($fixture);
            $process = new Process([
                'bash', $root.'/installer/bash-installer',
                '--install-dir='.$installDir,
                '--static',
            ], $root, [
                'HATFIELD_INSTALLER_BASE_URL' => $server['url'],
                'PATH' => $binDir.':'.(getenv('PATH') ?: '/usr/bin:/bin'),
            ]);
            $process->setTimeout(30);
            $process->run();
            $combined = $process->getOutput().$process->getErrorOutput();
            $this->assertFalse($process->isSuccessful());
            $this->assertStringContainsString('simulated activation failure', $combined);
            $this->assertStaticInstallUnchanged($installDir, $previous);
            $this->assertNoInstallTemps($installDir);
        } finally {
            $this->stopServer($server);
            TestDirectoryIsolation::removeDirectory($fixture);
            TestDirectoryIsolation::removeDirectory($installDir);
            TestDirectoryIsolation::removeDirectory($binDir);
        }
    }

    private function assertNoInstallTemps(string $installDir): void
    {
        $temps = glob($installDir.'/.hatfield-install.*') ?: [];
        $linkTemps = glob($installDir.'/.hatfield-link.*') ?: [];
        $this->assertSame([], $temps, 'Install-dir temporary destinations must be cleaned up');
        $this->assertSame([], $linkTemps, 'Install-dir link temporary destinations must be cleaned up');
    }

    /**
     * @return array{entry: string, runtime_dir: string, app: string, cli: string}
     */
    private function seedStaticInstall(string $installDir, string $appMarker, string $cliMarker): array
    {
        $runtimeDir = $installDir.'/.hatfield-runtime-seed';
        mkdir($runtimeDir, 0755, true);
        $appPayload = "#!/bin/sh\necho \"Hatfield {$appMarker}\"\n";
        $cliPayload = "#!/bin/sh\necho {$cliMarker}\n";
        file_put_contents($runtimeDir.'/hatfield', $appPayload);
        file_put_contents($runtimeDir.'/hatfield-php', $cliPayload);
        chmod($runtimeDir.'/hatfield', 0755);
        chmod($runtimeDir.'/hatfield-php', 0755);
        $entry = $installDir.'/hatfield';
        if (is_link($entry) || file_exists($entry)) {
            unlink($entry);
        }
        $this->assertTrue(symlink($runtimeDir.'/hatfield', $entry));

        return [
            'entry' => $entry,
            'runtime_dir' => $runtimeDir,
            'app' => $appPayload,
            'cli' => $cliPayload,
        ];
    }

    /**
     * @param array{entry: string, runtime_dir: string, app: string, cli: string} $previous
     */
    private function assertStaticInstallUnchanged(string $installDir, array $previous): void
    {
        $this->assertTrue(is_link($previous['entry']));
        $this->assertSame(
            realpath($previous['runtime_dir'].'/hatfield'),
            realpath($previous['entry']),
        );
        $this->assertSame($previous['app'], (string) file_get_contents($previous['runtime_dir'].'/hatfield'));
        $this->assertSame($previous['cli'], (string) file_get_contents($previous['runtime_dir'].'/hatfield-php'));
        $this->assertDirectoryExists($previous['runtime_dir']);
        $this->assertFileDoesNotExist($installDir.'/hatfield-php');
    }

    private function installerPlatform(): string
    {
        $kernel = php_uname('s');
        if (str_starts_with($kernel, 'Linux')) {
            $kernel = 'linux';
        } elseif (str_starts_with($kernel, 'Darwin')) {
            $kernel = 'darwin';
        } else {
            $this->fail('Unsupported installer platform: '.$kernel);
        }
        $machine = php_uname('m');
        if (str_starts_with($machine, 'aarch64') || str_starts_with($machine, 'arm64')) {
            $machine = 'arm64';
        } elseif ('x86_64' === $machine || 'amd64' === $machine) {
            $machine = 'amd64';
        } else {
            $this->fail('Unsupported installer architecture: '.$machine);
        }

        return $kernel.'-'.$machine;
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
     * @return array{url: string, process: Process}
     */
    private function startServer(string $docroot): array
    {
        // Let the OS allocate the port. The owned process reports readiness only after binding.
        $process = new Process(
            [\PHP_BINARY, '-S', '127.0.0.1:0', '-t', $docroot],
            $docroot,
            ['HATFIELD_SESSION_ID' => false, 'PHP_CLI_SERVER_WORKERS' => false],
        );
        $process->setTimeout(5);
        try {
            $process->start();
            $url = '';
            $ready = $process->waitUntil(static function () use ($process, &$url): bool {
                if (1 !== preg_match('/Development Server \((http:\/\/127\.0\.0\.1:[0-9]+)\) started/', $process->getErrorOutput(), $match)) {
                    return false;
                }
                $url = $match[1];

                return true;
            });
            $this->assertTrue($ready && $process->isRunning(), 'Fixture HTTP server failed to start: '.$process->getErrorOutput());

            return ['url' => $url, 'process' => $process];
        } catch (\Throwable $e) {
            // The caller has no server handle yet when startup fails.
            $process->stop(0);

            throw $e;
        }
    }

    /**
     * @param array{url: string, process: Process}|null $server
     */
    private function stopServer(?array $server): void
    {
        if (null !== $server) {
            $server['process']->stop(0);
        }
    }
}
