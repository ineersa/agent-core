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
 * @return array{
 *     static_php_cli_commit: string,
 *     static_php_cli_repository: string,
 *     php_version: string,
 *     php_source_sha256: string,
 *     phpmicro_repository: string,
 *     phpmicro_commit: string,
 *     phpmicro_patch: string,
 *     phpmicro_patch_sha256: string,
 *     extensions: list<string>,
 *     micro_fake_cli: bool
 * }
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
    $phpSha = $data['php_source_sha256'] ?? null;
    $phpmicroRepo = $data['phpmicro_repository'] ?? null;
    $phpmicroCommit = $data['phpmicro_commit'] ?? null;
    $phpmicroPatch = $data['phpmicro_patch'] ?? null;
    $phpmicroPatchSha = $data['phpmicro_patch_sha256'] ?? null;
    $extensions = $data['extensions'] ?? null;
    if (
        !is_string($commit) || '' === $commit
        || !is_string($repo) || '' === $repo
        || !is_string($phpVersion) || '' === $phpVersion
        || !is_string($phpSha) || 1 !== preg_match('/^[a-f0-9]{64}$/', $phpSha)
        || !is_string($phpmicroRepo) || '' === $phpmicroRepo
        || !is_string($phpmicroCommit) || '' === $phpmicroCommit
        || !is_string($phpmicroPatch) || '' === $phpmicroPatch
        || !is_string($phpmicroPatchSha) || 1 !== preg_match('/^[a-f0-9]{64}$/', $phpmicroPatchSha)
        || !is_array($extensions)
    ) {
        throw new RuntimeException('tools/static/pin.json missing required keys (static_php_cli_*, php_version, php_source_sha256, phpmicro_*, phpmicro_patch*, extensions)');
    }
    if (1 !== preg_match('/^\d+\.\d+\.\d+$/', $phpVersion)) {
        throw new RuntimeException('tools/static/pin.json php_version must be an exact patch version (e.g. 8.5.8), got: '.$phpVersion);
    }

    return [
        'static_php_cli_commit' => $commit,
        'static_php_cli_repository' => $repo,
        'php_version' => $phpVersion,
        'php_source_sha256' => $phpSha,
        'phpmicro_repository' => $phpmicroRepo,
        'phpmicro_commit' => $phpmicroCommit,
        'phpmicro_patch' => $phpmicroPatch,
        'phpmicro_patch_sha256' => $phpmicroPatchSha,
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
 * Ensure a git checkout at an exact commit under the worktree cache.
 */
function distribution_ensure_git_checkout(string $cacheRoot, string $repository, string $commit, string $label): string
{
    if (!is_dir($cacheRoot) && !mkdir($cacheRoot, 0755, true) && !is_dir($cacheRoot)) {
        throw new RuntimeException('Unable to create cache dir: '.$cacheRoot);
    }
    $checkout = $cacheRoot.'/'.$commit;
    if (!is_dir($checkout.'/.git')) {
        if (is_dir($checkout)) {
            \CastorTasks\remove_path_checked($checkout);
        }
        echo "Cloning {$label} @{$commit}...\n";
        \CastorTasks\run_checked(
            'git clone --filter=blob:none '.escapeshellarg($repository).' '.escapeshellarg($checkout)
        );
        \CastorTasks\run_checked(
            'git -C '.escapeshellarg($checkout).' checkout '.escapeshellarg($commit)
        );
    } else {
        $head = trim(\CastorTasks\run_checked('git -C '.escapeshellarg($checkout).' rev-parse HEAD'));
        if ($head !== $commit) {
            \CastorTasks\run_checked('git -C '.escapeshellarg($checkout).' fetch --all');
            \CastorTasks\run_checked(
                'git -C '.escapeshellarg($checkout).' checkout '.escapeshellarg($commit)
            );
            $head = trim(\CastorTasks\run_checked('git -C '.escapeshellarg($checkout).' rev-parse HEAD'));
            if ($head !== $commit) {
                throw new RuntimeException("Unable to checkout {$label} at {$commit} (HEAD={$head})");
            }
        }
    }

    return $checkout;
}

/**
 * Ensure pinned static-php-cli checkout exists and return its root path.
 */
function distribution_ensure_spc_checkout(): string
{
    $pin = distribution_static_pin();

    return distribution_ensure_git_checkout(
        distribution_root().'/var/tmp/static-php-cli',
        $pin['static_php_cli_repository'],
        $pin['static_php_cli_commit'],
        'static-php-cli',
    );
}

/**
 * Ensure pinned phpmicro checkout exists and return its absolute path.
 * Cached under var/tmp/static-php-cli/phpmicro/<commit> so the release cache key covers it.
 */
function distribution_ensure_phpmicro_checkout(): string
{
    $pin = distribution_static_pin();

    return distribution_ensure_git_checkout(
        distribution_root().'/var/tmp/static-php-cli/phpmicro',
        $pin['phpmicro_repository'],
        $pin['phpmicro_commit'],
        'phpmicro',
    );
}

/**
 * Ensure pinned phpmicro checkout, reset the Linux self-path source file, and apply the
 * tracked patch fail-closed. Cached tool checkout only — never touches the monorepo tree.
 *
 * Patch replaces realpath(getauxval(AT_EXECFN)) with realpath("/proc/self/exe") so relative
 * invocation of fused Linux micro binaries does not SIGSEGV when AT_EXECFN sits at a page boundary.
 */
function distribution_prepare_phpmicro_checkout(): string
{
    $pin = distribution_static_pin();
    $checkout = distribution_ensure_phpmicro_checkout();
    $root = distribution_root();
    $patchRel = $pin['phpmicro_patch'];
    $patchPath = $root.'/'.$patchRel;
    if (!is_file($patchPath)) {
        throw new RuntimeException('Missing pinned phpmicro patch: '.$patchPath);
    }
    $actualSha = hash_file('sha256', $patchPath);
    if (false === $actualSha || !hash_equals($pin['phpmicro_patch_sha256'], $actualSha)) {
        throw new RuntimeException('phpmicro patch SHA-256 mismatch for '.$patchPath.' (expected '.$pin['phpmicro_patch_sha256'].', got '.(false === $actualSha ? 'unreadable' : $actualSha).')');
    }

    // Idempotent: dirty/cached prior apply → restore exact pinned file then re-apply.
    \CastorTasks\run_checked(
        'git -C '.escapeshellarg($checkout).' checkout '.escapeshellarg($pin['phpmicro_commit'])
        .' -- '.escapeshellarg('php_micro_fileinfo.c')
    );
    \CastorTasks\run_checked(
        'git -C '.escapeshellarg($checkout).' apply --check '.escapeshellarg($patchPath)
    );
    \CastorTasks\run_checked(
        'git -C '.escapeshellarg($checkout).' apply '.escapeshellarg($patchPath)
    );
    echo "phpmicro prepared @{$pin['phpmicro_commit']} + {$patchRel}\n";

    return $checkout;
}

/**
 * Locate the official PHP source archive downloaded by SPC and verify its SHA-256.
 * Prefer exact php-{version}.tar.xz under the workdir downloads tree.
 */
function distribution_assert_php_source_sha256(string $workDir, string $phpVersion, string $expectedSha256): void
{
    $expectedName = 'php-'.$phpVersion.'.tar.xz';
    $candidates = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($workDir, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        if ($file->getFilename() === $expectedName) {
            $candidates[] = $file->getPathname();
        }
    }
    if ([] === $candidates) {
        throw new RuntimeException("Official PHP source archive {$expectedName} not found under {$workDir} after SPC download.".' Refusing to continue without fail-closed hash verification.');
    }
    // Prefer downloads/ paths when multiple copies exist; never accept ambiguous mismatched hashes.
    usort($candidates, static function (string $a, string $b) use ($workDir): int {
        $aPref = str_contains($a, $workDir.'/downloads') ? 0 : 1;
        $bPref = str_contains($b, $workDir.'/downloads') ? 0 : 1;
        if ($aPref !== $bPref) {
            return $aPref <=> $bPref;
        }

        return strcmp($a, $b);
    });
    $path = $candidates[0];
    $actual = hash_file('sha256', $path);
    if (false === $actual) {
        throw new RuntimeException('Unable to hash PHP source archive: '.$path);
    }
    if (!hash_equals($expectedSha256, $actual)) {
        throw new RuntimeException("PHP source SHA-256 mismatch for {$path}: expected {$expectedSha256}, got {$actual}");
    }
    foreach (array_slice($candidates, 1) as $extra) {
        $extraHash = hash_file('sha256', $extra);
        if (false !== $extraHash && !hash_equals($expectedSha256, $extraHash)) {
            throw new RuntimeException("Ambiguous PHP source archives: {$path} matches pin but {$extra} has hash {$extraHash}");
        }
    }
    echo "  PHP source SHA-256 verified: {$path}\n";
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

    // Host toolchain preflight — SPC doctor --auto-fix needs root/sudo; in
    // restricted environments that fails and later zlib/configure errors are opaque.
    $missingTools = [];
    foreach (['re2c', 'flex', 'gperf', 'make', 'cmake'] as $tool) {
        $which = trim((string) shell_exec('command -v '.escapeshellarg($tool).' 2>/dev/null'));
        if ('' === $which) {
            $missingTools[] = $tool;
        }
    }
    $cc = trim((string) shell_exec('command -v cc 2>/dev/null || command -v gcc 2>/dev/null || command -v clang 2>/dev/null'));
    if ('' === $cc) {
        $missingTools[] = 'cc/gcc/clang';
    }
    if ([] !== $missingTools) {
        throw new RuntimeException('Host static build requires build tools that are missing: '.implode(', ', $missingTools).'. Install them (e.g. re2c flex gperf build-essential cmake) or run on CI native runners. SPC doctor --auto-fix needs root/sudo and is not relied on here.');
    }

    echo "SPC doctor...\n";
    try {
        \CastorTasks\run_checked(escapeshellarg($spcBin).' doctor --auto-fix 2>&1', $workDir);
    } catch (Throwable $e) {
        // Intentional local degradation: doctor auto-fix often needs sudo; we already
        // preflighted required tools above. Surface diagnostic and continue.
        echo "SPC doctor warning (continuing after host preflight): {$e->getMessage()}\n";
    }

    $phpmicroPath = distribution_prepare_phpmicro_checkout();
    $customLocal = 'php-micro:'.$phpmicroPath;

    echo "SPC download extensions={$extensions} php={$pin['php_version']} phpmicro={$pin['phpmicro_commit']} patch={$pin['phpmicro_patch']}...\n";
    \CastorTasks\run_checked(
        escapeshellarg($spcBin).' download'
        .' --for-extensions='.escapeshellarg($extensions)
        .' --with-php='.escapeshellarg($pin['php_version'])
        .' --custom-local='.escapeshellarg($customLocal)
        .' --prefer-binary 2>&1',
        $workDir
    );
    distribution_assert_php_source_sha256($workDir, $pin['php_version'], $pin['php_source_sha256']);

    $buildArgs = escapeshellarg($spcBin).' build '.escapeshellarg($extensions)
        .' --build-cli --build-micro'
        .' --dl-with-php='.escapeshellarg($pin['php_version'])
        .' --dl-custom-local='.escapeshellarg($customLocal);
    if ($pin['micro_fake_cli']) {
        $buildArgs .= ' --with-micro-fake-cli';
    }
    // ponytail: pinned PHP 8.5 bare micro smoke segfaults on Linux even with marker payload; skip upstream bare micro+Zend smoke only; retain CLI/ext SPC smokes and Hatfield fused artifact version/list/Composer-platform/native topology hard proofs; remove workaround once upstream stabilizes.
    $buildArgs .= ' --no-smoke-test=micro';
    $buildArgs .= ' 2>&1';
    echo "SPC build micro...\n";
    \CastorTasks\run_checked($buildArgs, $workDir);

    $microSfx = $workDir.'/buildroot/bin/micro.sfx';
    if (!is_file($microSfx)) {
        throw new RuntimeException('micro.sfx not found after SPC build under '.$workDir);
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

/**
 * Smoke an artifact. When $expectedVersion/$expectedCommit are provided, require
 * exact substrings in --version output (fail closed for release handoff identity).
 */
function distribution_smoke_artifact(
    string $artifactPath,
    bool $isPhar = false,
    ?string $expectedVersion = null,
    ?string $expectedCommit = null,
): void {
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
            // Relative invocation from isolated smoke CWD (v0.0.2 publish regression):
            // absolute artifact path masks musl realpath(AT_EXECFN) SIGSEGV on Linux micro.
            // Symlink keeps original dist bytes untouched; realpath of /proc/self/exe still
            // resolves to the fused file after the pinned phpmicro self-path patch.
            $absoluteArtifact = realpath($artifactPath);
            if (false === $absoluteArtifact || !is_file($absoluteArtifact)) {
                throw new RuntimeException('Unable to resolve absolute artifact path for relative smoke: '.$artifactPath);
            }
            $relativeName = 'hatfield';
            $linkPath = $tmp.'/'.$relativeName;
            if (!symlink($absoluteArtifact, $linkPath)) {
                throw new RuntimeException('Unable to create relative smoke symlink: '.$linkPath.' -> '.$absoluteArtifact);
            }
            $cmdBase = $prefix.escapeshellarg('./'.$relativeName);
        }

        $version = \CastorTasks\run_checked($cmdBase.' --version 2>&1', $tmp);
        if (!str_contains($version, 'Hatfield') || !str_contains($version, 'commit')) {
            throw new RuntimeException('--version smoke failed for '.$artifactPath.': '.$version);
        }
        if (null !== $expectedVersion && '' !== $expectedVersion && !str_contains($version, $expectedVersion)) {
            throw new RuntimeException("--version missing expected version {$expectedVersion} for {$artifactPath}: {$version}");
        }
        if (null !== $expectedCommit && '' !== $expectedCommit && !str_contains($version, $expectedCommit)) {
            throw new RuntimeException("--version missing expected commit {$expectedCommit} for {$artifactPath}: {$version}");
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
 * Resolve the canonical PHAR used as micro:combine input.
 *
 * Release static jobs download the PHAR job's artifact into <dist>/hatfield.phar.
 * That file must be used as-is (smoke only) — never rebuilt/overwritten.
 * Local standalone static builds call phar_ensure+copy only when the dist PHAR is absent.
 *
 * @return array{path: string, source: 'handoff'|'built'}
 */
function distribution_resolve_canonical_phar_for_static(
    string $dist,
    ?string $expectedVersion = null,
    ?string $expectedCommit = null,
): array {
    $pharDest = $dist.'/hatfield.phar';
    if (is_file($pharDest) && filesize($pharDest) > 0) {
        // Exact release handoff: do not rebuild or overwrite.
        distribution_smoke_artifact(
            $pharDest,
            isPhar: true,
            expectedVersion: $expectedVersion,
            expectedCommit: $expectedCommit,
        );
        echo "Using existing dist PHAR handoff (no rebuild): {$pharDest}\n";

        return ['path' => $pharDest, 'source' => 'handoff'];
    }

    // Local standalone: build/ensure then place into dist.
    $pharPath = \CastorTasks\phar_ensure();
    if (!is_dir($dist) && !mkdir($dist, 0755, true) && !is_dir($dist)) {
        throw new RuntimeException('Unable to create dist dir: '.$dist);
    }
    if (is_file($pharDest) || is_link($pharDest)) {
        \CastorTasks\remove_path_checked($pharDest);
    }
    \CastorTasks\copy_file_checked($pharPath, $pharDest);
    chmod($pharDest, 0755);
    distribution_smoke_artifact(
        $pharDest,
        isPhar: true,
        expectedVersion: $expectedVersion,
        expectedCommit: $expectedCommit,
    );
    echo "Built/copied local PHAR into dist: {$pharDest}\n";

    return ['path' => $pharDest, 'source' => 'built'];
}

/**
 * Expected messenger transports launched after runtime.ready.
 *
 * runtime.ready is emitted AFTER consumers are launched (HeadlessController::run),
 * so topology verification must wait until these transports appear.
 *
 * @return list<string>
 */
function distribution_expected_messenger_transports(): array
{
    return [
        'run_control',
        'llm',
        'tool',
        'agent',
        'scheduler_default',
        'mcp',
        'extension_agent',
    ];
}

/**
 * Read a process cmdline via /proc (Linux) or portable ps (macOS).
 */
function distribution_read_process_cmdline(int $pid): string
{
    if ($pid <= 0) {
        return '';
    }
    $cmd = trim((string) @file_get_contents('/proc/'.$pid.'/cmdline'));
    if ('' !== $cmd) {
        return str_replace("\0", ' ', $cmd);
    }
    $ps = [];
    @exec('ps -p '.escapeshellarg((string) $pid).' -o args= 2>/dev/null', $ps);

    return trim(implode(' ', $ps));
}

/**
 * True when pid is alive AND still matches the captured cmdline.
 *
 * PID reuse with a different cmdline is treated as gone (not a leak).
 * /proc preferred; portable `ps` fallback for macOS.
 */
function distribution_owned_pid_still_alive(int $pid, string $expectedCmdline): bool
{
    if ($pid <= 0) {
        return false;
    }
    $alive = is_dir('/proc/'.$pid);
    if (!$alive) {
        // Portable fallback when /proc is absent (macOS CI runners).
        $ps = [];
        @exec('ps -p '.escapeshellarg((string) $pid).' -o pid= 2>/dev/null', $ps);
        $alive = [] !== $ps && '' !== trim(implode('', $ps));
    }
    if (!$alive) {
        return false;
    }
    $current = distribution_read_process_cmdline($pid);
    if ('' === $current) {
        // PID exists but cmdline unreadable — treat as still alive (fail closed).
        return true;
    }
    if ($current === $expectedCmdline) {
        return true;
    }
    // Minor argv rewriting still counts as the same owned process.
    if ('' !== $expectedCmdline && (str_contains($current, $expectedCmdline) || str_contains($expectedCmdline, $current))) {
        return true;
    }

    // Alive but unrelated cmdline => PID reuse, not a leak.
    return false;
}

/**
 * Direct children of $ppid via pgrep -P.
 *
 * Exit 0 = rows, exit 1 = no children, anything else = hard error.
 *
 * @return list<int>
 */
function distribution_pgrep_children(int $ppid): array
{
    $output = [];
    $exit = 0;
    // Capture stderr: a broken pgrep must not be mistaken for "no children".
    exec('pgrep -P '.escapeshellarg((string) $ppid).' 2>&1', $output, $exit);
    if (0 === $exit) {
        $pids = [];
        foreach ($output as $line) {
            $child = (int) trim($line);
            if ($child > 0) {
                $pids[] = $child;
            }
        }

        return $pids;
    }
    if (1 === $exit) {
        return [];
    }

    throw new RuntimeException('Native topology smoke: pgrep -P failed (exit '.$exit.') for ppid '.$ppid.".\n".implode("\n", $output));
}

/**
 * Portable ps column names: Linux uses sid/args, macOS uses sess/command.
 *
 * @return array{session: string, command: string}
 */
function distribution_ps_columns(): array
{
    if ('Darwin' === \PHP_OS_FAMILY) {
        return ['session' => 'sess', 'command' => 'command'];
    }

    return ['session' => 'sid', 'command' => 'args'];
}

/**
 * Parse one `ps -axo pid=,session=,command=` line into pid/session/cmd.
 *
 * @return array{pid: int, session: int, cmdline: string}|null
 */
function distribution_parse_ps_process_line(string $line): ?array
{
    $line = trim($line);
    if ('' === $line) {
        return null;
    }
    $parts = preg_split('/\s+/', $line, 3);
    if (!is_array($parts) || count($parts) < 2) {
        return null;
    }
    $pid = (int) $parts[0];
    if ($pid <= 0) {
        return null;
    }

    return [
        'pid' => $pid,
        'session' => (int) $parts[1],
        'cmdline' => $parts[2] ?? '',
    ];
}

/**
 * Session id for a live process. Hard-fails when ps fails (never silent zero).
 */
function distribution_process_session_id(int $pid): int
{
    if ($pid <= 0) {
        throw new RuntimeException('Native topology smoke: invalid pid for session lookup');
    }
    $cols = distribution_ps_columns();
    $output = [];
    $exit = 0;
    $cmd = 'ps -axo pid=,'.escapeshellarg($cols['session']).'= -p '.escapeshellarg((string) $pid).' 2>&1';
    exec($cmd, $output, $exit);
    if (0 !== $exit) {
        throw new RuntimeException('Native topology smoke: ps session lookup failed (exit '.$exit.') for pid '.$pid.".\ncmd: {$cmd}\n".implode("\n", $output));
    }
    foreach ($output as $line) {
        $parts = preg_split('/\s+/', trim($line), 2);
        if (!is_array($parts) || count($parts) < 2) {
            continue;
        }
        if ((int) $parts[0] === $pid) {
            return (int) $parts[1];
        }
    }

    throw new RuntimeException('Native topology smoke: ps session lookup returned no row for pid '.$pid.".\n".implode("\n", $output));
}

/**
 * Collect descendant process command lines for a controller pid.
 *
 * Walks pgrep -P depth-first and also scans session members so separate-PGID
 * messenger children are still visible. Inconclusive inspection must fail.
 *
 * Capture owned PIDs WHILE THE CONTROLLER IS ALIVE. After exit, orphans are
 * reparented so pgrep -P <dead-controller> returns nothing and can false-pass.
 *
 * @return list<array{pid: int, cmdline: string}>
 */
function distribution_collect_descendant_cmdlines(int $rootPid): array
{
    if ($rootPid <= 0) {
        throw new RuntimeException('Native topology smoke: invalid controller pid');
    }

    $found = [];
    $queue = [$rootPid];
    $seen = [$rootPid => true];
    while ([] !== $queue) {
        $ppid = array_shift($queue);
        foreach (distribution_pgrep_children($ppid) as $child) {
            if ($child <= 0 || isset($seen[$child])) {
                continue;
            }
            $seen[$child] = true;
            $queue[] = $child;
            $cmd = distribution_read_process_cmdline($child);
            $found[] = ['pid' => $child, 'cmdline' => $cmd];
        }
    }

    // Session scan catches reparented/separate-PGID messenger children.
    // Portable: Linux sid+args, Darwin sess+command via ps -axo.
    $sessionId = distribution_process_session_id($rootPid);
    if ($sessionId > 0) {
        $cols = distribution_ps_columns();
        $sessionLines = [];
        $exit = 0;
        $psCmd = 'ps -axo pid=,'.escapeshellarg($cols['session']).'='.
            ','.escapeshellarg($cols['command']).'= 2>&1';
        exec($psCmd, $sessionLines, $exit);
        if (0 !== $exit) {
            throw new RuntimeException('Native topology smoke: ps session scan failed (exit '.$exit.")\ncmd: {$psCmd}\n".implode("\n", $sessionLines));
        }
        if ([] === $sessionLines) {
            throw new RuntimeException('Native topology smoke: ps session scan returned zero rows (unsupported/empty ps).'."\ncmd: ".$psCmd);
        }
        foreach ($sessionLines as $line) {
            $parsed = distribution_parse_ps_process_line($line);
            if (null === $parsed) {
                continue;
            }
            $pid = $parsed['pid'];
            if ($pid === $rootPid || $parsed['session'] !== $sessionId || isset($seen[$pid])) {
                continue;
            }
            $seen[$pid] = true;
            $found[] = ['pid' => $pid, 'cmdline' => $parsed['cmdline']];
        }
    }

    return $found;
}

/**
 * After graceful controller stop, assert every pre-captured owned PID is gone
 * (or PID reused with a different cmdline).
 *
 * Fail-closed: still throws when survivors remain after wait. Before throw,
 * best-effort cleanup signals ONLY pre-captured owned PIDs/controller whose
 * current cmdline still matches the snapshot (PID-reuse protected). Never
 * broad pgrep/session/process-group kills; never root/unrelated/session workers.
 *
 * @param list<array{pid: int, cmdline: string}> $ownedSnapshot
 */
function distribution_assert_owned_pids_gone(array $ownedSnapshot, int $controllerPid, string $controllerCmdline, float $waitSeconds = 5.0): void
{
    $deadline = microtime(true) + $waitSeconds;
    $survivors = [];
    do {
        $survivors = [];
        if ($controllerPid > 0 && distribution_owned_pid_still_alive($controllerPid, $controllerCmdline)) {
            $survivors[] = '#'.$controllerPid.' controller still alive: '.distribution_read_process_cmdline($controllerPid);
        }
        foreach ($ownedSnapshot as $row) {
            $pid = $row['pid'];
            $cmdline = $row['cmdline'];
            if ($pid <= 0) {
                continue;
            }
            if (distribution_owned_pid_still_alive($pid, $cmdline)) {
                $survivors[] = '#'.$pid.' '.$cmdline.' (now: '.distribution_read_process_cmdline($pid).')';
            }
        }
        if ([] === $survivors) {
            return;
        }
        usleep(100_000);
    } while (microtime(true) < $deadline);

    // Pre-cleanup diagnostics are the failure contract; cleanup is best-effort only.
    $preCleanup = $survivors;
    distribution_signal_pre_captured_owned_survivors($ownedSnapshot, $controllerPid, $controllerCmdline);

    throw new RuntimeException("Native topology smoke: owned PIDs survived shutdown (pre-captured while controller alive; pgrep -P after exit is not used):\n".implode("\n", $preCleanup));
}

/**
 * TERM then brief wait then KILL only unchanged pre-captured owned PIDs.
 *
 * Re-checks distribution_owned_pid_still_alive before every signal so PID
 * reuse cannot hit an unrelated process. Scoped to the smoke-owned snapshot
 * only — never process groups, sessions, or discovery via pgrep.
 *
 * @param list<array{pid: int, cmdline: string}> $ownedSnapshot
 */
function distribution_signal_pre_captured_owned_survivors(array $ownedSnapshot, int $controllerPid, string $controllerCmdline): void
{
    /** @var list<array{pid: int, cmdline: string}> $targets */
    $targets = [];
    if ($controllerPid > 0) {
        $targets[] = ['pid' => $controllerPid, 'cmdline' => $controllerCmdline];
    }
    foreach ($ownedSnapshot as $row) {
        if (($row['pid'] ?? 0) > 0) {
            $targets[] = $row;
        }
    }

    foreach ($targets as $row) {
        $pid = $row['pid'];
        $cmdline = $row['cmdline'];
        if (!distribution_owned_pid_still_alive($pid, $cmdline)) {
            continue;
        }
        @posix_kill($pid, \SIGTERM);
    }

    usleep(200_000);

    foreach ($targets as $row) {
        $pid = $row['pid'];
        $cmdline = $row['cmdline'];
        if (!distribution_owned_pid_still_alive($pid, $cmdline)) {
            continue;
        }
        @posix_kill($pid, \SIGKILL);
    }
}

/**
 * Assert every expected messenger transport relaunches through the native binary.
 *
 * @param list<array{pid: int, cmdline: string}> $descendants
 */
function distribution_assert_native_messenger_topology(string $artifactPath, array $descendants): void
{
    $resolved = realpath($artifactPath);
    $resolvedArtifact = false !== $resolved ? $resolved : $artifactPath;
    $artifactBase = basename($resolvedArtifact);
    $dump = [];
    foreach ($descendants as $row) {
        $dump[] = '#'.$row['pid'].' '.$row['cmdline'];
    }
    $dumpText = implode("\n", $dump);

    if ([] === $descendants) {
        throw new RuntimeException("Native topology smoke: process inspection returned zero descendants after runtime.ready.\n".'Artifact: '.$resolvedArtifact."\n".'This is a hard failure (never soft-pass). Ensure /proc and pgrep are available.');
    }

    $consumeLines = [];
    foreach ($descendants as $row) {
        if (str_contains($row['cmdline'], 'messenger:consume')) {
            $consumeLines[] = $row['cmdline'];
        }
    }
    if ([] === $consumeLines) {
        throw new RuntimeException("Native topology smoke: no messenger:consume descendants observed.\nDescendants:\n".$dumpText);
    }

    $joined = implode("\n", $consumeLines);
    foreach (distribution_expected_messenger_transports() as $transport) {
        if (!str_contains($joined, $transport)) {
            throw new RuntimeException("Native topology smoke: expected messenger transport '{$transport}' not observed after runtime.ready.\nmessenger:consume lines:\n".$joined."\nAll descendants:\n".$dumpText);
        }
    }

    foreach ($consumeLines as $line) {
        // Fused native: argv0 is the artifact alone (one-element executable), not
        // "php <artifact>" or source bin/console.
        $usesArtifact = str_contains($line, $resolvedArtifact) || str_contains($line, $artifactBase);
        $usesSource = str_contains($line, 'bin/console');
        $usesSystemPhpPrefix = (bool) preg_match('#(?:^|\s)(?:/usr/bin/php|/usr/local/bin/php|php)\s+#', $line)
            && !str_starts_with(trim($line), $resolvedArtifact)
            && !str_starts_with(trim($line), $artifactBase);

        if ($usesSource && !$usesArtifact) {
            throw new RuntimeException("Native topology smoke: messenger child uses source bin/console instead of native artifact.\n".'line: '.$line);
        }
        if ($usesSystemPhpPrefix) {
            throw new RuntimeException("Native topology smoke: messenger child relaunched via system PHP instead of fused native binary.\n".'line: '.$line);
        }
        if (!$usesArtifact) {
            throw new RuntimeException("Native topology smoke: messenger child does not reference native artifact path.\n".'expected artifact: '.$resolvedArtifact."\n".'line: '.$line);
        }
    }
}

/**
 * Prove native binary can spawn headless controller and messenger children through itself.
 *
 * Hard requirements:
 * - wait for runtime.ready, then wait until expected messenger transports are present
 * - every messenger:consume cmdline must relaunch the same native path (not system PHP/source)
 * - inconclusive process inspection fails with diagnostics
 * - after shutdown, no owned descendants may survive
 */
function distribution_smoke_native_process_topology(string $artifactPath): void
{
    if (!is_file($artifactPath)) {
        throw new RuntimeException('Native topology smoke: artifact missing: '.$artifactPath);
    }
    $resolvedArtifactPath = realpath($artifactPath);
    $artifactPath = false !== $resolvedArtifactPath ? $resolvedArtifactPath : $artifactPath;

    $tmp = sys_get_temp_dir().'/hatfield-native-topo-'.bin2hex(random_bytes(6));
    if (!mkdir($tmp, 0755, true) && !is_dir($tmp)) {
        throw new RuntimeException('Native topology smoke: unable to create temp dir');
    }
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
    $stderr = '';
    $deadline = microtime(true) + 25.0;
    $ready = false;
    $controllerPid = 0;
    $controllerCmdline = '';
    /** @var list<array{pid: int, cmdline: string}> $ownedSnapshot */
    $ownedSnapshot = [];
    try {
        while (microtime(true) < $deadline) {
            $chunk = stream_get_contents($pipes[1]);
            if (is_string($chunk) && '' !== $chunk) {
                $stdout .= $chunk;
            }
            $errChunk = stream_get_contents($pipes[2]);
            if (is_string($errChunk) && '' !== $errChunk) {
                $stderr .= $errChunk;
            }
            if (str_contains($stdout, 'runtime.ready') || str_contains($stdout, '"type":"runtime.ready"')) {
                $ready = true;
                break;
            }
            $status = proc_get_status($proc);
            if (!$status['running']) {
                // Drain remaining pipes before diagnosing early exit.
                $chunk = stream_get_contents($pipes[1]);
                if (is_string($chunk) && '' !== $chunk) {
                    $stdout .= $chunk;
                }
                $errChunk = stream_get_contents($pipes[2]);
                if (is_string($errChunk) && '' !== $errChunk) {
                    $stderr .= $errChunk;
                }
                break;
            }
            usleep(50_000);
        }
        if (!$ready) {
            $status = proc_get_status($proc);
            throw new RuntimeException("Native topology smoke: runtime.ready not observed.\n".'controller running: '.($status['running'] ? 'yes' : 'no')."\n".'controller exitcode: '.var_export($status['exitcode'], true)."\nstdout:\n".substr($stdout, -2000)."\nstderr:\n".substr($stderr, -2000));
        }

        $status = proc_get_status($proc);
        $controllerPid = $status['pid'];
        if ($controllerPid <= 0) {
            throw new RuntimeException("Native topology smoke: controller pid unavailable\nstdout:\n".substr($stdout, -2000)."\nstderr:\n".substr($stderr, -2000));
        }
        $controllerCmdline = distribution_read_process_cmdline($controllerPid);
        if ('' === $controllerCmdline) {
            $controllerCmdline = $artifactPath.' agent --controller';
        }

        // Consumers are launched before runtime.ready; still wait until expected transports are visible.
        $transportsReady = false;
        $descendants = [];
        $transportDeadline = microtime(true) + 15.0;
        $inspectionError = null;
        while (microtime(true) < $transportDeadline) {
            // Keep draining controller pipes so child-launch failures stay visible.
            $chunk = stream_get_contents($pipes[1]);
            if (is_string($chunk) && '' !== $chunk) {
                $stdout .= $chunk;
            }
            $errChunk = stream_get_contents($pipes[2]);
            if (is_string($errChunk) && '' !== $errChunk) {
                $stderr .= $errChunk;
            }
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $inspectionError = new RuntimeException(
                    'controller exited while waiting for messenger topology (exitcode='.var_export($status['exitcode'], true).')'
                );
                break;
            }
            try {
                $descendants = distribution_collect_descendant_cmdlines($controllerPid);
                distribution_assert_native_messenger_topology($artifactPath, $descendants);
                $transportsReady = true;
                break;
            } catch (Throwable $e) {
                // Keep polling until deadline; last error is rethrown with pipe tails.
                $last = $e;
            }
            usleep(100_000);
        }
        if (!$transportsReady) {
            $msg = null !== $inspectionError
                ? $inspectionError->getMessage()
                : (isset($last) ? $last->getMessage() : 'expected messenger transports never appeared');
            throw new RuntimeException("Native topology smoke: failed after runtime.ready while waiting for messenger consumers.\n".$msg."\nstdout:\n".substr($stdout, -2000)."\nstderr:\n".substr($stderr, -2000));
        }

        // Capture owned PIDs WHILE controller is still alive. After exit, orphans
        // reparent and pgrep -P <dead-controller> false-passes.
        $ownedSnapshot = [];
        foreach ($descendants as $row) {
            if (
                str_contains($row['cmdline'], $artifactPath)
                || str_contains($row['cmdline'], 'messenger:consume')
                || str_contains($row['cmdline'], basename($artifactPath))
            ) {
                $ownedSnapshot[] = $row;
            }
        }
        if ([] === $ownedSnapshot) {
            throw new RuntimeException('Native topology smoke: topology passed but owned PID snapshot is empty');
        }
        echo "  native topology: runtime.ready + messenger consumers relaunch via {$artifactPath}\n";
        echo '  native topology: captured '.count($ownedSnapshot)." owned descendant PIDs before shutdown\n";
    } finally {
        foreach ([0, 1, 2] as $fd) {
            if (isset($pipes[$fd]) && is_resource($pipes[$fd])) {
                fclose($pipes[$fd]);
            }
        }
        $status = proc_get_status($proc);
        if ($status['running']) {
            // Prefer graceful controller shutdown via SIGTERM (controller signal handlers).
            // Only signal the controller this smoke created — never descendants/unrelated.
            // Wait > ConsumerSupervisor's default 5s shared consumer grace (aligned with
            // JsonlProcessAgentSessionClient::CONTROLLER_STOP_GRACE_SECONDS = 7s).
            posix_kill($status['pid'], \SIGTERM);
            $waitUntil = microtime(true) + 7.0;
            while (microtime(true) < $waitUntil) {
                $status = proc_get_status($proc);
                if (!$status['running']) {
                    break;
                }
                usleep(50_000);
            }
            $status = proc_get_status($proc);
            if ($status['running']) {
                // Last resort for this owned controller only — never signal unrelated processes.
                posix_kill($status['pid'], \SIGKILL);
            }
        }
        proc_close($proc);

        // Assert pre-captured owned PIDs are gone (or PID reused with different cmdline).
        if ($controllerPid > 0 && [] !== $ownedSnapshot) {
            try {
                distribution_assert_owned_pids_gone($ownedSnapshot, $controllerPid, $controllerCmdline, 5.0);
            } catch (Throwable $e) {
                try {
                    \CastorTasks\remove_path_checked($tmp);
                } catch (Throwable $cleanupError) {
                    fwrite(\STDERR, 'native topology cleanup warning: '.$cleanupError->getMessage()."\n");
                }
                throw $e;
            }
        }

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
    echo 'phpmicro commit: '.$pin['phpmicro_commit'].\PHP_EOL;
    echo 'phpmicro patch: '.$pin['phpmicro_patch'].\PHP_EOL;
    echo 'phpmicro patch sha256: '.$pin['phpmicro_patch_sha256'].\PHP_EOL;
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

    $expectedVersion = (null !== $releaseVersion && '' !== $releaseVersion) ? $releaseVersion : null;
    $expectedCommit = (null !== $commit && '' !== $commit) ? $commit : null;

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
    distribution_smoke_artifact(
        $dest,
        isPhar: true,
        expectedVersion: $expectedVersion,
        expectedCommit: $expectedCommit,
    );
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

    $expectedVersion = (null !== $releaseVersion && '' !== $releaseVersion) ? $releaseVersion : null;
    $expectedCommit = (null !== $commit && '' !== $commit) ? $commit : null;

    // Env identity is only for local rebuild path when dist PHAR is absent.
    // Release handoff uses the already-embedded PHAR from the PHAR job.
    if (null !== $expectedVersion) {
        putenv('HATFIELD_BUILD_VERSION='.$expectedVersion);
        $_ENV['HATFIELD_BUILD_VERSION'] = $expectedVersion;
    }
    if (null !== $expectedCommit) {
        putenv('HATFIELD_BUILD_COMMIT='.$expectedCommit);
        $_ENV['HATFIELD_BUILD_COMMIT'] = $expectedCommit;
    }

    $dist = distribution_dir($output);
    if (!is_dir($dist) && !mkdir($dist, 0755, true) && !is_dir($dist)) {
        throw new RuntimeException('Unable to create dist dir: '.$dist);
    }
    $resolved = distribution_resolve_canonical_phar_for_static($dist, $expectedVersion, $expectedCommit);
    $pharDest = $resolved['path'];

    $built = distribution_build_micro_sfx($target);
    $out = $dist.'/'.distribution_artifact_name($target);
    distribution_combine_micro($built['spc'], $built['micro_sfx'], $pharDest, $out, $built['work_dir']);
    distribution_smoke_artifact(
        $out,
        isPhar: false,
        expectedVersion: $expectedVersion,
        expectedCommit: $expectedCommit,
    );
    distribution_smoke_native_process_topology($out);
    distribution_write_sha256sums($dist, array_values(array_filter(
        ($scan = scandir($dist)) !== false ? $scan : [],
        static fn (string $f): bool => str_starts_with($f, 'hatfield.')
    )));
    echo "Static binary ready: {$out} (phar source={$resolved['source']})\n";
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
    #[AsOption(description: 'Skip native process topology smoke (still may require native artifact)')]
    bool $skipTopology = false,
    #[AsOption(description: 'Allow missing hatfield.phar (default: required)')]
    bool $allowMissingPhar = false,
    #[AsOption(description: 'Allow missing host static artifact (default: required)')]
    bool $allowMissingNative = false,
): void {
    $dist = distribution_dir($output);
    if (!is_dir($dist)) {
        throw new RuntimeException('Dist dir missing: '.$dist);
    }

    $phar = $dist.'/hatfield.phar';
    if (is_file($phar)) {
        distribution_smoke_artifact($phar, isPhar: true);
        // Hard packaged-content proof: defaults/themes/migrations/selected docs present.
        distribution_assert_phar_bundled_resources($phar);
    } elseif (!$allowMissingPhar) {
        throw new RuntimeException('distribution:verify requires hatfield.phar in '.$dist);
    } else {
        echo "Note: hatfield.phar missing in {$dist}\n";
    }

    $hostArtifact = $dist.'/'.distribution_artifact_name(distribution_host_target());
    if (is_file($hostArtifact)) {
        distribution_smoke_artifact($hostArtifact, isPhar: false);
        if (!$skipTopology) {
            distribution_smoke_native_process_topology($hostArtifact);
        }
    } elseif (!$allowMissingNative) {
        throw new RuntimeException('distribution:verify requires host static artifact '.$hostArtifact.' (build with castor distribution:build-static). Topology/CI must not soft-pass.');
    } else {
        echo "Note: host static artifact missing ({$hostArtifact})\n";
    }

    if (is_file($dist.'/SHA256SUMS')) {
        distribution_verify_sha256sums($dist);
    } else {
        throw new RuntimeException('distribution:verify requires SHA256SUMS in '.$dist);
    }

    echo "distribution:verify ok\n";
}

/**
 * Assert the PHAR archive contains bundled defaults, themes, migrations, selected docs.
 */
function distribution_assert_phar_bundled_resources(string $pharPath): void
{
    if (!is_file($pharPath)) {
        throw new RuntimeException('PHAR missing for bundled-resource proof: '.$pharPath);
    }
    $phar = new Phar($pharPath);
    $required = [
        'config/hatfield.defaults.yaml',
        'config/themes/catppuccin-mocha.yaml',
        'migrations/application/Version20260601152619.php',
        'migrations/messenger_transport/Version20260828224203.php',
    ];
    foreach ($required as $entry) {
        if (!isset($phar[$entry])) {
            throw new RuntimeException('PHAR missing bundled entry: '.$entry);
        }
        if ($phar[$entry]->isLink()) {
            throw new RuntimeException('PHAR entry must be materialized file, not symlink: '.$entry);
        }
    }

    $root = \CastorTasks\project_root_dir();
    $catalog = (new Ineersa\CodingAgent\Docs\BuiltinDocsCatalog())->discover($root);
    $expected = [];
    foreach ($catalog as $entry) {
        $expected[$entry['relativePath']] = true;
        if (!isset($phar[$entry['relativePath']])) {
            throw new RuntimeException('PHAR missing selected built-in doc: '.$entry['relativePath']);
        }
        if ($phar[$entry['relativePath']]->isLink()) {
            throw new RuntimeException('PHAR built-in doc must be regular file: '.$entry['relativePath']);
        }
        $uri = 'phar://'.$pharPath.'/'.$entry['relativePath'];
        $packaged = file_get_contents($uri);
        $source = file_get_contents($entry['absolutePath']);
        if (false === $packaged || false === $source || $packaged !== $source) {
            throw new RuntimeException('PHAR built-in doc bytes must match source: '.$entry['relativePath']);
        }
    }
    // Exact Markdown inventory under both canonical archive doc roots, and
    // reject ANY archive entry under the vendor path-package Extension API docs tree.
    $canonicalPrefixes = [
        Ineersa\CodingAgent\Docs\BuiltinDocsCatalog::CORE_DOCS_RELATIVE.'/',
        Ineersa\CodingAgent\Docs\BuiltinDocsCatalog::EXTENSION_API_DOCS_RELATIVE.'/',
    ];
    $vendorApiDocsPrefix = 'vendor/ineersa/hatfield-extension-api/docs/';
    foreach (new RecursiveIteratorIterator($phar) as $file) {
        /** @var PharFileInfo $file */
        if (!$file->isFile()) {
            continue;
        }
        $rel = str_replace('\\', '/', $file->getPathname());
        if (str_contains($rel, '.phar/')) {
            $rel = substr($rel, strpos($rel, '.phar/') + strlen('.phar/'));
        }
        if (str_starts_with($rel, $vendorApiDocsPrefix) || $rel === rtrim($vendorApiDocsPrefix, '/')) {
            throw new RuntimeException('PHAR must not ship vendor path-package Extension API docs entry: '.$rel);
        }
        if (!str_ends_with($rel, '.md')) {
            continue;
        }
        $isCanonical = false;
        foreach ($canonicalPrefixes as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                $isCanonical = true;
                break;
            }
        }
        if (!$isCanonical) {
            continue;
        }
        if (!isset($expected[$rel])) {
            throw new RuntimeException('PHAR contains unmarked/extra documentation file: '.$rel);
        }
    }

    if (isset($phar['internal-docs/settings.md'])) {
        throw new RuntimeException('PHAR must not contain legacy internal-docs projection');
    }
    echo '  phar bundled resources: ok ('.count($expected)." selected docs)\n";
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
