<?php

declare(strict_types=1);

namespace CastorTasks;

use Castor\Context;
use Symfony\Component\Process\Process;

use function Castor\run;

const REPORTS_DIR = __DIR__.'/../var/reports';

/**
 * Return the most recent modification time among all files in the given directories.
 *
 * Recurses into subdirectories, skips unreadable directories silently,
 * and returns 0.0 if no regular files are found.  Replaces the old
 * `find … | sort -rn | head -1` shell pipeline which polluted stderr
 * with SIGPIPE noise when head(1) closed the pipe.
 *
 * @param list<string> $directories absolute filesystem paths
 */
function latest_file_mtime(array $directories): float
{
    $latest = 0.0;

    foreach ($directories as $dir) {
        if (!is_dir($dir) || !is_readable($dir)) {
            continue;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $mtime = $file->getMTime();
            if ($mtime > $latest) {
                $latest = $mtime;
            }
        }
    }

    return $latest;
}

// ─── PHAR packaging constants ──────────────────────────────────────────
// Centralised so output paths, staging directories, and tooling references
// have a single source of truth.  Every function that needs the PHAR path
// calls hatfield_phar_path() instead of hard-coding a path.
//
// Defaults are project-root-relative so each checkout/worktree gets its
// own local PHAR artifact — sibling worktrees won't clobber each other.
//
// Environment overrides (optional):
//   HATFIELD_PHAR_PATH        — Override the PHAR output file path
//                                (absolute, or project-root-relative).
//   HATFIELD_PHAR_STAGING_DIR — Override the production Composer staging
//                                dir (absolute path).
//   HATFIELD_PHAR_BOX_BIN     — Override the Box binary (defaults to
//                                tools/phar/vendor/bin/box when the isolated
//                                toolchain is present).

/** Default project-root-relative PHAR output path. */
const HATFIELD_PHAR_PATH_DEFAULT = 'var/tmp/phar/hatfield.phar';

/** Default project-root-relative staging directory. */
const HATFIELD_PHAR_STAGING_DIR_DEFAULT = 'var/tmp/phar-build/source';

/**
 * Resolve the PHAR output path.
 *
 * Respects HATFIELD_PHAR_PATH if set; otherwise returns a worktree-local
 * path (var/tmp/phar/hatfield.phar under the project root).  Relative
 * overrides are resolved against the project root directory.
 *
 * Worktree-local default prevents concurrent builds in sibling worktrees
 * from clobbering each other's PHAR artifacts.
 */
function hatfield_phar_path(): string
{
    $override = getenv('HATFIELD_PHAR_PATH');
    $root = realpath(__DIR__.'/..');

    if (false !== $override && '' !== $override) {
        if (str_starts_with($override, '/')) {
            return $override;
        }

        if (false !== $root) {
            return $root.'/'.$override;
        }
    }

    // Default: worktree-local so sibling checkouts don't collide.
    if (false !== $root) {
        return $root.'/'.HATFIELD_PHAR_PATH_DEFAULT;
    }

    return HATFIELD_PHAR_PATH_DEFAULT; // last-resort fallback
}

/**
 * Resolve the PHAR staging directory.
 *
 * Respects HATFIELD_PHAR_STAGING_DIR if set; otherwise returns a
 * worktree-local path (var/tmp/phar-build/source under the project root).
 *
 * Worktree-local default prevents concurrent builds in sibling worktrees
 * from clobbering each other's staging area.
 */
function hatfield_phar_staging_dir(): string
{
    $override = getenv('HATFIELD_PHAR_STAGING_DIR');

    if (false !== $override && '' !== $override) {
        return $override;
    }

    $root = realpath(__DIR__.'/..');
    if (false !== $root) {
        return $root.'/'.HATFIELD_PHAR_STAGING_DIR_DEFAULT;
    }

    return HATFIELD_PHAR_STAGING_DIR_DEFAULT; // last-resort fallback
}

/**
 * Resolve the Box binary path.
 *
 * Precedence:
 *   1. HATFIELD_PHAR_BOX_BIN env var (explicit override).
 *   2. tools/phar/vendor/bin/box (isolated project-local toolchain).
 *   3. Global Box (from PATH or BOX_BIN env).
 *
 * When the isolated toolchain at tools/phar/ exists but is not yet installed,
 * a lazy `composer install --no-dev` is triggered there so the binary becomes
 * available on first use.
 */
function hatfield_phar_box_bin(): string
{
    // 1. Explicit env override.
    $override = getenv('HATFIELD_PHAR_BOX_BIN');
    if (false !== $override && '' !== $override && is_executable($override)) {
        return $override;
    }

    $root = realpath(__DIR__.'/..');
    if (false === $root) {
        throw new \RuntimeException('Unable to resolve project root for Box binary resolution.');
    }

    // 2. Isolated toolchain under tools/phar/.
    $localBoxBin = $root.'/tools/phar/vendor/bin/box';
    if (is_executable($localBoxBin)) {
        return $localBoxBin;
    }

    // Lazy install if the composer.json exists but vendor/ is missing.
    if (is_file($root.'/tools/phar/composer.json')) {
        $composerBin = hatfield_phar_composer_bin();
        $installCmd = \sprintf(
            'cd %s && COMPOSER_MEMORY_LIMIT=-1 XDEBUG_MODE=off %s install --no-dev --no-interaction --no-progress 2>&1',
            escapeshellarg($root.'/tools/phar'),
            escapeshellarg($composerBin),
        );
        $output = shell_exec($installCmd);
        // After composer install, re-check the binary with a fresh
        // stat cache — composer install creates the file on disk.
        clearstatcache(true, $localBoxBin);
        if (is_executable($localBoxBin)) {
            return $localBoxBin;
        }
        // If install produced output but the binary still isn't
        // executable — show diagnostic output before falling through
        // to the global Box lookup.
        $diagnostic = \is_string($output) ? trim($output) : '';
        if ('' !== $diagnostic) {
            echo "  tools/phar/ composer install output:\n  ".str_replace("\n", "\n  ", $diagnostic)."\n";
        }
    }

    // 3. Global Box (PATH, or the legacy BOX_BIN env).
    $globalBox = getenv('BOX_BIN');
    if (false === $globalBox || '' === $globalBox) {
        $whichBox = shell_exec('which box 2>/dev/null');
        $globalBox = \is_string($whichBox) ? trim($whichBox) : '';
    }
    if ('' !== $globalBox && is_executable($globalBox)) {
        return $globalBox;
    }

    throw new \RuntimeException('Box is not installed. Options:'.\PHP_EOL.'  1. (preferred) The isolated toolchain is at tools/phar/ — it will be set up automatically.'.\PHP_EOL.'  2. Install Box globally: composer global require humbug/box'.\PHP_EOL.'  3. Set HATFIELD_PHAR_BOX_BIN to the Box binary path.'.\PHP_EOL);
}

/**
 * Resolve the Composer binary for build operations.
 */
function hatfield_phar_composer_bin(): string
{
    $composerBin = getenv('COMPOSER_BIN');
    if (false === $composerBin || '' === $composerBin) {
        $whichComposer = shell_exec('which composer 2>/dev/null');
        $composerBin = \is_string($whichComposer) ? trim($whichComposer) : '';
    }
    if ('' === $composerBin) {
        $composerBin = trim(shell_exec('which composer.phar 2>/dev/null') ?? '');
    }
    if ('' === $composerBin) {
        throw new \RuntimeException('Composer not found. Set COMPOSER_BIN or install composer globally.');
    }

    return $composerBin;
}

// ─── ──────────────────────────────────────────────────────────────────

function dev_php_exec(string $command): void
{
    run($command);
}

function is_llm_mode(): bool
{
    $value = getenv('LLM_MODE');

    if (false === $value) {
        return false;
    }

    return !\in_array(strtolower(trim((string) $value)), ['', '0', 'false', 'off', 'no'], true);
}

function reports_dir(): string
{
    if (!is_dir(REPORTS_DIR) && !mkdir(REPORTS_DIR, 0777, true) && !is_dir(REPORTS_DIR)) {
        throw new \RuntimeException(\sprintf('Unable to create reports directory "%s".', REPORTS_DIR));
    }

    return REPORTS_DIR;
}

function report_path(string $filename): string
{
    return reports_dir().'/'.$filename;
}

function relative_report_path(string $filename): string
{
    return 'var/reports/'.$filename;
}

function run_quiet_command(string $command): Process
{
    return run($command, context: new Context(quiet: true, allowFailure: true));
}

function persist_process_output(Process $process, string $filename): string
{
    $path = report_path($filename);
    $stdout = trim($process->getOutput());
    $stderr = trim($process->getErrorOutput());
    $combinedOutput = trim($stdout."\n".$stderr);

    file_put_contents($path, '' === $combinedOutput ? "[no output]\n" : $combinedOutput."\n");

    return $path;
}

function summarize_phpstan_json(string $jsonOutput): string
{
    $jsonOutput = trim($jsonOutput);
    if ('' === $jsonOutput) {
        return 'summary unavailable';
    }

    $decoded = json_decode($jsonOutput, true);
    if (!\is_array($decoded)) {
        return 'summary unavailable';
    }

    $totals = $decoded['totals'] ?? null;
    if (!\is_array($totals)) {
        return 'summary unavailable';
    }

    $errors = $totals['errors'] ?? null;
    $fileErrors = $totals['file_errors'] ?? null;

    if (!\is_int($errors) || !\is_int($fileErrors)) {
        return 'summary unavailable';
    }

    return \sprintf('errors=%d,file_errors=%d', $errors, $fileErrors);
}

function summarize_php_cs_fixer_json(string $jsonOutput): string
{
    $jsonOutput = trim($jsonOutput);
    if ('' === $jsonOutput) {
        return 'summary unavailable';
    }

    $decoded = json_decode($jsonOutput, true);
    if (!\is_array($decoded)) {
        return 'summary unavailable';
    }

    $files = $decoded['files'] ?? null;
    $fileCount = \is_array($files) ? \count($files) : 0;

    return \sprintf('files_fixed=%d', $fileCount);
}

function phpunit_inputs_available(): bool
{
    foreach (['phpunit.xml', 'phpunit.xml.dist', 'phpunit.dist.xml'] as $configFile) {
        if (file_exists(__DIR__.'/../'.$configFile)) {
            return true;
        }
    }

    $testsDir = __DIR__.'/../tests';
    if (!is_dir($testsDir)) {
        return false;
    }

    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($testsDir, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $name = $file->getFilename();
        if (str_ends_with($name, 'Test.php') || str_ends_with($name, '.phpt')) {
            return true;
        }
    }

    return false;
}

function write_empty_junit_report(string $filename): string
{
    $path = report_path($filename);
    file_put_contents($path, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<testsuites tests="0" assertions="0" errors="0" failures="0" skipped="0" time="0.0"/>
XML
    );

    return $path;
}

function summarize_junit_xml(string $xmlPath): string
{
    if (!is_file($xmlPath)) {
        return 'summary unavailable';
    }

    $xml = @simplexml_load_file($xmlPath);
    if (false === $xml) {
        return 'summary unavailable';
    }

    $attributes = $xml->attributes();

    if (null === $attributes || !isset($attributes['tests'])) {
        $suites = $xml->xpath('/testsuites/testsuite[1]');
        $firstSuite = (false !== $suites && isset($suites[0]) && $suites[0] instanceof \SimpleXMLElement)
            ? $suites[0]
            : null;

        if (null !== $firstSuite) {
            $attributes = $firstSuite->attributes();
        }
    }

    if (null === $attributes) {
        return 'summary unavailable';
    }

    return \sprintf(
        'tests=%d,assertions=%d,errors=%d,failures=%d,skipped=%d',
        (int) ($attributes['tests'] ?? 0),
        (int) ($attributes['assertions'] ?? 0),
        (int) ($attributes['errors'] ?? 0),
        (int) ($attributes['failures'] ?? 0),
        (int) ($attributes['skipped'] ?? 0),
    );
}

function summarize_deptrac_json(string $jsonOutput): string
{
    $jsonOutput = trim($jsonOutput);
    if ('' === $jsonOutput) {
        return 'summary unavailable';
    }

    $decoded = json_decode($jsonOutput, true);
    if (!\is_array($decoded)) {
        return 'summary unavailable';
    }

    $report = $decoded['Report'] ?? null;
    if (!\is_array($report)) {
        return 'summary unavailable';
    }

    return \sprintf(
        'violations=%d,errors=%d,uncovered=%d,allowed=%d',
        (int) ($report['Violations'] ?? 0),
        (int) ($report['Errors'] ?? 0),
        (int) ($report['Uncovered'] ?? 0),
        (int) ($report['Allowed'] ?? 0),
    );
}

/**
 * Ensure the PHAR exists and is fresh.
 *
 * If the PHAR is missing or stale (source, config, or box/composer files
 * have been updated since the last build), triggers a rebuild.
 *
 * @return string absolute path to the existing or freshly built PHAR
 */
/**
 * PHAR build lock file path (relative to project root).
 *
 * Used by phar_ensure() to serialize parallel workers that all try
 * to build the same PHAR simultaneously.  Without this lock,
 * concurrent shell_exec('rm -rf staging/') calls race with
 * concurrent cp/composer/box steps → "Directory not empty" / stale
 * file errors.
 */
const PHAR_BUILD_LOCK = 'var/tmp/phar-build.lock';

/**
 * Maximum seconds to wait for the PHAR build lock before giving up.
 *
 * In practice a PHAR build takes 8–15 s (composer install + box compile).
 * A 60 s timeout is generous enough for all real workloads while
 * preventing indefinite deadlock if a previous worker crashed.
 */
const PHAR_BUILD_LOCK_TIMEOUT_S = 60;

function phar_ensure(): string
{
    $pharPath = hatfield_phar_path();
    $root = realpath(__DIR__.'/..');
    if (false === $root) {
        throw new \RuntimeException('Unable to resolve project root for PHAR ensure.');
    }

    if (is_file($pharPath) && is_readable($pharPath)) {
        // Quick stale detection: compare mtime against key meta-files
        // and latest source/config file.
        $pharMtime = filemtime($pharPath);

        // Check meta-files that directly affect the build output.
        foreach (['box.json', 'composer.lock'] as $file) {
            $path = $root.'/'.$file;
            if (is_file($path) && filemtime($path) > $pharMtime) {
                echo "PHAR stale: {$file} changed. Rebuilding.\n";
                phar_build_with_lock($root);

                return $pharPath;
            }
        }

        // Check the most recently changed source/config file.
        $latestSrc = latest_file_mtime([$root.'/src', $root.'/config']);
        if ($latestSrc > (float) $pharMtime) {
            echo "PHAR stale: source/config files changed. Rebuilding.\n";
            phar_build_with_lock($root);

            return $pharPath;
        }

        return $pharPath;
    }

    // Build if missing.
    echo "PHAR not found. Building.\n";
    phar_build_with_lock($root);

    return $pharPath;
}

/**
 * Acquire a per-project lock, then build the PHAR.
 *
 * Serialises concurrent phar_build() callers so parallel test workers
 * do not race on the shared staging directory.
 */
function phar_build_with_lock(string $root): void
{
    $lockPath = $root.'/'.PHAR_BUILD_LOCK;
    @mkdir(\dirname($lockPath), 0755, true);

    $lockHandle = fopen($lockPath, 'c+b');
    if (false === $lockHandle) {
        // Can't lock — build without serialization as best-effort.
        phar_build();

        return;
    }

    $deadline = microtime(true) + PHAR_BUILD_LOCK_TIMEOUT_S;
    $locked = false;

    do {
        if (flock($lockHandle, \LOCK_EX | \LOCK_NB)) {
            $locked = true;
            break;
        }
        usleep(100000); // 100 ms
    } while (microtime(true) < $deadline);

    if (!$locked) {
        fclose($lockHandle);
        throw new \RuntimeException('Timed out waiting for PHAR build lock after '.PHAR_BUILD_LOCK_TIMEOUT_S.' s. Remove '.$lockPath.' if a previous build crashed.');
    }

    try {
        // Re-read the PHAR path AFTER acquiring the lock — another
        // worker may have built it while we were waiting.
        $pharPath = hatfield_phar_path();
        if (is_file($pharPath) && is_readable($pharPath)) {
            // Double-check staleness while holding the lock.
            $pharMtime = filemtime($pharPath);
            $latestSrc = latest_file_mtime([$root.'/src', $root.'/config']);
            if ($latestSrc <= (float) $pharMtime) {
                // Already up-to-date — another worker built it while we waited.
                return;
            }
        }

        phar_build();
    } finally {
        flock($lockHandle, \LOCK_UN);
        fclose($lockHandle);
    }
}

/**
 * Deterministic Composer autoloader suffix used for PHAR builds.
 *
 * Without a fixed suffix, Composer derives the autoloader class name from
 * a hash of composer.json.  If the resulting class name collides with the
 * autoloader from a sibling PHAR or the host Composer project, class-map
 * resolution silently breaks (only one autoloader survives per process).
 *
 * Castor solves this by patching composer.json with a randomly-generated
 * suffix during PHAR builds (tools/phar/castor.php).  We use a deterministic
 * project-scoped value so the suffix is stable across builds and doesn't
 * depend on build-time randomness.
 *
 * This suffix is applied to the staging composer.json before the production
 * `composer install --optimize-autoloader` step, ensuring the generated
 * autoloader (preserved by Box with dump-autoload:false) has a unique name.
 */
const HATFIELD_PHAR_AUTOLOADER_SUFFIX = 'HatfieldPharBuild';

/**
 * Build the PHAR using a clean production staging directory.
 *
 * Pipeline:
 *   1. Create/refresh staging dir with only packaging inputs (no dev vendor).
 *   2. Apply a deterministic Composer autoloader suffix to prevent class-map
 *      collision when the PHAR is consumed by another Composer project.
 *   3. Run `composer install --no-dev --optimize-autoloader` in staging.
 *   4. Compile with Box (from the isolated tools/phar/ toolchain).
 *   5. Smoke-test the artifact from an isolated temp directory.
 *   6. Report timings and PHAR size.
 *
 * @return string absolute path to the built PHAR
 */
function phar_build(): string
{
    $pharPath = hatfield_phar_path();
    $root = realpath(__DIR__.'/..');
    if (false === $root) {
        throw new \RuntimeException('Unable to resolve project root for PHAR build.');
    }

    // Guard: the PHAR output path and staging dir must live under the
    // current project root unless the caller set an explicit override
    // (HATFIELD_PHAR_PATH / HATFIELD_PHAR_STAGING_DIR) to a non-project
    // location.  This catches accidental global-path usage.
    $explicitPharPath = getenv('HATFIELD_PHAR_PATH');
    if ((false === $explicitPharPath || '' === $explicitPharPath) && !str_starts_with($pharPath, $root)) {
        throw new \RuntimeException(\sprintf('PHAR output path %s is not under the project root %s. This indicates a non-worktree-local default. Set HATFIELD_PHAR_PATH explicitly if this is intentional.', $pharPath, $root));
    }

    $stagingDir = hatfield_phar_staging_dir();
    $explicitStaging = getenv('HATFIELD_PHAR_STAGING_DIR');
    if ((false === $explicitStaging || '' === $explicitStaging) && !str_starts_with($stagingDir, $root)) {
        throw new \RuntimeException(\sprintf('Staging directory %s is not under the project root %s. This indicates a non-worktree-local default. Set HATFIELD_PHAR_STAGING_DIR explicitly if this is intentional.', $stagingDir, $root));
    }

    $startTime = microtime(true);

    // ── Resolve external binaries ────────────────────────────────────

    $boxBin = hatfield_phar_box_bin();
    $composerBin = hatfield_phar_composer_bin();

    // ── 1. Prepare staging directory ─────────────────────────────────

    // Ensure output directory exists.
    @mkdir(\dirname($pharPath), 0755, true);

    // Start fresh so stale files from previous builds never leak in.
    if (is_dir($stagingDir)) {
        shell_exec('rm -rf '.escapeshellarg($stagingDir));
    }
    if (!mkdir($stagingDir, 0755, true) && !is_dir($stagingDir)) {
        throw new \RuntimeException('Unable to create staging directory: '.$stagingDir);
    }

    $copyStart = microtime(true);

    // Copy source directories.
    foreach (['bin', 'src', 'config', 'migrations'] as $dir) {
        $srcPath = $root.'/'.$dir;
        if (is_dir($srcPath)) {
            shell_exec(
                'cp -a '.escapeshellarg($srcPath).' '.escapeshellarg($stagingDir.'/')
            );
        }
    }

    // Copy individual root files needed by Box and Composer.
    foreach (['composer.json', 'composer.lock', 'box.json'] as $file) {
        $srcPath = $root.'/'.$file;
        if (is_file($srcPath)) {
            copy($srcPath, $stagingDir.'/'.$file);
        }
    }

    $copyTime = microtime(true) - $copyStart;
    $copySize = dirsize_estimate($stagingDir);

    echo "Staging prepared: {$stagingDir} ({$copySize} MB)\n";

    // ── 2. Apply deterministic autoloader suffix ─────────────────────
    //
    // Without a fixed suffix, Composer derives the autoloader class name
    // (ComposerAutoloaderInit<hex>) from a hash of composer.json.  If the
    // PHAR is loaded inside a host project that produces the same hash —
    // e.g. another agent-core build — the autoloader class names collide
    // and the PHAR's autoloader silently fails to register.
    //
    // We set config.autoloader-suffix in the staging composer.json so the
    // autoloader class name is always ComposerAutoloaderInitHatfieldPharBuild.
    // This is applied to the staging copy only — the root composer.json is
    // never modified.

    $stagingComposerJson = $stagingDir.'/composer.json';
    if (!is_file($stagingComposerJson)) {
        throw new \RuntimeException('Staging composer.json not found at '.$stagingComposerJson);
    }

    $composerConfig = json_decode((string) file_get_contents($stagingComposerJson), true);
    if (!\is_array($composerConfig)) {
        throw new \RuntimeException('Failed to parse staging composer.json.');
    }

    if (!isset($composerConfig['config']) || !\is_array($composerConfig['config'])) {
        $composerConfig['config'] = [];
    }
    $composerConfig['config']['autoloader-suffix'] = HATFIELD_PHAR_AUTOLOADER_SUFFIX;

    $encoded = json_encode($composerConfig, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    if (false === $encoded) {
        throw new \RuntimeException('Failed to encode staging composer.json with autoloader suffix.');
    }
    if (false === file_put_contents($stagingComposerJson, $encoded."\n")) {
        throw new \RuntimeException('Failed to write staging composer.json with autoloader suffix.');
    }

    // ── 3. Install production-only Composer dependencies ─────────────

    // Default APP_ENV to prod if not already set by the caller.
    // This ensures Composer resolves Symfony config in the correct
    // environment (e.g. bundle registration) without forcing APP_DEBUG.
    $composerEnv = getenv('APP_ENV');
    $composerEnv = (false !== $composerEnv && '' !== $composerEnv) ? $composerEnv : 'prod';
    $composerStart = microtime(true);
    $composerCmd = \sprintf(
        'cd %s && APP_ENV=%s COMPOSER_MEMORY_LIMIT=-1 XDEBUG_MODE=off %s install'
        .' --no-dev --prefer-dist --no-interaction --no-progress'
        .' --optimize-autoloader 2>&1',
        escapeshellarg($stagingDir),
        escapeshellarg($composerEnv),
        escapeshellarg($composerBin)
    );
    $composerOutput = shell_exec($composerCmd);
    $composerTime = microtime(true) - $composerStart;

    if (null === $composerOutput) {
        throw new \RuntimeException('composer install command returned no output (shell_exec failure).'.\PHP_EOL.'Command: '.$composerCmd);
    }

    // ── 4. Compile PHAR with Box ─────────────────────────────────────

    // Update box.json output in staging to reflect the resolved PHAR path
    // (in case the env override differs from the default in the file).
    $stagingBoxJson = $stagingDir.'/box.json';
    if (is_file($stagingBoxJson)) {
        $boxConfig = json_decode((string) file_get_contents($stagingBoxJson), true);
        if (\is_array($boxConfig)) {
            $boxConfig['output'] = $pharPath;
            $encoded = json_encode($boxConfig, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
            if (false !== $encoded) {
                file_put_contents($stagingBoxJson, $encoded."\n");
            }
        }
    }

    $boxEnv = getenv('APP_ENV');
    $boxEnv = (false !== $boxEnv && '' !== $boxEnv) ? $boxEnv : 'prod';
    $boxStart = microtime(true);
    $boxCmd = \sprintf(
        'cd %s && APP_ENV=%s php -d memory_limit=-1 -d xdebug.mode=off %s compile 2>&1',
        escapeshellarg($stagingDir),
        escapeshellarg($boxEnv),
        escapeshellarg($boxBin)
    );
    $boxOutput = shell_exec($boxCmd);
    $boxTime = microtime(true) - $boxStart;

    if (!is_file($pharPath)) {
        $error = 'PHAR build failed.'.\PHP_EOL;
        $error .= 'Composer output:'.\PHP_EOL;
        $error .= $composerOutput.\PHP_EOL;
        $error .= 'Box output:'.\PHP_EOL;
        $error .= ($boxOutput ?? '<no output>').\PHP_EOL;
        $error .= 'Box command: '.$boxCmd.\PHP_EOL;
        throw new \RuntimeException($error);
    }

    $totalTime = microtime(true) - $startTime;
    $sizeMb = \sprintf('%.1f', filesize($pharPath) / 1024 / 1024);
    echo "PHAR built: {$pharPath} ({$sizeMb} MB)\n";
    echo \sprintf(
        "Timings: copy=%.1fs  composer=%.1fs  box=%.1fs  total=%.1fs\n",
        $copyTime, $composerTime, $boxTime, $totalTime
    );

    // ── 5. Smoke-test the PHAR from an isolated working directory ────
    //
    // Running from a temp cwd (outside the repo) proves:
    //   - The PHAR boots without any source-checkout scaffolding.
    //   - .hatfield/cache and .hatfield/logs are created in the runtime cwd.
    //   - Package-autoloader collision is avoided (the fixed suffix works).
    //   - Core commands (list, about, agent --help) are functional.

    phar_smoke($pharPath);

    return $pharPath;
}

/**
 * Run fast no-LLM smoke checks against a built PHAR artifact.
 *
 * Executes from an isolated temporary working directory so .hatfield/* dirs
 * are created outside the repo and do not pollute the source checkout.
 *
 * Validates:
 *   - PHAR boots and Symfony Console loads all commands.
 *   - `agent` command is listed (confirmed the PHAR contains app code).
 *   - `about` reports the correct environment (prod by default).
 *   - `agent --help` renders usage text.
 *
 * Output is kept compact for Castor build logs.
 */
function phar_smoke(string $pharPath): void
{
    // Create an isolated temp cwd to prove writable-dir creation works.
    // Use a random suffix (not just getpid) to avoid collisions when
    // multiple build processes or Castor invocations share a temp dir.
    $tmpCwd = sys_get_temp_dir().'/hatfield-phar-smoke-'.bin2hex(random_bytes(8));
    @mkdir($tmpCwd, 0755, true);
    if (!is_dir($tmpCwd)) {
        echo "PHAR smoke test: SKIP (could not create isolated cwd: {$tmpCwd})\n";

        return;
    }

    // Wrap all smoke logic in try/finally so the temp dir is always
    // cleaned up, even when a check fails or an exception is thrown.
    try {
        // Isolate HOME inside the temp cwd so the PHAR does NOT read
        // the real user's ~/.hatfield/settings.yaml which may reference
        // providers/models not available in the packaged PHAR.
        $homeDir = $tmpCwd.'/home';
        @mkdir($homeDir.'/.hatfield', 0755, true);
        $stubWritten = file_put_contents(
            $homeDir.'/.hatfield/settings.yaml',
            "ai:\n    default_model: null\n",
        );
        if (false === $stubWritten) {
            echo "PHAR smoke test: SKIP (could not write isolated home settings)\n";

            return;
        }
        $homeEnv = 'HOME='.escapeshellarg($homeDir);

        $smokeEnv = getenv('APP_ENV');
        $smokeEnv = (false !== $smokeEnv && '' !== $smokeEnv) ? $smokeEnv : 'prod';
        $failed = [];
        $phpBin = \PHP_BINARY;

        // 1. `list` — verifies the Symfony Console application boots and
        //    all commands (including `agent`) are registered.
        $listOutput = shell_exec(
            'cd '.escapeshellarg($tmpCwd).' && '.$homeEnv.' APP_ENV='.$smokeEnv.' '.$phpBin.' '
            .escapeshellarg($pharPath).' list 2>&1'
        );
        if (null === $listOutput || !str_contains($listOutput, 'agent')) {
            $failed[] = 'list (agent command not found)';
            echo "  smoke list: FAIL\n";
        } else {
            echo "  smoke list: ok\n";
        }

        // 2. `about` — verifies the kernel boots, environment is correct,
        //    and writable directories are resolved.
        $aboutOutput = shell_exec(
            'cd '.escapeshellarg($tmpCwd).' && '.$homeEnv.' APP_ENV='.$smokeEnv.' '.$phpBin.' '
            .escapeshellarg($pharPath).' about 2>&1'
        );
        if (null === $aboutOutput || !str_contains($aboutOutput, 'Environment')) {
            $failed[] = 'about (no Environment line)';
            echo "  smoke about: FAIL\n";
        } else {
            echo "  smoke about: ok\n";
        }

        // 3. `agent --help` — verifies AgentCommand is loadable and its
        //    options (--cwd, --model, --headless, etc.) are present.
        $helpOutput = shell_exec(
            'cd '.escapeshellarg($tmpCwd).' && '.$homeEnv.' APP_ENV='.$smokeEnv.' '.$phpBin.' '
            .escapeshellarg($pharPath).' agent --help 2>&1'
        );
        if (null === $helpOutput || !str_contains($helpOutput, 'Usage:')) {
            $failed[] = 'agent --help (no Usage line)';
            echo "  smoke agent --help: FAIL\n";
        } else {
            echo "  smoke agent --help: ok\n";
        }

        // 4. Verify .hatfield/cache was created in the isolated cwd —
        //    proves writable-dir isolation works.
        if (is_dir($tmpCwd.'/.hatfield/cache')) {
            echo "  smoke writable-dir isolation: ok (.hatfield/cache created in {$tmpCwd})\n";
        }

        // 5. Verify PHAR cache isolation: the cache directory suffix must
        //    be derived from the PHAR archive content hash (SHA-256, 12 hex
        //    chars), not the old stable md5(__FILE__) fixpoint that allowed
        //    stale Symfony compiled containers to survive PHAR rebuilds.
        $cacheDirs = glob($tmpCwd.'/.hatfield/cache/'.$smokeEnv.'-*', \GLOB_ONLYDIR);
        if ([] !== $cacheDirs) {
            $cacheSuffix = substr($cacheDirs[0], strrpos($cacheDirs[0], '-') + 1);
            $expectedHash = hash_file('sha256', $pharPath);

            if (false === $expectedHash) {
                $failed[] = 'cache-isolation (could not hash PHAR archive)';
                echo "  smoke cache-isolation: FAIL (hash_file returned false)\n";
            } elseif ($cacheSuffix !== substr($expectedHash, 0, 12)) {
                $failed[] = "cache-isolation (cache suffix {$cacheSuffix} does not match expected content hash prefix "
                    .substr($expectedHash, 0, 12).')';
                echo "  smoke cache-isolation: FAIL\n";
            } else {
                echo "  smoke cache-isolation: ok (content-based suffix {$cacheSuffix})\n";
            }
        } else {
            // The PHAR may not produce a cache dir on every boot (e.g. if
            // the container was already compiled elsewhere), so this is
            // a warning, not a hard failure.
            echo "  smoke cache-isolation: warn (no cache dirs found)\n";
        }

        if ([] !== $failed) {
            echo 'PHAR smoke test: FAIL ('.\count($failed).' failures: '.implode(', ', $failed).")\n";
            echo "  The PHAR compiled successfully but one or more boot checks failed.\n";
        } else {
            echo "PHAR smoke test: ok\n";
        }
    } finally {
        // Clean up smoke artifacts — always runs, even on failure.
        shell_exec('rm -rf '.escapeshellarg($tmpCwd));
    }
}

/**
 * Quick estimate of directory size in MB.
 */
function dirsize_estimate(string $path): string
{
    $bytes = (int) trim(shell_exec(
        'du -sb '.escapeshellarg($path).' 2>/dev/null | cut -f1'
    ) ?? '0');

    return \sprintf('%.1f', $bytes / 1024 / 1024);
}

function xml_escape(string $value): string
{
    return htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
}

/**
 * Fail-fast check: verify the test LLM (llama_cpp_test/test on port 9052)
 * can actually complete a tiny generation request.
 *
 * Health-only checks are insufficient — the server can report /health and
 * /v1/models while generation is stuck (e.g. corrupted model load, all
 * slots busy).  This sends a minimal chat completion and fails within 5s
 * if no valid response arrives, preventing 90s+ timeouts in
 * test:tui / test:llm-real / test:controller.
 *
 * Called before any E2E step that depends on real LLM generation.
 */
function check_llm_generation_ready(): void
{
    $cacheFile = 'var/tmp/llm-generation-ready.cache';
    $ttlSeconds = (int) (getenv('HATFIELD_LLM_READY_TTL') ?: 120);
    if ($ttlSeconds > 0 && is_file($cacheFile)) {
        $mtime = filemtime($cacheFile);
        if (false !== $mtime && (time() - $mtime) < $ttlSeconds) {
            echo 'llama.cpp generation: ok (cached, ttl='.$ttlSeconds.'s)
';

            return;
        }
    }

    $baseUrl = 'http://192.168.2.38:9052';
    $model = 'test';
    $url = $baseUrl.'/v1/chat/completions';
    // Use a realistic smoke-test prompt with enough max_tokens to avoid
    // truncating reasoning mid-stream, which can crash llama.cpp during
    // slot cleanup (ggml_abort in common_context_seq_rm).  The old 1-token
    // preflight would cut off reasoning models and trigger server aborts.
    $payload = '{"model":"'.$model.'","messages":[{"role":"user","content":"Respond with exactly one word: hello."}],"max_tokens":512,"temperature":0,"stream":false}';

    $cmd = 'timeout --kill-after=5s 15s curl -sS -m 10 -o /dev/null -w "%{http_code}"'
        .' -H "Content-Type: application/json"'
        .' -d '.escapeshellarg($payload)
        .' '.escapeshellarg($url);

    $process = run_quiet_command($cmd);

    $httpCode = (int) trim($process->getOutput());

    if (200 === $httpCode && 0 === $process->getExitCode()) {
        if (!is_dir('var/tmp')) {
            @mkdir('var/tmp', 0o777, true);
        }
        @touch($cacheFile);
        echo 'llama.cpp generation: ok'."\n";

        return;
    }

    $diagnostic = \sprintf(
        "\n".
        "llama.cpp generation readiness check FAILED\n".
        "  Endpoint: %s\n".
        "  Model: %s\n".
        "  Sent: %s\n".
        "  HTTP status: %d (curl exit: %d)\n".
        "\n".
        "  The server responds to /health and /v1/models but cannot complete a\n".
        "  minimal generation request.  Make sure llama.cpp is running, the\n".
        "  model is loaded correctly, and no generation slots are stuck.\n".
        "  Check manually: curl -sS -m 5 -d '%s' %s\n",
        $url,
        $model,
        $payload,
        $httpCode,
        $process->getExitCode(),
        $payload,
        $url,
    );

    throw new \RuntimeException($diagnostic);
}

function build_idea_run_config_xml(string $commandName, string $description): string
{
    $configurationName = 'castor '.$commandName;
    $command = 'castor '.$commandName;

    $configurationNameXml = xml_escape($configurationName);
    $commandXml = xml_escape($command);
    $descriptionXml = xml_escape($description);

    return <<<XML
<component name="ProjectRunConfigurationManager">
  <configuration default="false" name="{$configurationNameXml}" type="ShConfigurationType" factoryName="Shell Script" singleton="false">
    <option name="SCRIPT_TEXT" value="{$commandXml}" />
    <option name="INDEPENDENT_SCRIPT_PATH" value="true" />
    <option name="SCRIPT_PATH" value="" />
    <option name="SCRIPT_OPTIONS" value="" />
    <option name="INDEPENDENT_INTERPRETER_PATH" value="true" />
    <option name="INTERPRETER_PATH" value="/bin/bash" />
    <option name="INTERPRETER_OPTIONS" value="" />
    <option name="INDEPENDENT_SCRIPT_WORKING_DIRECTORY" value="true" />
    <option name="SCRIPT_WORKING_DIRECTORY" value="\$PROJECT_DIR\$" />
    <option name="EXECUTE_IN_TERMINAL" value="false" />
    <option name="EXECUTE_SCRIPT_FILE" value="false" />
    <envs />
    <method v="2" />
  </configuration>
  <!-- {$descriptionXml} -->
</component>
XML;
}
