<?php

declare(strict_types=1);

namespace CastorTasks;

use Castor\Context;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Process\Process;

use function Castor\run;

const REPORTS_DIR = __DIR__.'/../var/reports';

/**
 * Sanitize a QA run id segment for filesystem and env use.
 */
function sanitize_qa_run_id_segment(string $value): string
{
    $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '', $value) ?? '';

    return '' !== $sanitized ? $sanitized : 'qa-run';
}

/**
 * Project root directory for Castor helpers.
 */
function project_root_dir(): string
{
    return false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..';
}

/**
 * Default path to the personal Bubblewrap launcher (~/.local bin layout).
 */
function default_pi_bwrap_script_path(): string
{
    $home = getenv('HOME');
    if (false === $home || '' === $home) {
        return '/bin/pi-bwrap';
    }

    return rtrim($home, '/').'/bin/pi-bwrap';
}

/**
 * Resolved pi-bwrap executable path (override with HATFIELD_PI_BWRAP).
 */
function pi_bwrap_script_path(): string
{
    $override = getenv('HATFIELD_PI_BWRAP');
    if (false !== $override && '' !== trim($override)) {
        return $override;
    }

    return default_pi_bwrap_script_path();
}

/**
 * Whether agent-launch Castor tasks should skip Bubblewrap (HATFIELD_BWRAP=0).
 */
function pi_bwrap_disabled_by_env(): bool
{
    $flag = getenv('HATFIELD_BWRAP');
    if (false === $flag) {
        return false;
    }

    return \in_array(strtolower(trim($flag)), ['0', 'false', 'no', 'off'], true);
}

/**
 * True when the current Castor process was re-execed under pi-bwrap (recursion guard).
 */
function pi_bwrap_already_inside(): bool
{
    $flag = getenv('HATFIELD_INSIDE_PI_BWRAP');
    if (false === $flag) {
        return false;
    }

    return \in_array(strtolower(trim($flag)), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Whether ~/bin/pi-bwrap (or override) exists and is executable.
 */
function pi_bwrap_script_available(): bool
{
    $path = pi_bwrap_script_path();

    return is_file($path) && is_executable($path);
}

/**
 * Whether run:agent / run:agent-capture should re-exec Castor under pi-bwrap before direct TUI launch.
 */
function should_auto_wrap_agent_castor_task(): bool
{
    if (pi_bwrap_disabled_by_env() || pi_bwrap_already_inside()) {
        return false;
    }

    return pi_bwrap_script_available();
}

/**
 * Shell-escape argv pieces for passthru()/exec() (one quoted token per argument).
 *
 * @param list<string> $argv
 */
function shell_quote_argv(array $argv): string
{
    return implode(' ', array_map(static fn (string $part): string => escapeshellarg($part), $argv));
}

/**
 * Castor CLI used to re-exec tasks (global `castor` PHAR, not raw project castor.php).
 *
 * Override with HATFIELD_CASTOR_EXECUTABLE. When unset, prefers $_SERVER['argv'][0] when it
 * looks like a castor entrypoint, then ~/.local/bin/castor, then `castor` on PATH.
 */
function castor_cli_executable(): ?string
{
    $override = getenv('HATFIELD_CASTOR_EXECUTABLE');
    if (false !== $override && '' !== trim($override)) {
        $path = trim($override);

        return is_file($path) && is_executable($path) ? $path : null;
    }

    $argv0 = $_SERVER['argv'][0] ?? '';
    if ('' !== $argv0) {
        $resolved = realpath($argv0);
        if (false !== $resolved && is_executable($resolved) && (str_ends_with($resolved, '/castor') || str_ends_with($resolved, 'castor.php'))) {
            return $resolved;
        }
    }

    $home = getenv('HOME');
    if (false !== $home && '' !== $home) {
        $local = rtrim($home, '/').'/.local/bin/castor';
        if (is_file($local) && is_executable($local)) {
            return $local;
        }
    }

    $which = trim((string) shell_exec('command -v castor 2>/dev/null'));
    if ('' !== $which && is_executable($which)) {
        return $which;
    }

    return null;
}

/**
 * Build the passthru command to re-exec a Castor task under pi-bwrap, or null when wrapping is skipped.
 *
 * pi-bwrap ends with `bwrap ... -- "$@"` (no shell), so each logical argv must be a separate token:
 * `wrapper env HATFIELD_INSIDE_PI_BWRAP=1 <castor> <taskName>`.
 *
 * @return list<string>|null argv vector when wrapping should happen (for tests); null otherwise
 */
function build_pi_bwrap_castor_reexec_argv(string $taskName): ?array
{
    if (!should_auto_wrap_agent_castor_task()) {
        return null;
    }

    $castorBin = castor_cli_executable();
    if (null === $castorBin) {
        return null;
    }

    return [
        pi_bwrap_script_path(),
        'env',
        'HATFIELD_INSIDE_PI_BWRAP=1',
        $castorBin,
        $taskName,
    ];
}

/**
 * @see build_pi_bwrap_castor_reexec_argv()
 */
function build_pi_bwrap_castor_reexec_command(string $taskName): ?string
{
    $argv = build_pi_bwrap_castor_reexec_argv($taskName);
    if (null === $argv) {
        return null;
    }

    return shell_quote_argv($argv);
}

/**
 * Re-exec the current Castor task under pi-bwrap when should_auto_wrap_agent_castor_task().
 *
 * Sets HATFIELD_INSIDE_PI_BWRAP=1 in the child via env(1) so nested calls do not wrap again.
 * Exits with the child status when re-exec happens.
 */
function maybe_reexec_castor_task_under_pi_bwrap(string $taskName): void
{
    $command = build_pi_bwrap_castor_reexec_command($taskName);
    if (null === $command) {
        return;
    }

    passthru($command, $exitCode);
    exit($exitCode);
}

/**
 * Initialize per-invocation QA resources for castor check().
 *
 * Sets process env via putenv() so command builders and child shells inherit
 * run-scoped report/tmp/cache/DB paths.  Returns the generated run id.
 */
function initialize_qa_check_run(): string
{
    $random = bin2hex(random_bytes(4));
    $id = sanitize_qa_run_id_segment(\sprintf('qa-%s-%d-%s', date('Ymd-His'), getmypid(), $random));

    $reportsRel = 'var/reports/'.$id;
    $tmpRel = 'var/tmp/'.$id;
    $cacheRel = '.hatfield/cache-'.$id;
    $dbFile = 'app_test-'.$id.'.sqlite';
    $transportDbFile = 'messenger_transport_test-'.$id.'.sqlite';

    $vars = [
        'HATFIELD_QA_RUN_ID' => $id,
        'HATFIELD_QA_REPORTS_DIR' => $reportsRel,
        'HATFIELD_QA_TMP_DIR' => $tmpRel,
        'HATFIELD_CACHE_DIR' => $cacheRel,
        'HATFIELD_TEST_DATABASE_PATH' => $dbFile,
        'HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH' => $transportDbFile,
    ];

    foreach ($vars as $name => $value) {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    $projectRoot = project_root_dir();
    foreach ([$reportsRel, $tmpRel, $cacheRel, 'var/test'] as $relative) {
        $path = $projectRoot.'/'.$relative;
        if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
            throw new \RuntimeException(\sprintf('Unable to create QA directory "%s".', $path));
        }
    }

    return $id;
}

/**
 * Minimal personal Hatfield settings for QA/test Symfony kernel subprocesses.
 *
 * Prevents developer ~/.hatfield/settings.yaml (invalid default_model, secrets)
 * from breaking castor test/check migrations and PHPUnit/ParaTest workers.
 * Project .hatfield/settings.yaml in the worktree cwd is still loaded as layer 3.
 */
function qa_test_home_settings_contents(): string
{
    return "ai:\n    default_model: null\n";
}

/**
 * Absolute path to an isolated HOME directory for this Castor invocation or check run.
 */
function qa_test_home_dir(): string
{
    static $resolved = null;
    if (null !== $resolved) {
        return $resolved;
    }

    $projectRoot = project_root_dir();
    $runId = getenv('HATFIELD_QA_RUN_ID');
    if (false !== $runId && '' !== trim((string) $runId)) {
        $segment = sanitize_qa_run_id_segment((string) $runId);
        $resolved = $projectRoot.'/var/tmp/'.$segment.'/qa-home';
    } else {
        $resolved = $projectRoot.'/var/tmp/qa-home/pid-'.getmypid();
    }

    $hatfieldDir = $resolved.'/.hatfield';
    if (!is_dir($hatfieldDir) && !mkdir($hatfieldDir, 0777, true) && !is_dir($hatfieldDir)) {
        throw new \RuntimeException(\sprintf('Unable to create QA test HOME directory "%s".', $hatfieldDir));
    }

    $settingsPath = $hatfieldDir.'/settings.yaml';
    $contents = qa_test_home_settings_contents();
    if (!is_file($settingsPath) || file_get_contents($settingsPath) !== $contents) {
        if (false === file_put_contents($settingsPath, $contents)) {
            throw new \RuntimeException(\sprintf('Unable to write QA test HOME settings at "%s".', $settingsPath));
        }
    }

    putenv('HATFIELD_QA_TEST_HOME='.$resolved);
    $_ENV['HATFIELD_QA_TEST_HOME'] = $resolved;
    $_SERVER['HATFIELD_QA_TEST_HOME'] = $resolved;

    return $resolved;
}

/**
 * Shell prefix exporting isolated HOME for subprocesses that boot the test kernel.
 */
function qa_test_home_shell_prefix(): string
{
    $home = qa_test_home_dir();

    return 'HOME='.escapeshellarg($home).' HATFIELD_QA_TEST_HOME='.escapeshellarg($home);
}

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
    $custom = getenv('HATFIELD_QA_REPORTS_DIR');
    if (false !== $custom && '' !== trim((string) $custom)) {
        $relative = ltrim((string) $custom, '/');
        $dir = project_root_dir().'/'.$relative;
    } else {
        $dir = REPORTS_DIR;
    }

    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new \RuntimeException(\sprintf('Unable to create reports directory "%s".', $dir));
    }

    return $dir;
}

function report_path(string $filename): string
{
    return reports_dir().'/'.$filename;
}

function relative_report_path(string $filename): string
{
    $custom = getenv('HATFIELD_QA_REPORTS_DIR');
    if (false !== $custom && '' !== trim((string) $custom)) {
        return rtrim((string) $custom, '/').'/'.$filename;
    }

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

// ─── Full QA gate (castor check) cross-worktree lock ───────────────────

/** Default maximum wait to acquire the full-check Symfony Lock (seconds). */
const CASTOR_CHECK_LOCK_ACQUIRE_TIMEOUT_S = 60;

/** Heartbeat interval while waiting for another check (seconds). */
const CASTOR_CHECK_LOCK_WAIT_HEARTBEAT_S = 15;

/**
 * Resolved lock acquire timeout for `castor check` (seconds).
 *
 * Override with `HATFIELD_CASTOR_CHECK_LOCK_TIMEOUT` (positive number, max 3600).
 */
function castor_check_lock_acquire_timeout_seconds(): float
{
    $raw = getenv('HATFIELD_CASTOR_CHECK_LOCK_TIMEOUT');
    if (false === $raw || '' === trim((string) $raw)) {
        return (float) CASTOR_CHECK_LOCK_ACQUIRE_TIMEOUT_S;
    }
    if (!is_numeric($raw)) {
        throw new \RuntimeException('HATFIELD_CASTOR_CHECK_LOCK_TIMEOUT must be a positive number of seconds (got: '.$raw.')');
    }
    $seconds = (float) $raw;
    if ($seconds <= 0.0 || $seconds > 3600.0) {
        throw new \RuntimeException('HATFIELD_CASTOR_CHECK_LOCK_TIMEOUT must be between 0 (exclusive) and 3600 (got: '.$raw.')');
    }

    return $seconds;
}

/**
 * Build a failure message when the castor check lock cannot be acquired in time.
 */
function format_castor_check_lock_acquire_timeout_message(
    string $projectRoot,
    string $resource,
    string $lockDir,
    float $timeoutSeconds,
    float $elapsedSeconds,
): string {
    $lines = [
        \sprintf(
            'castor check: failed to acquire Symfony Lock within %.0fs (elapsed %.1fs, pid %d).',
            $timeoutSeconds,
            $elapsedSeconds,
            getmypid()
        ),
        '  lock resource: '.$resource,
        '  lock directory: '.$lockDir,
    ];
    $meta = read_castor_check_lock_meta($projectRoot);
    if (null !== $meta) {
        $lines[] = '  holder metadata (may be stale if the holder crashed without releasing):';
        $lines[] = '    pid: '.($meta['pid'] ?? '?');
        $lines[] = '    started_at: '.($meta['started_at'] ?? '?');
        $lines[] = '    cwd: '.($meta['cwd'] ?? '?');
        $lines[] = '    qa_run_id: '.('' !== ($meta['qa_run_id'] ?? '') ? $meta['qa_run_id'] : '(none)');
        $lines[] = '    project_root: '.($meta['project_root'] ?? '?');
        $lines[] = '    repo_identity: '.($meta['repo_identity'] ?? '?');
    } else {
        $lines[] = '  holder metadata: (none — lock file may exist without meta JSON)';
    }
    $lines[] = 'Another full `castor check` for this repository may still be running (including in a sibling worktree).';
    $lines[] = 'Wait and retry, or inspect the holder process/metadata above. Do not auto-kill processes from this gate.';
    $lines[] = 'Optional manual listing: `castor clean:cleanup:workers:list` (current-user QA workers only; never signal root-owned processes).';

    return implode("\n", $lines);
}

/**
 * Whether full `castor check` should acquire the shared repository lock.
 *
 * Set `HATFIELD_CASTOR_CHECK_LOCK=0` to disable (stress testing only).
 */
function castor_check_lock_enabled(): bool
{
    $raw = getenv('HATFIELD_CASTOR_CHECK_LOCK');
    if (false === $raw) {
        return true;
    }
    $normalized = strtolower(trim((string) $raw));

    return !\in_array($normalized, ['0', 'false', 'no', 'off'], true);
}

/**
 * Stable identity for sibling worktrees of the same git repository.
 *
 * Override with `HATFIELD_CASTOR_CHECK_LOCK_IDENTITY` (tests / smoke only).
 */
function castor_check_repo_lock_identity(string $projectRoot): string
{
    $override = getenv('HATFIELD_CASTOR_CHECK_LOCK_IDENTITY');
    if (false !== $override && '' !== trim((string) $override)) {
        return trim((string) $override);
    }

    $gitCommon = trim((string) shell_exec(
        'git -C '.escapeshellarg($projectRoot).' rev-parse --git-common-dir 2>/dev/null'
    ));
    if ('' !== $gitCommon) {
        if (!str_starts_with($gitCommon, '/')) {
            $gitCommon = rtrim($projectRoot, '/').'/'.$gitCommon;
        }
        $resolved = realpath($gitCommon);
        if (false !== $resolved) {
            return $resolved;
        }

        return $gitCommon;
    }

    $rootReal = realpath($projectRoot);

    return false !== $rootReal ? $rootReal : $projectRoot;
}

function castor_check_lock_directory(): string
{
    $runtime = getenv('XDG_RUNTIME_DIR');
    if (false !== $runtime && '' !== trim((string) $runtime)) {
        $dir = rtrim((string) $runtime, '/').'/hatfield/castor-check';
        if (@mkdir($dir, 0700, true) || is_dir($dir)) {
            return $dir;
        }
    }

    $fallback = rtrim(sys_get_temp_dir(), '/').'/hatfield-castor-check-'.(string) getmyuid();
    if (!is_dir($fallback) && !mkdir($fallback, 0700, true) && !is_dir($fallback)) {
        throw new \RuntimeException('Unable to create castor check lock directory at '.$fallback);
    }

    return $fallback;
}

function castor_check_lock_resource_name(string $projectRoot): string
{
    $identity = castor_check_repo_lock_identity($projectRoot);

    return 'castor-check-'.hash('sha256', $identity);
}

function create_castor_check_lock_factory(): LockFactory
{
    return new LockFactory(new FlockStore(castor_check_lock_directory()));
}

function castor_check_lock_meta_path(string $projectRoot): string
{
    $identity = castor_check_repo_lock_identity($projectRoot);

    return castor_check_lock_directory().'/castor-check-meta-'.hash('sha256', $identity).'.json';
}

/**
 * @return array{pid: int, started_at: string, cwd: string, project_root: string, repo_identity: string, qa_run_id: string, lock_resource: string, lock_directory: string}|null
 */
function read_castor_check_lock_meta(string $projectRoot): ?array
{
    $path = castor_check_lock_meta_path($projectRoot);
    if (!is_readable($path)) {
        return null;
    }
    $json = file_get_contents($path);
    if (false === $json || '' === trim($json)) {
        return null;
    }
    $decoded = json_decode($json, true);
    if (!\is_array($decoded)) {
        return null;
    }

    return $decoded;
}

function write_castor_check_lock_meta(string $projectRoot): void
{
    $path = castor_check_lock_meta_path($projectRoot);
    @mkdir(\dirname($path), 0700, true);
    $payload = [
        'pid' => getmypid(),
        'started_at' => date('c'),
        'cwd' => (string) getcwd(),
        'project_root' => $projectRoot,
        'repo_identity' => castor_check_repo_lock_identity($projectRoot),
        'qa_run_id' => (string) (false !== ($qa = getenv('HATFIELD_QA_RUN_ID')) ? $qa : ''),
        'lock_resource' => castor_check_lock_resource_name($projectRoot),
        'lock_directory' => castor_check_lock_directory(),
    ];
    file_put_contents($path, json_encode($payload, \JSON_UNESCAPED_SLASHES)."\n", \LOCK_EX);
}

function update_castor_check_lock_meta_qa_run_id(string $projectRoot, string $qaRunId): void
{
    $path = castor_check_lock_meta_path($projectRoot);
    $existing = read_castor_check_lock_meta($projectRoot);
    $payload = null !== $existing ? $existing : [
        'pid' => getmypid(),
        'started_at' => date('c'),
        'cwd' => (string) getcwd(),
        'project_root' => $projectRoot,
        'repo_identity' => castor_check_repo_lock_identity($projectRoot),
        'qa_run_id' => '',
        'lock_resource' => castor_check_lock_resource_name($projectRoot),
        'lock_directory' => castor_check_lock_directory(),
    ];
    $payload['qa_run_id'] = $qaRunId;
    @mkdir(\dirname($path), 0700, true);
    file_put_contents($path, json_encode($payload, \JSON_UNESCAPED_SLASHES)."\n", \LOCK_EX);
}

function clear_castor_check_lock_meta(string $projectRoot): void
{
    $path = castor_check_lock_meta_path($projectRoot);
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * Acquire Symfony Lock for the full QA gate. Waits up to {@see CASTOR_CHECK_LOCK_ACQUIRE_TIMEOUT_S}
 * seconds (override: `HATFIELD_CASTOR_CHECK_LOCK_TIMEOUT`) with periodic heartbeats.
 *
 * Sibling worktrees of the same repository share the lock resource name.
 */
function acquire_castor_check_lock(string $projectRoot): LockInterface
{
    $factory = create_castor_check_lock_factory();
    $resource = castor_check_lock_resource_name($projectRoot);
    $lock = $factory->createLock($resource, null, false);

    $timeoutSeconds = castor_check_lock_acquire_timeout_seconds();
    $waitStart = microtime(true);
    $nextHeartbeat = $waitStart;
    $waitingAnnounced = false;
    $lockDir = castor_check_lock_directory();

    while (!$lock->acquire(blocking: false)) {
        $now = microtime(true);
        $elapsed = $now - $waitStart;
        if ($elapsed >= $timeoutSeconds) {
            fail_quality(format_castor_check_lock_acquire_timeout_message(
                $projectRoot,
                $resource,
                $lockDir,
                $timeoutSeconds,
                $elapsed,
            ));
        }
        if (!$waitingAnnounced) {
            echo \sprintf(
                "castor check: waiting for another full check for this repository (Symfony Lock resource %s, directory %s, pid %d, acquire timeout %.0fs)\n",
                $resource,
                $lockDir,
                getmypid(),
                $timeoutSeconds
            );
            $meta = read_castor_check_lock_meta($projectRoot);
            if (null !== $meta) {
                echo '  holder (metadata, may be stale): pid '.($meta['pid'] ?? '?')
                    .', started '.($meta['started_at'] ?? '?')
                    .', cwd '.($meta['cwd'] ?? '?')
                    .', qa_run_id '.($meta['qa_run_id'] ?? '(none)')."\n";
            }
            $waitingAnnounced = true;
        }
        if ($now >= $nextHeartbeat) {
            echo \sprintf("castor check: still waiting (%.0fs elapsed, pid %d)\n", $elapsed, getmypid());
            $nextHeartbeat = $now + (float) CASTOR_CHECK_LOCK_WAIT_HEARTBEAT_S;
        }
        usleep(200_000);
    }

    if ($waitingAnnounced) {
        echo \sprintf("castor check: lock acquired after %.1fs (pid %d)\n", microtime(true) - $waitStart, getmypid());
    }

    write_castor_check_lock_meta($projectRoot);

    return $lock;
}

function release_castor_check_lock(LockInterface $lock, string $projectRoot): void
{
    $lock->release();
    clear_castor_check_lock_meta($projectRoot);
}

/**
 * True when another process holds the castor check Symfony Lock.
 */
function castor_check_lock_is_busy(string $projectRoot): bool
{
    $factory = create_castor_check_lock_factory();
    $lock = $factory->createLock(castor_check_lock_resource_name($projectRoot), null, true);
    if ($lock->acquire(blocking: false)) {
        $lock->release();

        return false;
    }

    return true;
}

function castor_check_lock_smoke_hold(string $projectRoot, float $holdSeconds): void
{
    $lock = acquire_castor_check_lock($projectRoot);
    try {
        usleep((int) max(0, $holdSeconds * 1_000_000));
    } finally {
        release_castor_check_lock($lock, $projectRoot);
    }
}

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

    // Curated internal docs use source-tree symlinks; Box rejects links, so
    // materialize regular files into the staging tree before compilation.
    $internalDocsPath = $root.'/internal-docs';
    if (is_dir($internalDocsPath)) {
        shell_exec(
            'cp -aL '.escapeshellarg($internalDocsPath).' '.escapeshellarg($stagingDir.'/')
        );
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

// ─── QA check run leak detection and artifact integrity ───────────────

/**
 * PIDs that must never be reported as QA run leaks (current Castor PHP and ancestors).
 *
 * @return list<int>
 */
function qa_check_run_protected_pids(): array
{
    $protected = [];
    $pid = getmypid();
    $seen = [];
    while ($pid > 0 && !isset($seen[$pid])) {
        $seen[$pid] = true;
        $protected[] = $pid;
        $status = @file_get_contents('/proc/'.$pid.'/status');
        if (false === $status || !preg_match('/^PPid:\s+(\d+)/m', $status, $m)) {
            break;
        }
        $pid = (int) $m[1];
    }

    return $protected;
}

/**
 * Whether /proc/<pid>/environ contains HATFIELD_QA_RUN_ID for the given run id.
 */
function process_environ_has_qa_run_id(int $pid, string $runId): bool
{
    $environ = @file_get_contents('/proc/'.$pid.'/environ');
    if (false === $environ || '' === $runId) {
        return false;
    }

    $needle = 'HATFIELD_QA_RUN_ID='.$runId."\0";

    return str_contains($environ, $needle);
}

/**
 * @return list<array{pid:int,ppid:int,sid:int,cmd:string,cwd:string}>
 */
function collect_qa_check_run_leaked_processes(string $runId): array
{
    if ('' === trim($runId) || !\function_exists('posix_geteuid')) {
        return [];
    }

    $uid = posix_geteuid();
    $protected = array_fill_keys(qa_check_run_protected_pids(), true);
    $leaks = [];

    $procEntries = glob('/proc/[0-9]*');
    if (false === $procEntries) {
        $procEntries = [];
    }
    foreach ($procEntries as $procDir) {
        $pid = (int) basename($procDir);
        if ($pid <= 0 || isset($protected[$pid])) {
            continue;
        }

        $stat = @stat($procDir);
        if (false === $stat || ($stat['uid'] ?? -1) !== $uid) {
            continue;
        }

        if (!process_environ_has_qa_run_id($pid, $runId)) {
            continue;
        }

        $ppid = 0;
        $status = @file_get_contents($procDir.'/status');
        if (false !== $status && preg_match('/^PPid:\s+(\d+)/m', $status, $m)) {
            $ppid = (int) $m[1];
        }

        $sid = 0;
        $statLine = @file_get_contents($procDir.'/stat');
        if (false !== $statLine) {
            $close = strrpos($statLine, ')');
            if (false !== $close) {
                $rest = trim(substr($statLine, $close + 1));
                $fields = preg_split('/\s+/', $rest);
                if (false === $fields) {
                    $fields = [];
                }
                if (isset($fields[3])) {
                    $sid = (int) $fields[3];
                }
            }
        }

        $cmdRaw = @file_get_contents($procDir.'/cmdline');
        $cmd = '';
        if (false !== $cmdRaw) {
            $cmd = str_replace("\0", ' ', trim($cmdRaw));
        }

        $cwd = '';
        $cwdLink = $procDir.'/cwd';
        if (is_link($cwdLink)) {
            $resolved = @readlink($cwdLink);
            if (false !== $resolved) {
                $cwd = $resolved;
            }
        }

        $leaks[] = [
            'pid' => $pid,
            'ppid' => $ppid,
            'sid' => $sid,
            'cmd' => $cmd,
            'cwd' => $cwd,
        ];
    }

    usort($leaks, static fn (array $a, array $b): int => $a['pid'] <=> $b['pid']);

    return $leaks;
}

/**
 * Fail the QA gate if processes tagged with this run id remain (no auto-kill).
 */
function assert_castor_check_run_no_process_leaks(string $runId): void
{
    $processLeaks = collect_qa_check_run_leaked_processes($runId);
    $tmuxLeaks = collect_qa_check_run_leaked_tmux_sessions($runId);

    if ([] === $processLeaks && [] === $tmuxLeaks) {
        echo "QA run leak check: ok (no processes or tmux sessions owned by HATFIELD_QA_RUN_ID={$runId})\n";

        return;
    }

    $lines = [
        'QA run leak check FAILED: resources still owned by HATFIELD_QA_RUN_ID='.$runId,
        'Investigate lifecycle teardown (do not auto-kill). Manual cleanup only when safe:',
        '  castor clean:cleanup:workers:list',
        '  castor clean:cleanup:workers',
        '  tmux list-sessions (see @hatfield_qa_run_id session option)',
        '',
    ];

    if ([] !== $processLeaks) {
        $lines[] = 'Processes:';
        foreach ($processLeaks as $row) {
            $lines[] = \sprintf(
                '  pid=%d ppid=%d sid=%d cwd=%s cmd=%s',
                $row['pid'],
                $row['ppid'],
                $row['sid'],
                '' !== $row['cwd'] ? $row['cwd'] : '?',
                '' !== $row['cmd'] ? $row['cmd'] : '?',
            );
        }
        $lines[] = '';
    }

    if ([] !== $tmuxLeaks) {
        $lines[] = 'Tmux sessions (exact @hatfield_qa_run_id match):';
        foreach ($tmuxLeaks as $session) {
            $lines[] = '  '.$session.'  (tmux kill-session -t '.escapeshellarg($session).')';
        }
    }

    fail_quality(implode("\n", $lines));
}

/**
 * ParaTest worker budget for check E2E lanes (conservative under parallel castor check).
 */
function check_lane_paratest_processes(string $lane, int $default, int $max = 4): int
{
    $envMap = [
        'tui' => 'HATFIELD_CHECK_TUI_PARATEST_PROCESSES',
        'llm-real' => 'HATFIELD_CHECK_LLM_REAL_PARATEST_PROCESSES',
        'unit' => 'HATFIELD_CHECK_UNIT_PARATEST_PROCESSES',
    ];
    $envName = $envMap[$lane] ?? null;
    $raw = false;
    if (null !== $envName) {
        $raw = getenv($envName);
    }
    if (false === $raw || '' === trim((string) $raw)) {
        $inCheck = false !== getenv('HATFIELD_QA_RUN_ID') && '' !== trim((string) getenv('HATFIELD_QA_RUN_ID'));
        $processes = $inCheck ? $default : ('llm-real' === $lane ? 4 : $default);
    } else {
        $processes = (int) $raw;
    }

    if ($processes < 1) {
        $processes = $default;
    }
    if ($processes > $max) {
        $processes = $max;
    }

    return $processes;
}

/**
 * @param list<string> $laneSteps
 */
function assert_castor_check_lane_artifacts_integrity(array $laneSteps): void
{
    $reportsDir = reports_dir();
    $runReportsRel = getenv('HATFIELD_QA_REPORTS_DIR');
    $missing = [];
    foreach ($laneSteps as $step) {
        $path = report_path('check-'.$step.'.log');
        if (!is_file($path)) {
            $missing[] = 'missing log: '.relative_report_path('check-'.$step.'.log').' (expected under '.$reportsDir.')';
            continue;
        }
        if (0 === filesize($path)) {
            $missing[] = 'empty log: '.relative_report_path('check-'.$step.'.log');
        }
    }

    if ([] === $missing) {
        echo 'QA artifact integrity: ok ('.\count($laneSteps)." lane logs in {$reportsDir})\n";

        return;
    }

    fail_quality("QA artifact integrity FAILED:\n".implode("\n", $missing));
}

// ─── llama-proxy cache guard (castor check only) ───────────────────────

function llama_proxy_admin_base_url(): string
{
    $override = getenv('HATFIELD_LLM_PROXY_ADMIN_URL');
    if (false !== $override && '' !== trim((string) $override)) {
        return rtrim((string) $override, '/');
    }

    return 'http://127.0.0.1:9052';
}

/**
 * Optional admin token header for llama-proxy stats (when LLAMA_PROXY_ADMIN_TOKEN is set).
 *
 * @return list<string>
 */
function llama_proxy_admin_curl_headers(): array
{
    $token = getenv('LLAMA_PROXY_ADMIN_TOKEN');
    if (false === $token || '' === trim((string) $token)) {
        return [];
    }

    return ['-H', 'X-Llama-Proxy-Token: '.(string) $token];
}

function llama_proxy_cache_guard_enabled(): bool
{
    $raw = getenv('HATFIELD_LLM_CACHE_GUARD');
    if (false === $raw) {
        return true;
    }
    $normalized = strtolower(trim((string) $raw));

    return !\in_array($normalized, ['0', 'false', 'no', 'off'], true);
}

/**
 * @return array{entries: int, bytes: ?int, raw: array<string, mixed>}
 */
function fetch_llama_proxy_cache_stats(): array
{
    $url = llama_proxy_admin_base_url().'/__llama_proxy/cache/stats';
    $headerArgs = llama_proxy_admin_curl_headers();
    $headerShell = '';
    foreach ($headerArgs as $part) {
        $headerShell .= ' '.escapeshellarg($part);
    }

    $cmd = 'timeout --kill-after=3s 8s curl -sS -m 5 -f'.$headerShell.' '.escapeshellarg($url);
    $process = run_quiet_command($cmd);
    if (0 !== $process->getExitCode()) {
        throw new \RuntimeException('llama-proxy cache stats unavailable at '.$url.' (curl exit '.$process->getExitCode().'). Full `castor check` requires llama-proxy on port 9052 with /__llama_proxy/cache/stats. '.trim($process->getErrorOutput().$process->getOutput()));
    }

    $body = trim($process->getOutput());
    $decoded = json_decode($body, true);
    if (!\is_array($decoded)) {
        throw new \RuntimeException('llama-proxy cache stats returned non-JSON from '.$url.': '.$body);
    }

    if (!\array_key_exists('entries', $decoded)) {
        throw new \RuntimeException('llama-proxy cache stats JSON missing "entries" key from '.$url.': '.$body);
    }

    $entries = $decoded['entries'];
    if (!\is_int($entries) && !(\is_string($entries) && ctype_digit($entries))) {
        throw new \RuntimeException('llama-proxy cache stats "entries" is not an integer from '.$url.': '.$body);
    }

    $bytes = null;
    if (\array_key_exists('bytes', $decoded)) {
        $bytesRaw = $decoded['bytes'];
        if (\is_int($bytesRaw) || (\is_string($bytesRaw) && ctype_digit($bytesRaw))) {
            $bytes = (int) $bytesRaw;
        }
    }

    return [
        'entries' => (int) $entries,
        'bytes' => $bytes,
        'raw' => $decoded,
    ];
}

/**
 * Capture baseline cache entries for `castor check` (before generation preflight).
 */
function begin_castor_check_llama_proxy_cache_guard(): ?int
{
    if (!llama_proxy_cache_guard_enabled()) {
        echo "llama-proxy cache guard: disabled (HATFIELD_LLM_CACHE_GUARD=0)\n";

        return null;
    }

    $stats = fetch_llama_proxy_cache_stats();
    $entries = $stats['entries'];
    echo 'llama-proxy cache guard: baseline entries='.$entries."\n";

    return $entries;
}

function assert_castor_check_llama_proxy_cache_unchanged(?int $baselineEntries): void
{
    if (null === $baselineEntries) {
        return;
    }

    $stats = fetch_llama_proxy_cache_stats();
    $after = $stats['entries'];
    if ($after > $baselineEntries) {
        throw new \RuntimeException(\sprintf("llama-proxy cache grew from %d to %d entries during `castor check` — uncached live LLM request(s) occurred.\nWarm the proxy cache first: run `castor test:llm-real`, verify `curl %s/__llama_proxy/cache/stats`, then rerun `castor check`.\n".'After clearing the proxy cache you must warm again before the gate passes.', $baselineEntries, $after, llama_proxy_admin_base_url()));
    }

    echo 'llama-proxy cache guard: ok (entries '.$baselineEntries.' → '.$after.")\n";
}

function check_llm_generation_ready(): void
{
    $tmpDir = getenv('HATFIELD_QA_TMP_DIR');
    if (false !== $tmpDir && '' !== trim((string) $tmpDir)) {
        $cacheFile = rtrim((string) $tmpDir, '/').'/llm-generation-ready.cache';
    } else {
        $cacheFile = 'var/tmp/llm-generation-ready.cache';
    }
    $envTtl = getenv('HATFIELD_LLM_READY_TTL');
    $ttlSeconds = (int) (false !== $envTtl && '' !== $envTtl ? $envTtl : 120);
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

    $cmd = qa_check_run_env_command().' timeout --kill-after=5s 15s curl -sS -m 10 -o /dev/null -w "%{http_code}"'
        .' -H "Content-Type: application/json"'
        .' -d '.escapeshellarg($payload)
        .' '.escapeshellarg($url);

    $process = run_quiet_command($cmd);

    $httpCode = (int) trim($process->getOutput());

    if (200 === $httpCode && 0 === $process->getExitCode()) {
        $cacheParent = \dirname($cacheFile);
        if ('' !== $cacheParent && '.' !== $cacheParent && !is_dir($cacheParent)) {
            @mkdir($cacheParent, 0o777, true);
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
