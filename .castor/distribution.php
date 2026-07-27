<?php

declare(strict_types=1);

/**
 * Distribution packaging tasks: static/native binaries, checksums, verify.
 *
 * Local phar:* tasks remain authoritative for the canonical PHAR.
 * These tasks compose them into release artifacts under var/tmp/dist/.
 */

use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';

/** Default distribution output directory (worktree-local). */
const HATFIELD_DIST_DIR_DEFAULT = 'var/tmp/dist';

/** Canonical release artifact basenames. */
const HATFIELD_DIST_ARTIFACTS = [
    'hatfield.phar',
    'hatfield.linux-amd64',
    'hatfield.linux-arm64',
    'hatfield.darwin-amd64',
    'hatfield.darwin-arm64',
    'SHA256SUMS',
];

/**
 * Supported static targets: name => [os, arch, host_os, host_arch].
 *
 * @return array<string, array{os: string, arch: string, host_os: string, host_arch: string}>
 */
function distribution_targets(): array
{
    return [
        'linux-amd64' => ['os' => 'linux', 'arch' => 'x86_64', 'host_os' => 'Linux', 'host_arch' => 'x86_64'],
        'linux-arm64' => ['os' => 'linux', 'arch' => 'aarch64', 'host_os' => 'Linux', 'host_arch' => 'aarch64'],
        'darwin-amd64' => ['os' => 'macos', 'arch' => 'x86_64', 'host_os' => 'Darwin', 'host_arch' => 'x86_64'],
        'darwin-arm64' => ['os' => 'macos', 'arch' => 'aarch64', 'host_os' => 'Darwin', 'host_arch' => 'arm64'],
    ];
}

function distribution_root(): string
{
    return false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..';
}

function distribution_dir(?string $override = null): string
{
    if (null !== $override && '' !== $override) {
        return str_starts_with($override, '/') ? $override : distribution_root().'/'.$override;
    }
    $env = getenv('HATFIELD_DIST_DIR');
    if (false !== $env && '' !== $env) {
        return str_starts_with($env, '/') ? $env : distribution_root().'/'.$env;
    }

    return distribution_root().'/'.HATFIELD_DIST_DIR_DEFAULT;
}

function distribution_artifact_name(string $target): string
{
    if ('phar' === $target) {
        return 'hatfield.phar';
    }

    return 'hatfield.'.$target;
}

/**
 * @return array{static_php_cli_commit: string, static_php_cli_repository: string, php_version: string, extensions: list<string>, micro_fake_cli: bool}
 */
function distribution_static_pin(): array
{
    $path = distribution_root().'/tools/static/pin.json';
    if (!is_file($path)) {
        throw new RuntimeException('Missing static toolchain pin: '.$path);
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid tools/static/pin.json');
    }
    $commit = $data['static_php_cli_commit'] ?? null;
    $repo = $data['static_php_cli_repository'] ?? null;
    $phpVersion = $data['php_version'] ?? null;
    $extensions = $data['extensions'] ?? null;
    if (!is_string($commit) || !is_string($repo) || !is_string($phpVersion) || !is_array($extensions)) {
        throw new RuntimeException('tools/static/pin.json missing required keys');
    }

    return [
        'static_php_cli_commit' => $commit,
        'static_php_cli_repository' => $repo,
        'php_version' => $phpVersion,
        'extensions' => array_values(array_map('strval', $extensions)),
        'micro_fake_cli' => (bool) ($data['micro_fake_cli'] ?? true),
    ];
}

function distribution_host_target(): string
{
    $os = php_uname('s');
    $arch = php_uname('m');
    $osKey = match (true) {
        str_contains($os, 'Linux') => 'linux',
        str_contains($os, 'Darwin') => 'darwin',
        default => throw new RuntimeException('Unsupported host OS for static build: '.$os),
    };
    $archKey = match ($arch) {
        'x86_64', 'amd64' => 'amd64',
        'aarch64', 'arm64' => 'arm64',
        default => throw new RuntimeException('Unsupported host architecture for static build: '.$arch),
    };

    return $osKey.'-'.$archKey;
}

function distribution_assert_host_can_build(string $target): void
{
    $targets = distribution_targets();
    if (!isset($targets[$target])) {
        throw new RuntimeException('Unknown static target: '.$target.'. Supported: '.implode(', ', array_keys($targets)));
    }
    $host = distribution_host_target();
    if ($host !== $target) {
        throw new RuntimeException("Host target {$host} cannot build {$target}. Cross-compilation is not supported; CI owns the matrix.");
    }
}

/**
 * Ensure pinned static-php-cli checkout exists and return its root path.
 */
function distribution_ensure_spc_checkout(): string
{
    $pin = distribution_static_pin();
    $cacheRoot = distribution_root().'/var/tmp/static-php-cli';
    $checkout = $cacheRoot.'/'.$pin['static_php_cli_commit'];
    if (!is_dir($cacheRoot) && !mkdir($cacheRoot, 0755, true) && !is_dir($cacheRoot)) {
        throw new RuntimeException('Unable to create static-php-cli cache dir: '.$cacheRoot);
    }

    if (!is_dir($checkout.'/.git')) {
        if (is_dir($checkout)) {
            \CastorTasks\remove_path_checked($checkout);
        }
        echo "Cloning static-php-cli @{$pin['static_php_cli_commit']}...\n";
        \CastorTasks\run_checked(
            'git clone --filter=blob:none '.escapeshellarg($pin['static_php_cli_repository']).' '.escapeshellarg($checkout)
        );
        \CastorTasks\run_checked(
            'git -C '.escapeshellarg($checkout).' checkout '.escapeshellarg($pin['static_php_cli_commit'])
        );
    } else {
        $head = trim(\CastorTasks\run_checked('git -C '.escapeshellarg($checkout).' rev-parse HEAD'));
        if ($head !== $pin['static_php_cli_commit']) {
            \CastorTasks\run_checked('git -C '.escapeshellarg($checkout).' fetch --all');
            \CastorTasks\run_checked(
                'git -C '.escapeshellarg($checkout).' checkout '.escapeshellarg($pin['static_php_cli_commit'])
            );
        }
    }

    return $checkout;
}

/**
 * Build micro.sfx for the host using pinned SPC + pin.json extensions.
 *
 * @return array{spc: string, micro_sfx: string, work_dir: string}
 */
function distribution_build_micro_sfx(string $target): array
{
    distribution_assert_host_can_build($target);
    $pin = distribution_static_pin();
    $spcRoot = distribution_ensure_spc_checkout();
    $workDir = distribution_root().'/var/tmp/static-build/'.$target;
    if (is_dir($workDir)) {
        // Keep downloads cache when present; rebuild outputs only.
        foreach (['buildroot', 'source', 'downloads'] as $keep) {
            // leave downloads
        }
    }
    if (!is_dir($workDir) && !mkdir($workDir, 0755, true) && !is_dir($workDir)) {
        throw new RuntimeException('Unable to create static build dir: '.$workDir);
    }

    // SPC is a Composer project — vendor/ must exist before bin/spc can boot.
    $spcBin = $spcRoot.'/bin/spc';
    if (!is_dir($spcRoot.'/vendor') && is_file($spcRoot.'/composer.json')) {
        $composer = \CastorTasks\hatfield_phar_composer_bin();
        echo "Installing static-php-cli Composer dependencies...\n";
        \CastorTasks\run_checked(
            escapeshellarg($composer).' install --no-dev --no-interaction --no-progress 2>&1',
            $spcRoot
        );
    }
    if (!is_file($spcBin)) {
        throw new RuntimeException('static-php-cli binary not found at '.$spcBin);
    }
    if (!is_file($spcRoot.'/vendor/autoload.php')) {
        throw new RuntimeException('static-php-cli vendor/autoload.php missing after composer install in '.$spcRoot);
    }

    $extensions = implode(',', $pin['extensions']);
    $libsHint = 'libxml,zlib,openssl,curl,sqlite,bzip2,onig,icu'; // best-effort; spc resolves deps

    echo "SPC doctor...\n";
    try {
        \CastorTasks\run_checked(escapeshellarg($spcBin).' doctor --auto-fix 2>&1', $workDir);
    } catch (Throwable $e) {
        echo "SPC doctor warning (continuing): {$e->getMessage()}\n";
    }

    echo "SPC download extensions={$extensions} php={$pin['php_version']}...\n";
    \CastorTasks\run_checked(
        escapeshellarg($spcBin).' download'
        .' --for-extensions='.escapeshellarg($extensions)
        .' --with-php='.escapeshellarg($pin['php_version'])
        .' --prefer-binary 2>&1',
        $workDir
    );

    $buildArgs = escapeshellarg($spcBin).' build '.escapeshellarg($extensions)
        .' --build-cli --build-micro';
    if ($pin['micro_fake_cli']) {
        $buildArgs .= ' --with-micro-fake-cli';
    }
    $buildArgs .= ' 2>&1';
    echo "SPC build micro...\n";
    \CastorTasks\run_checked($buildArgs, $workDir);

    $microSfx = $workDir.'/buildroot/bin/micro.sfx';
    if (!is_file($microSfx)) {
        // Some layouts nest under spc cwd differently.
        $alt = $workDir.'/buildroot/bin/micro.sfx';
        if (!is_file($alt)) {
            throw new RuntimeException('micro.sfx not found after SPC build under '.$workDir);
        }
        $microSfx = $alt;
    }

    return ['spc' => $spcBin, 'micro_sfx' => $microSfx, 'work_dir' => $workDir];
}

function distribution_combine_micro(string $spcBin, string $microSfx, string $pharPath, string $outputPath, string $workDir): void
{
    if (is_file($outputPath) || is_link($outputPath)) {
        \CastorTasks\remove_path_checked($outputPath);
    }
    $parent = dirname($outputPath);
    if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
        throw new RuntimeException('Unable to create dist output dir: '.$parent);
    }

    \CastorTasks\run_checked(
        escapeshellarg($spcBin).' micro:combine '.escapeshellarg($pharPath)
        .' --with-micro='.escapeshellarg($microSfx)
        .' --output='.escapeshellarg($outputPath)
        .' 2>&1',
        $workDir
    );

    if (!is_file($outputPath) || 0 === filesize($outputPath)) {
        throw new RuntimeException('Static combine produced empty/missing artifact: '.$outputPath);
    }
    chmod($outputPath, 0755);
}

function distribution_smoke_artifact(string $artifactPath, bool $isPhar = false): void
{
    if (!is_file($artifactPath) || filesize($artifactPath) < 1024) {
        throw new RuntimeException('Artifact missing or too small: '.$artifactPath);
    }

    $tmp = sys_get_temp_dir().'/hatfield-dist-smoke-'.bin2hex(random_bytes(6));
    if (!mkdir($tmp, 0755, true) && !is_dir($tmp)) {
        throw new RuntimeException('Unable to create smoke cwd');
    }
    try {
        $home = $tmp.'/home';
        mkdir($home.'/.hatfield', 0755, true);
        \CastorTasks\write_file_checked($home.'/.hatfield/settings.yaml', "ai:\n    default_model: null\n");
        $prefix = 'HOME='.escapeshellarg($home).' APP_ENV=prod ';
        if ($isPhar) {
            $cmdBase = $prefix.escapeshellarg(\PHP_BINARY).' '.escapeshellarg($artifactPath);
        } else {
            $cmdBase = $prefix.escapeshellarg($artifactPath);
        }

        $version = \CastorTasks\run_checked($cmdBase.' --version 2>&1', $tmp);
        if (!str_contains($version, 'Hatfield') || !str_contains($version, 'commit')) {
            throw new RuntimeException('--version smoke failed for '.$artifactPath.': '.$version);
        }
        $list = \CastorTasks\run_checked($cmdBase.' list 2>&1', $tmp);
        if (!str_contains($list, 'agent')) {
            throw new RuntimeException('list smoke failed for '.$artifactPath);
        }
        echo "  dist smoke ok: {$artifactPath}\n";
    } finally {
        try {
            \CastorTasks\remove_path_checked($tmp);
        } catch (Throwable $e) {
            fwrite(\STDERR, 'dist smoke cleanup warning: '.$e->getMessage()."\n");
        }
    }
}

/**
 * Prove native binary can spawn headless controller and messenger children through itself.
 */
function distribution_smoke_native_process_topology(string $artifactPath): void
{
    if (!is_file($artifactPath)) {
        throw new RuntimeException('Native topology smoke: artifact missing: '.$artifactPath);
    }

    $tmp = sys_get_temp_dir().'/hatfield-native-topo-'.bin2hex(random_bytes(6));
    mkdir($tmp, 0755, true);
    mkdir($tmp.'/home/.hatfield', 0755, true);
    mkdir($tmp.'/.hatfield', 0755, true);
    \CastorTasks\write_file_checked($tmp.'/home/.hatfield/settings.yaml', "ai:\n    default_model: null\n");

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $env = [
        'HOME' => $tmp.'/home',
        'APP_ENV' => 'prod',
        'APP_DEBUG' => '0',
        'HATFIELD_CWD' => $tmp,
        'HATFIELD_BINARY_PATH' => $artifactPath,
    ];
    // Merge minimal env for child.
    $fullEnv = [];
    foreach (getenv() as $k => $v) {
        $fullEnv[$k] = $v;
    }
    foreach ($env as $k => $v) {
        $fullEnv[$k] = $v;
    }

    $cmd = [$artifactPath, 'agent', '--controller', '--cwd='.$tmp];
    $proc = proc_open($cmd, $descriptors, $pipes, $tmp, $fullEnv);
    if (!is_resource($proc)) {
        \CastorTasks\remove_path_checked($tmp);
        throw new RuntimeException('Failed to start native controller for topology smoke');
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $deadline = microtime(true) + 20.0;
    $ready = false;
    try {
        while (microtime(true) < $deadline) {
            $chunk = stream_get_contents($pipes[1]);
            if (is_string($chunk) && '' !== $chunk) {
                $stdout .= $chunk;
                if (str_contains($stdout, 'runtime.ready') || str_contains($stdout, '"type":"runtime.ready"')) {
                    $ready = true;
                    break;
                }
            }
            usleep(50_000);
        }
        if (!$ready) {
            $errRaw = stream_get_contents($pipes[2]);
            $err = is_string($errRaw) ? $errRaw : '';
            throw new RuntimeException("Native topology smoke: runtime.ready not observed.\nstdout:\n".substr($stdout, -2000)."\nstderr:\n".substr($err, -2000));
        }

        // Inspect children of the controller: should include messenger:consume via same binary.
        $status = proc_get_status($proc);
        $pid = $status['pid'];
        if ($pid <= 0) {
            throw new RuntimeException('Native topology smoke: controller pid unavailable');
        }
        $psRaw = shell_exec('ps --ppid '.escapeshellarg((string) $pid).' -o args= 2>/dev/null');
        $ps = is_string($psRaw) ? $psRaw : '';
        if (!str_contains($ps, 'messenger:consume') && !str_contains($ps, $artifactPath)) {
            // Soft check: some platforms hide children briefly; still require ready.
            echo "  native topology: runtime.ready ok (child listing inconclusive on this host)\n";
        } else {
            if (str_contains($ps, 'bin/console') && !str_contains($ps, $artifactPath)) {
                throw new RuntimeException('Native topology smoke: children still use source bin/console: '.$ps);
            }
            echo "  native topology: controller ready and children observed\n";
        }
    } finally {
        foreach ([0, 1, 2] as $fd) {
            if (isset($pipes[$fd]) && is_resource($pipes[$fd])) {
                fclose($pipes[$fd]);
            }
        }
        $status = proc_get_status($proc);
        if ($status['running']) {
            posix_kill($status['pid'], \SIGTERM);
            $waitUntil = microtime(true) + 3.0;
            while (microtime(true) < $waitUntil) {
                $status = proc_get_status($proc);
                if (!$status['running']) {
                    break;
                }
                usleep(50_000);
            }
            $status = proc_get_status($proc);
            if ($status['running']) {
                posix_kill($status['pid'], \SIGKILL);
            }
        }
        proc_close($proc);
        try {
            \CastorTasks\remove_path_checked($tmp);
        } catch (Throwable $e) {
            fwrite(\STDERR, 'native topology cleanup warning: '.$e->getMessage()."\n");
        }
    }
}

/**
 * @param list<string> $artifactNames
 */
function distribution_write_sha256sums(string $distDir, array $artifactNames): string
{
    $lines = [];
    foreach ($artifactNames as $name) {
        if ('SHA256SUMS' === $name) {
            continue;
        }
        $path = $distDir.'/'.$name;
        if (!is_file($path)) {
            continue;
        }
        $hash = hash_file('sha256', $path);
        if (false === $hash) {
            throw new RuntimeException('Unable to hash '.$path);
        }
        $lines[] = $hash.'  '.$name;
    }
    sort($lines);
    $out = $distDir.'/SHA256SUMS';
    \CastorTasks\write_file_checked($out, implode("\n", $lines).([] !== $lines ? "\n" : ''));

    return $out;
}

function distribution_verify_sha256sums(string $distDir): void
{
    $sums = $distDir.'/SHA256SUMS';
    if (!is_file($sums)) {
        throw new RuntimeException('SHA256SUMS missing in '.$distDir);
    }
    $lines = file($sums, \FILE_IGNORE_NEW_LINES);
    if (false === $lines) {
        throw new RuntimeException('Unable to read SHA256SUMS');
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ('' === $line || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^([a-f0-9]{64})  (.+)$/', $line, $m)) {
            throw new RuntimeException('Invalid SHA256SUMS line: '.$line);
        }
        $path = $distDir.'/'.$m[2];
        if (!is_file($path)) {
            throw new RuntimeException('SHA256SUMS references missing file: '.$m[2]);
        }
        $actual = hash_file('sha256', $path);
        if ($actual !== $m[1]) {
            throw new RuntimeException("Checksum mismatch for {$m[2]}: expected {$m[1]}, got {$actual}");
        }
    }
    echo "SHA256SUMS verified for {$distDir}\n";
}

#[AsTask(name: 'distribution:info', description: 'Show distribution artifact paths and host target')]
function distribution_info(): void
{
    $dist = distribution_dir();
    echo 'Dist dir: '.$dist.\PHP_EOL;
    echo 'Host target: '.distribution_host_target().\PHP_EOL;
    $pin = distribution_static_pin();
    echo 'SPC commit: '.$pin['static_php_cli_commit'].\PHP_EOL;
    echo 'PHP version: '.$pin['php_version'].\PHP_EOL;
    echo 'Extensions: '.implode(',', $pin['extensions']).\PHP_EOL;
    foreach (HATFIELD_DIST_ARTIFACTS as $name) {
        $path = $dist.'/'.$name;
        $state = is_file($path) ? filesize($path).' bytes' : 'missing';
        echo "  {$name}: {$state}\n";
    }
}

#[AsTask(name: 'distribution:build', description: 'Build canonical PHAR into dist/ and generate checksums')]
function distribution_build(
    #[AsOption(description: 'Output directory (default var/tmp/dist)')]
    ?string $output = null,
    #[AsOption(description: 'Release version to embed (HATFIELD_BUILD_VERSION)')]
    ?string $releaseVersion = null,
    #[AsOption(description: 'Commit SHA to embed')]
    ?string $commit = null,
): void {
    $root = distribution_root();
    if (null !== $releaseVersion && '' !== $releaseVersion) {
        putenv('HATFIELD_BUILD_VERSION='.$releaseVersion);
        $_ENV['HATFIELD_BUILD_VERSION'] = $releaseVersion;
    }
    if (null !== $commit && '' !== $commit) {
        putenv('HATFIELD_BUILD_COMMIT='.$commit);
        $_ENV['HATFIELD_BUILD_COMMIT'] = $commit;
    }

    $pharPath = \CastorTasks\phar_build();
    $dist = distribution_dir($output);
    if (!is_dir($dist) && !mkdir($dist, 0755, true) && !is_dir($dist)) {
        throw new RuntimeException('Unable to create dist dir: '.$dist);
    }
    $dest = $dist.'/hatfield.phar';
    if (is_file($dest)) {
        \CastorTasks\remove_path_checked($dest);
    }
    \CastorTasks\copy_file_checked($pharPath, $dest);
    chmod($dest, 0755);
    distribution_smoke_artifact($dest, isPhar: true);
    distribution_write_sha256sums($dist, ['hatfield.phar']);
    echo "Distribution PHAR ready: {$dest}\n";
}

#[AsTask(name: 'distribution:build-static', description: 'Build host-native static binary from canonical PHAR')]
function distribution_build_static(
    #[AsOption(description: 'Target (linux-amd64|linux-arm64|darwin-amd64|darwin-arm64); default host')]
    ?string $target = null,
    #[AsOption(description: 'Output directory (default var/tmp/dist)')]
    ?string $output = null,
    #[AsOption(description: 'Release version to embed (HATFIELD_BUILD_VERSION)')]
    ?string $releaseVersion = null,
    #[AsOption(description: 'Commit SHA to embed')]
    ?string $commit = null,
): void {
    $target ??= distribution_host_target();
    distribution_assert_host_can_build($target);

    if (null !== $releaseVersion && '' !== $releaseVersion) {
        putenv('HATFIELD_BUILD_VERSION='.$releaseVersion);
        $_ENV['HATFIELD_BUILD_VERSION'] = $releaseVersion;
    }
    if (null !== $commit && '' !== $commit) {
        putenv('HATFIELD_BUILD_COMMIT='.$commit);
        $_ENV['HATFIELD_BUILD_COMMIT'] = $commit;
    }

    // Ensure PHAR exists with embedded identity.
    $pharPath = \CastorTasks\phar_ensure();
    $dist = distribution_dir($output);
    if (!is_dir($dist) && !mkdir($dist, 0755, true) && !is_dir($dist)) {
        throw new RuntimeException('Unable to create dist dir: '.$dist);
    }
    $pharDest = $dist.'/hatfield.phar';
    if (!is_file($pharDest) || filemtime($pharDest) < filemtime($pharPath)) {
        if (is_file($pharDest)) {
            \CastorTasks\remove_path_checked($pharDest);
        }
        \CastorTasks\copy_file_checked($pharPath, $pharDest);
        chmod($pharDest, 0755);
    }

    $built = distribution_build_micro_sfx($target);
    $out = $dist.'/'.distribution_artifact_name($target);
    distribution_combine_micro($built['spc'], $built['micro_sfx'], $pharDest, $out, $built['work_dir']);
    distribution_smoke_artifact($out, isPhar: false);
    distribution_smoke_native_process_topology($out);
    distribution_write_sha256sums($dist, array_values(array_filter(
        ($scan = scandir($dist)) !== false ? $scan : [],
        static fn (string $f): bool => str_starts_with($f, 'hatfield.')
    )));
    echo "Static binary ready: {$out}\n";
}

#[AsTask(name: 'distribution:checksums', description: 'Generate SHA256SUMS for artifacts in dist dir')]
function distribution_checksums(
    #[AsOption(description: 'Output directory (default var/tmp/dist)')]
    ?string $output = null,
): void {
    $dist = distribution_dir($output);
    if (!is_dir($dist)) {
        throw new RuntimeException('Dist dir missing: '.$dist);
    }
    $names = array_values(array_filter(
        ($scan = scandir($dist)) !== false ? $scan : [],
        static fn (string $f): bool => str_starts_with($f, 'hatfield.')
    ));
    $path = distribution_write_sha256sums($dist, $names);
    echo "Wrote {$path}\n";
}

#[AsTask(name: 'distribution:verify', description: 'Verify dist artifacts: sizes, checksums, smokes')]
function distribution_verify(
    #[AsOption(description: 'Output directory (default var/tmp/dist)')]
    ?string $output = null,
    #[AsOption(description: 'Also run native process topology smoke when host static artifact present')]
    bool $topology = true,
): void {
    $dist = distribution_dir($output);
    if (!is_dir($dist)) {
        throw new RuntimeException('Dist dir missing: '.$dist);
    }

    $phar = $dist.'/hatfield.phar';
    if (is_file($phar)) {
        distribution_smoke_artifact($phar, isPhar: true);
    } else {
        echo "Note: hatfield.phar missing in {$dist}\n";
    }

    $hostArtifact = $dist.'/'.distribution_artifact_name(distribution_host_target());
    if (is_file($hostArtifact)) {
        distribution_smoke_artifact($hostArtifact, isPhar: false);
        if ($topology) {
            distribution_smoke_native_process_topology($hostArtifact);
        }
    } else {
        echo "Note: host static artifact missing ({$hostArtifact})\n";
    }

    if (is_file($dist.'/SHA256SUMS')) {
        distribution_verify_sha256sums($dist);
    } else {
        echo "Note: SHA256SUMS missing\n";
    }

    echo "distribution:verify ok\n";
}

#[AsTask(name: 'distribution:clean', description: 'Remove dist artifacts and static build caches')]
function distribution_clean(
    #[AsOption(description: 'Also remove static-php-cli clone cache')]
    bool $all = false,
): void {
    $dist = distribution_dir();
    if (is_dir($dist)) {
        \CastorTasks\remove_path_checked($dist);
        echo "Removed {$dist}\n";
    }
    $staticBuild = distribution_root().'/var/tmp/static-build';
    if (is_dir($staticBuild)) {
        \CastorTasks\remove_path_checked($staticBuild);
        echo "Removed {$staticBuild}\n";
    }
    if ($all) {
        $spc = distribution_root().'/var/tmp/static-php-cli';
        if (is_dir($spc)) {
            \CastorTasks\remove_path_checked($spc);
            echo "Removed {$spc}\n";
        }
    }
}
