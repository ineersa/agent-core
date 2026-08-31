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
 * Invocation-scoped Symfony cache root for standalone Castor test/migrate.
 *
 * Avoids shared `.hatfield/cache/test` when HATFIELD_CACHE_DIR is unset.
 * Reuses an already-set HATFIELD_CACHE_DIR (castor check run-scoped cache,
 * ParaTest worker override, or explicit caller) without allocating a new one.
 */
function qa_standalone_test_cache_dir(): string
{
    $existing = getenv('HATFIELD_CACHE_DIR');
    if (false !== $existing && '' !== trim((string) $existing)) {
        return (string) $existing;
    }

    $projectRoot = project_root_dir();
    $runId = getenv('HATFIELD_QA_RUN_ID');
    if (false !== $runId && '' !== trim((string) $runId)) {
        $segment = sanitize_qa_run_id_segment((string) $runId);
        $resolved = '.hatfield/cache-'.$segment;
    } else {
        $resolved = '.hatfield/cache-test-pid-'.getmypid();
    }

    $path = $projectRoot.'/'.$resolved;
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        throw new \RuntimeException(\sprintf('Unable to create standalone test cache directory "%s".', $path));
    }

    putenv('HATFIELD_CACHE_DIR='.$resolved);
    $_ENV['HATFIELD_CACHE_DIR'] = $resolved;
    $_SERVER['HATFIELD_CACHE_DIR'] = $resolved;

    return $resolved;
}

/**
 * Shell prefix exporting isolated HOME for subprocesses that boot the test kernel.
 */
function qa_test_home_shell_prefix(): string
{
    $home = qa_test_home_dir();
    $cache = qa_standalone_test_cache_dir();

    return 'HOME='.escapeshellarg($home)
        .' HATFIELD_QA_TEST_HOME='.escapeshellarg($home)
        .' HATFIELD_CACHE_DIR='.escapeshellarg($cache);
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

/** Default project-root-relative directory for session-owned PHAR copies. */
const HATFIELD_PHAR_SESSION_COPIES_DIR_DEFAULT = 'var/tmp/phar/sessions';

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
        try {
            $output = run_checked($installCmd);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to install tools/phar Box toolchain: '.$e->getMessage(), 0, $e);
        }
        // After composer install, re-check the binary with a fresh
        // stat cache — composer install creates the file on disk.
        clearstatcache(true, $localBoxBin);
        if (is_executable($localBoxBin)) {
            return $localBoxBin;
        }
        // If install produced output but the binary still isn't
        // executable — show diagnostic output before falling through
        // to the global Box lookup.
        $diagnostic = trim($output);
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

/**
 * Materialize the ExtensionApi path package under vendor/ as a real directory.
 *
 * Root path-requires ineersa/hatfield-extension-api; Composer leaves a symlink
 * (often outside the staging tree). Box cannot package that link into the PHAR.
 * Copy-before-unlink so a failed rename leaves the symlink intact.
 */
function materialize_vendor_path_package_symlinks(string $stagingDir): void
{
    $path = $stagingDir.'/vendor/ineersa/hatfield-extension-api';
    if (!is_link($path)) {
        // Already a real directory (or missing); still strip vendor docs duplicates.
        strip_vendor_extension_api_docs($stagingDir);

        return;
    }

    $target = realpath($path);
    if (false === $target || !is_dir($target)) {
        throw new \RuntimeException('Unable to resolve path-package symlink target for: '.$path);
    }

    $tmp = $path.'.materialize-tmp-'.bin2hex(random_bytes(4));
    run_checked('cp -a '.escapeshellarg($target).' '.escapeshellarg($tmp));
    if (!unlink($path)) {
        remove_path_checked($tmp);
        throw new \RuntimeException('Unable to remove path-package symlink: '.$path);
    }
    if (!rename($tmp, $path)) {
        remove_path_checked($tmp);
        throw new \RuntimeException('Unable to replace path-package symlink with copy: '.$path);
    }

    // Canonical model-visible API docs live under .hatfield/extensions/extension-api/docs.
    // Drop the vendor path-package docs/ copy so the PHAR does not ship duplicates.
    strip_vendor_extension_api_docs($stagingDir);
}

/**
 * Remove vendor/ineersa/hatfield-extension-api/docs after Composer path materialization.
 *
 * Selected API docs are staged only at the monorepo-canonical path.
 */
function strip_vendor_extension_api_docs(string $stagingDir): void
{
    $docs = $stagingDir.'/vendor/ineersa/hatfield-extension-api/docs';
    if (is_dir($docs) || is_link($docs)) {
        remove_path_checked($docs);
    }
}

// ─── ──────────────────────────────────────────────────────────────────

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
        $custom = rtrim(trim((string) $custom), '/');
        $dir = str_starts_with($custom, '/') ? $custom : project_root_dir().'/'.$custom;
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
        return rtrim(trim((string) $custom), '/').'/'.$filename;
    }

    return 'var/reports/'.$filename;
}

function run_quiet_command(string $command): Process
{
    return run($command, context: new Context(quiet: true, allowFailure: true));
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
 * Run a shell command with fail-fast exit-status checking.
 *
 * Captures combined stdout/stderr. Never logs secrets intentionally: callers
 * must not embed credentials in $command. Returns trimmed stdout on success.
 *
 * @throws \RuntimeException on non-zero exit
 */
/**
 * @param array<string, string> $env
 */
function run_checked(string $command, ?string $cwd = null, array $env = []): string
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $fullEnv = null;
    if ([] !== $env) {
        $fullEnv = [];
        foreach (getenv() as $k => $v) {
            $fullEnv[$k] = $v;
        }
        foreach ($env as $k => $v) {
            $fullEnv[$k] = $v;
        }
    }

    $process = proc_open($command, $descriptorSpec, $pipes, $cwd, $fullEnv);
    if (!\is_resource($process)) {
        throw new \RuntimeException('Failed to start command: '.$command);
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    $stdout = false === $stdout ? '' : $stdout;
    $stderr = false === $stderr ? '' : $stderr;
    $combined = trim($stdout.('' !== $stderr ? ("\n".$stderr) : ''));

    if (0 !== $status) {
        $tail = $combined;
        if (\strlen($tail) > 4000) {
            $tail = '...'.substr($tail, -4000);
        }
        throw new \RuntimeException("Command failed (exit {$status}): {$command}\n".'cwd: '.($cwd ?? (string) getcwd())."\noutput:\n{$tail}");
    }

    return $combined;
}

/**
 * Recursively remove a path (file or directory). Fail-fast.
 */
function remove_path_checked(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }

    if (is_link($path) || is_file($path)) {
        if (!unlink($path)) {
            throw new \RuntimeException('Failed to remove file: '.$path);
        }

        return;
    }

    if (!is_dir($path)) {
        throw new \RuntimeException('Refusing to remove non-file/non-dir path: '.$path);
    }

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entryPath = (string) $entry;
        if ($entry->isDir()) {
            if (!rmdir($entryPath)) {
                throw new \RuntimeException('Failed to remove directory: '.$entryPath);
            }
        } elseif (!unlink($entryPath)) {
            throw new \RuntimeException('Failed to remove file: '.$entryPath);
        }
    }
    if (!rmdir($path)) {
        throw new \RuntimeException('Failed to remove directory: '.$path);
    }
}

/**
 * Copy a file, failing if the copy does not succeed.
 */
function copy_file_checked(string $from, string $to): void
{
    $parent = \dirname($to);
    if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
        throw new \RuntimeException('Unable to create parent directory: '.$parent);
    }
    if (!copy($from, $to)) {
        throw new \RuntimeException("Failed to copy {$from} -> {$to}");
    }
}

/**
 * Write file contents, fail-fast.
 */
function write_file_checked(string $path, string $contents): void
{
    $parent = \dirname($path);
    if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
        throw new \RuntimeException('Unable to create parent directory: '.$parent);
    }
    if (false === file_put_contents($path, $contents)) {
        throw new \RuntimeException('Failed to write file: '.$path);
    }
}

/**
 * Packaged PHAR input roots and files used for both staging and freshness.
 *
 * Keep this list complete: any input that can change the artifact must be here
 * so phar_ensure() and the lock-holder second freshness check stay in sync.
 *
 * @return array{directories: list<string>, files: list<string>}
 */
function phar_packaged_inputs(string $root): array
{
    // Selected built-in docs are fingerprinted as individual files (not the whole
    // docs/ tree). Discovery uses the same BuiltinDocsCatalog roots/marker as runtime.
    $selectedDocs = [];
    if (class_exists(\Ineersa\CodingAgent\Docs\BuiltinDocsCatalog::class)) {
        $selectedDocs = (new \Ineersa\CodingAgent\Docs\BuiltinDocsCatalog())->selectedAbsolutePaths($root);
    }

    // Extension API package source is fingerprinted without its docs/ subtree so
    // unmarked API Markdown does not invalidate freshness; selected API docs are
    // listed explicitly via $selectedDocs.
    $extensionApiFiles = [];
    $extensionApiRoot = $root.'/.hatfield/extensions/extension-api';
    if (is_dir($extensionApiRoot)) {
        $extensionApiRootReal = false !== ($extensionApiRootResolved = realpath($extensionApiRoot))
            ? $extensionApiRootResolved
            : $extensionApiRoot;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($extensionApiRoot, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $current) use ($extensionApiRootReal): bool {
                    if (!$current->isDir()) {
                        return true;
                    }
                    // Skip only the package-level docs/ directory.
                    if ('docs' !== $current->getFilename()) {
                        return true;
                    }
                    $parent = \dirname($current->getPathname());
                    $parentReal = false !== ($parentResolved = realpath($parent))
                        ? $parentResolved
                        : $parent;

                    return $parentReal !== $extensionApiRootReal;
                },
            ),
        );
        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isFile() || $entry->isLink()) {
                $extensionApiFiles[] = $entry->getPathname();
            }
        }
        sort($extensionApiFiles);
    }

    return [
        'directories' => [
            $root.'/bin',
            $root.'/src',
            $root.'/config',
            $root.'/migrations',
            $root.'/.castor',
            $root.'/tools/phar',
        ],
        'files' => array_values(array_unique(array_merge([
            $root.'/composer.json',
            $root.'/composer.lock',
            $root.'/box.json',
            $root.'/castor.php',
            $root.'/tools/phar/composer.json',
            $root.'/tools/phar/composer.lock',
        ], $extensionApiFiles, $selectedDocs))),
    ];
}

/**
 * Sidecar path storing the deterministic packaged-input fingerprint for a PHAR.
 *
 * Compared by phar_is_stale() so freshness tracks packaged content
 * (including selected built-in docs), not just directory mtimes.
 */
function phar_freshness_marker_path(string $pharPath): string
{
    return $pharPath.'.inputs.sha256';
}

/**
 * Deterministic fingerprint of the complete packaged/build input set.
 *
 * Directories are walked recursively. Symlinks contribute the resolved target
 * path and the target file contents. Selected built-in docs are also listed as
 * explicit files. Missing optional paths are recorded as absent so deletions invalidate.
 */
function phar_input_fingerprint(string $root): string
{
    $inputs = phar_packaged_inputs($root);
    $lines = [];

    $recordFile = static function (string $path) use (&$lines): void {
        if (is_link($path)) {
            $target = readlink($path);
            $resolved = realpath($path);
            $hash = false !== $resolved && is_file($resolved)
                ? hash_file('sha256', $resolved)
                : 'missing-target';
            $lines[] = 'link\t'.$path.'\t'.(false === $target ? '' : $target).'\t'.(false === $hash ? 'unreadable' : $hash);

            return;
        }
        if (!is_file($path)) {
            $lines[] = 'missing\t'.$path;

            return;
        }
        $hash = hash_file('sha256', $path);
        $lines[] = 'file\t'.$path.'\t'.(false === $hash ? 'unreadable' : $hash);
    };

    foreach ($inputs['files'] as $file) {
        $recordFile($file);
    }

    foreach ($inputs['directories'] as $dir) {
        if (!is_dir($dir) || !is_readable($dir)) {
            $lines[] = 'missing-dir\t'.$dir;
            continue;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        $paths = [];
        foreach ($iterator as $entry) {
            $paths[] = (string) $entry;
        }
        sort($paths);
        foreach ($paths as $path) {
            $recordFile($path);
        }
    }

    // Fingerprint the resolved packaging build identity (env overrides + git HEAD),
    // matching resolve_build_identity_for_packaging() so unset HATFIELD_BUILD_COMMIT
    // still invalidates when HEAD moves.
    $identity = resolve_build_identity_for_packaging($root);
    $lines[] = 'build-identity\tversion\t'.$identity['version'];
    $lines[] = 'build-identity\tcommit\t'.$identity['commit'];

    sort($lines);

    return hash('sha256', implode("\n", $lines)."\n");
}

/**
 * True when $pharPath is missing/unreadable or its input fingerprint differs.
 *
 * Uses the same complete packaged-input set as staging (phar_packaged_inputs)
 * plus build identity env. The lock-holder second check must call this same
 * predicate — never a divergent mtime shortcut.
 */
function phar_is_stale(string $root, string $pharPath): bool
{
    if (!is_file($pharPath) || !is_readable($pharPath)) {
        return true;
    }

    $marker = phar_freshness_marker_path($pharPath);
    if (!is_file($marker) || !is_readable($marker)) {
        return true;
    }

    $stored = trim((string) file_get_contents($marker));
    if ('' === $stored || !preg_match('/^[a-f0-9]{64}$/', $stored)) {
        return true;
    }

    return !hash_equals($stored, phar_input_fingerprint($root));
}

/**
 * Persist the current packaged-input fingerprint next to a successful PHAR.
 */
function phar_write_freshness_marker(string $root, string $pharPath): void
{
    write_file_checked(phar_freshness_marker_path($pharPath), phar_input_fingerprint($root)."\n");
}

/**
 * Remove PHAR artifact and its freshness marker (failed builds / clean).
 */
function phar_remove_artifact_and_marker(string $pharPath): void
{
    if (is_file($pharPath) || is_link($pharPath)) {
        remove_path_checked($pharPath);
    }
    $marker = phar_freshness_marker_path($pharPath);
    if (is_file($marker) || is_link($marker)) {
        remove_path_checked($marker);
    }
}

/**
 * Resolve build version/commit for packaging (env overrides + git).
 *
 * @return array{version: string, commit: string}
 */
function resolve_build_identity_for_packaging(string $root): array
{
    $version = getenv('HATFIELD_BUILD_VERSION');
    $version = (false !== $version && '' !== trim((string) $version)) ? trim((string) $version) : 'dev';

    $commit = getenv('HATFIELD_BUILD_COMMIT');
    if (false === $commit || '' === trim((string) $commit)) {
        $git = trim((string) shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null'));
        $commit = '' !== $git ? $git : 'unknown';
    } else {
        $commit = trim((string) $commit);
    }

    return ['version' => $version, 'commit' => $commit];
}

/**
 * PHAR build lock file path (relative to project root).
 *
 * Used by phar_ensure() to serialize parallel workers that all try
 * to build the same PHAR simultaneously.  Without this lock,
 * concurrent staging cleanup races with concurrent cp/composer/box steps.
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

/**
 * Ensure the PHAR exists and is fresh.
 *
 * If the PHAR is missing or stale relative to the complete packaged-input set,
 * triggers a rebuild. Failures propagate (no swallow). run:agent sessions exec
 * a fixed session copy, so the canonical artifact has no long-lived holder
 * and is rebuilt freely.
 *
 * @return string absolute path to the existing or freshly built PHAR
 */
function phar_ensure(): string
{
    $pharPath = hatfield_phar_path();
    $root = realpath(__DIR__.'/..');
    if (false === $root) {
        throw new \RuntimeException('Unable to resolve project root for PHAR ensure.');
    }

    if (!phar_is_stale($root, $pharPath)) {
        return $pharPath;
    }

    if (is_file($pharPath)) {
        echo "PHAR stale: packaged inputs changed. Rebuilding.\n";
    } else {
        echo "PHAR not found. Building.\n";
    }

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
    if (!is_dir(\dirname($lockPath)) && !mkdir(\dirname($lockPath), 0755, true) && !is_dir(\dirname($lockPath))) {
        throw new \RuntimeException('Unable to create PHAR lock directory: '.\dirname($lockPath));
    }

    $lockHandle = fopen($lockPath, 'c+b');
    if (false === $lockHandle) {
        throw new \RuntimeException('Unable to open PHAR build lock: '.$lockPath);
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
        // Re-check freshness AFTER acquiring the lock — another worker may
        // have built it while we were waiting. Uses the same predicate as
        // phar_ensure() so lock-holder and first check cannot diverge.
        $pharPath = hatfield_phar_path();
        if (!phar_is_stale($root, $pharPath)) {
            return;
        }

        phar_build();
    } finally {
        flock($lockHandle, \LOCK_UN);
        fclose($lockHandle);
    }
}

// ─── Session-owned PHAR copies (run:agent) ────────────────────────────

/**
 * Directory holding session-owned PHAR copies (under the project root).
 */
function hatfield_phar_session_copies_dir(): string
{
    return project_root_dir().'/'.HATFIELD_PHAR_SESSION_COPIES_DIR_DEFAULT;
}

/**
 * Materialize a byte-identical session-owned copy of the canonical artifact.
 *
 * WHY: run:agent sessions exec the artifact for their whole lifetime. If a
 * session ran the canonical file directly, castor test/check rebuilds of the
 * canonical artifact would swap phar:// file reads under the live process and
 * corrupt the session. Sessions exec one fixed copy at
 * var/tmp/phar/sessions/hatfield.phar instead, so the canonical artifact has
 * no long-lived holder and is rebuilt freely.
 *
 * Same build → reuse without rewriting the file a live session may exec from.
 * New build → overwrite the fixed path in place. Safe only because launches
 * are serialized (single session at a time); an old session alive across a
 * rebuild would observe its binary replaced mid-execution. Absent/corrupt
 * dest is re-copied the same way. Swept by `castor clean:cleanup` (removes
 * the whole var/tmp/phar tree).
 *
 * @param string      $pharPath    canonical artifact to copy from
 * @param string|null $sessionsDir override root for session copies (tests)
 *
 * @return string absolute path of the session-owned copy
 */
function phar_materialize_session_copy(string $pharPath, ?string $sessionsDir = null): string
{
    $sessionsDir = $sessionsDir ?? hatfield_phar_session_copies_dir();
    $dest = $sessionsDir.'/hatfield.phar';
    $hash = hash_file('sha256', $pharPath);
    if (false === $hash) {
        throw new \RuntimeException('Unable to hash PHAR artifact: '.$pharPath);
    }

    // Same build → reuse without rewriting the file a live session may exec from.
    if (is_file($dest) && hash_file('sha256', $dest) === $hash) {
        return $dest;
    }

    if (!is_dir($sessionsDir) && !mkdir($sessionsDir, 0755, true) && !is_dir($sessionsDir)) {
        throw new \RuntimeException('Unable to create PHAR session copies directory: '.$sessionsDir);
    }

    // Fixed path, in-place overwrite on new build: safe only because launches are
    // serialized (single session at a time) — an old session alive across a rebuild
    // would observe its binary replaced mid-execution.
    if (!copy($pharPath, $dest)) {
        throw new \RuntimeException("Failed to copy PHAR artifact {$pharPath} -> {$dest}");
    }

    return $dest;
}

// ─── Full QA gate (castor check) cross-worktree lock ───────────────────

/** Default maximum wait to acquire the full-check Symfony Lock (seconds). */
const CASTOR_CHECK_LOCK_ACQUIRE_TIMEOUT_S = 60;

/** Heartbeat interval while waiting for another check (seconds). */
const CASTOR_CHECK_LOCK_WAIT_HEARTBEAT_S = 15;

/**
 * Resolved lock acquire timeout for `castor check` (seconds).
 *
 * Override with `HATFIELD_CASTOR_CHECK_LOCK_TIMEOUT` (positive number, max 3600).
 * When `$checkWallDeadline` is set (absolute hrtime-seconds from check() entry),
 * the acquire wait is also clamped so lock waiting cannot push total check
 * invocation past the absolute 210s wall.
 */
function castor_check_lock_acquire_timeout_seconds(?float $checkWallDeadline = null): float
{
    $raw = getenv('HATFIELD_CASTOR_CHECK_LOCK_TIMEOUT');
    if (false === $raw || '' === trim((string) $raw)) {
        $seconds = (float) CASTOR_CHECK_LOCK_ACQUIRE_TIMEOUT_S;
    } else {
        if (!is_numeric($raw)) {
            throw new \RuntimeException('HATFIELD_CASTOR_CHECK_LOCK_TIMEOUT must be a positive number of seconds (got: '.$raw.')');
        }
        $seconds = (float) $raw;
        if ($seconds <= 0.0 || $seconds > 3600.0) {
            throw new \RuntimeException('HATFIELD_CASTOR_CHECK_LOCK_TIMEOUT must be between 0 (exclusive) and 3600 (got: '.$raw.')');
        }
    }

    if (null !== $checkWallDeadline) {
        $remaining = $checkWallDeadline - (hrtime(true) / 1e9);
        if ($remaining <= 0.0) {
            return 0.001; // tiny positive so the acquire loop fails immediately
        }
        $seconds = min($seconds, $remaining);
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
 * When `$checkWallDeadline` is provided, acquire wait is also bounded by remaining
 * absolute check wall so lock wait + gate cannot exceed 210s total.
 */
function acquire_castor_check_lock(string $projectRoot, ?float $checkWallDeadline = null): LockInterface
{
    $factory = create_castor_check_lock_factory();
    $resource = castor_check_lock_resource_name($projectRoot);
    $lock = $factory->createLock($resource, null, false);

    $timeoutSeconds = castor_check_lock_acquire_timeout_seconds($checkWallDeadline);
    $waitStart = microtime(true);
    $nextHeartbeat = $waitStart;
    $waitingAnnounced = false;
    $lockDir = castor_check_lock_directory();

    while (!$lock->acquire(blocking: false)) {
        $now = microtime(true);
        $elapsed = $now - $waitStart;
        // Re-clamp each loop so an absolute wall deadline is honored even if
        // the initial timeout was computed earlier in the wait.
        if (null !== $checkWallDeadline) {
            $remainingWall = $checkWallDeadline - (hrtime(true) / 1e9);
            if ($remainingWall <= 0.0 || $elapsed >= $remainingWall) {
                fail_quality(\sprintf(
                    'castor check exceeded absolute wall clock of %ds while waiting for lock (elapsed %.1fs, lock resource %s)',
                    castor_test_runner_max_seconds(),
                    $elapsed,
                    $resource,
                ));
            }
            $timeoutSeconds = min($timeoutSeconds, max(0.001, $remainingWall));
        }
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
 *   2. Embed build identity (version + commit).
 *   3. Apply a deterministic Composer autoloader suffix to prevent class-map
 *      collision when the PHAR is consumed by another Composer project.
 *   4. Run `composer install --no-dev --optimize-autoloader` in staging.
 *   5. Compile with Box (from the isolated tools/phar/ toolchain).
 *   6. Smoke-test the artifact from an isolated temp directory.
 *   7. Report timings and PHAR size.
 *
 * Destination artifact is deleted before compile; failed compile/smoke leaves
 * no successful-looking artifact behind.
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
    $boxBin = hatfield_phar_box_bin();
    $composerBin = hatfield_phar_composer_bin();

    if (!is_dir(\dirname($pharPath)) && !mkdir(\dirname($pharPath), 0755, true) && !is_dir(\dirname($pharPath))) {
        throw new \RuntimeException('Unable to create PHAR output directory: '.\dirname($pharPath));
    }

    // Delete any previous artifact + freshness marker before compile so a
    // failed build cannot leave a stale successful-looking PHAR in place.
    phar_remove_artifact_and_marker($pharPath);

    if (is_dir($stagingDir)) {
        remove_path_checked($stagingDir);
    }
    if (!mkdir($stagingDir, 0755, true) && !is_dir($stagingDir)) {
        throw new \RuntimeException('Unable to create staging directory: '.$stagingDir);
    }

    $copyStart = microtime(true);

    foreach (['bin', 'src', 'config', 'migrations'] as $dir) {
        $srcPath = $root.'/'.$dir;
        if (!is_dir($srcPath)) {
            throw new \RuntimeException('Required packaging directory missing: '.$srcPath);
        }
        run_checked('cp -a '.escapeshellarg($srcPath).' '.escapeshellarg($stagingDir.'/'));
    }

    // Path package for ineersa/hatfield-extension-api must exist relative to staging
    // composer.json so `composer install --no-dev` can resolve the package.
    $extensionApiSrc = $root.'/.hatfield/extensions/extension-api';
    if (!is_dir($extensionApiSrc)) {
        throw new \RuntimeException('Required packaging directory missing: '.$extensionApiSrc);
    }
    $extensionApiStaging = $stagingDir.'/.hatfield/extensions/extension-api';
    if (!mkdir($extensionApiStaging, 0755, true) && !is_dir($extensionApiStaging)) {
        throw new \RuntimeException('Unable to create staging directory: '.$extensionApiStaging);
    }
    // Copy the public Extension API package for composer path resolution, but exclude
    // its docs/ tree so unmarked Markdown cannot enter the archive. Catalog-selected
    // API docs are materialized below as regular files at canonical paths.
    run_checked(
        'rsync -a --delete --exclude '.escapeshellarg('docs/')
        .' '.escapeshellarg($extensionApiSrc.'/')
        .' '.escapeshellarg($extensionApiStaging.'/'),
    );

    // Stage only marked built-in docs at their canonical paths as regular files.
    // Unmarked repository docs and any legacy internal-docs projection are omitted.
    $selectedDocs = (new \Ineersa\CodingAgent\Docs\BuiltinDocsCatalog())->discover($root);
    foreach ($selectedDocs as $entry) {
        $dest = $stagingDir.'/'.$entry['relativePath'];
        $destDir = \dirname($dest);
        if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            throw new \RuntimeException('Unable to create staged docs directory: '.$destDir);
        }
        copy_file_checked($entry['absolutePath'], $dest);
    }

    foreach (['composer.json', 'composer.lock', 'box.json'] as $file) {
        $srcPath = $root.'/'.$file;
        if (!is_file($srcPath)) {
            throw new \RuntimeException('Required packaging file missing: '.$srcPath);
        }
        copy_file_checked($srcPath, $stagingDir.'/'.$file);
    }

    $identity = resolve_build_identity_for_packaging($root);
    $generatedRelative = 'src/CodingAgent/Build/build-identity.generated.php';
    $generatedSource = \Ineersa\CodingAgent\Build\ApplicationBuildIdentity::generatePhpSource(
        $identity['version'],
        $identity['commit'],
    );
    write_file_checked($stagingDir.'/'.$generatedRelative, $generatedSource);

    $copyTime = microtime(true) - $copyStart;
    $copySize = dirsize_estimate($stagingDir);

    echo "Staging prepared: {$stagingDir} ({$copySize} MB)\n";
    echo "Build identity: version={$identity['version']} commit={$identity['commit']}\n";

    // Without a fixed suffix, Composer derives the autoloader class name
    // from a hash of composer.json. Apply staging-only autoloader-suffix.
    $stagingComposerJson = $stagingDir.'/composer.json';
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
    write_file_checked($stagingComposerJson, $encoded."\n");

    $composerEnv = getenv('APP_ENV');
    $composerEnv = (false !== $composerEnv && '' !== $composerEnv) ? $composerEnv : 'prod';
    $composerStart = microtime(true);
    $composerCmd = \sprintf(
        'APP_ENV=%s COMPOSER_MEMORY_LIMIT=-1 XDEBUG_MODE=off %s install'
        .' --no-dev --prefer-dist --no-interaction --no-progress'
        .' --optimize-autoloader 2>&1',
        escapeshellarg($composerEnv),
        escapeshellarg($composerBin)
    );
    try {
        $composerOutput = run_checked($composerCmd, $stagingDir);
        // Composer path-requires extension-api as a vendor symlink; Box cannot
        // package that link, so materialize vendor/ineersa/hatfield-extension-api.
        materialize_vendor_path_package_symlinks($stagingDir);
    } catch (\Throwable $e) {
        phar_remove_artifact_and_marker($pharPath);
        throw $e;
    }
    $composerTime = microtime(true) - $composerStart;

    $stagingBoxJson = $stagingDir.'/box.json';
    $boxConfig = json_decode((string) file_get_contents($stagingBoxJson), true);
    if (!\is_array($boxConfig)) {
        throw new \RuntimeException('Failed to parse staging box.json.');
    }
    $boxConfig['output'] = $pharPath;
    $encoded = json_encode($boxConfig, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    if (false === $encoded) {
        throw new \RuntimeException('Failed to encode staging box.json.');
    }
    write_file_checked($stagingBoxJson, $encoded."\n");

    $boxEnv = getenv('APP_ENV');
    $boxEnv = (false !== $boxEnv && '' !== $boxEnv) ? $boxEnv : 'prod';
    $boxStart = microtime(true);
    $boxCmd = \sprintf(
        'APP_ENV=%s php -d memory_limit=-1 -d xdebug.mode=off %s compile 2>&1',
        escapeshellarg($boxEnv),
        escapeshellarg($boxBin)
    );
    try {
        $boxOutput = run_checked($boxCmd, $stagingDir);
    } catch (\Throwable $e) {
        phar_remove_artifact_and_marker($pharPath);
        throw new \RuntimeException("PHAR Box compile failed.\nComposer output:\n{$composerOutput}\n".$e->getMessage(), 0, $e);
    }
    $boxTime = microtime(true) - $boxStart;

    if (!is_file($pharPath)) {
        throw new \RuntimeException("PHAR build failed: output missing.\nComposer output:\n{$composerOutput}\nBox output:\n{$boxOutput}\n".'Box command: '.$boxCmd);
    }
    if (0 === filesize($pharPath)) {
        phar_remove_artifact_and_marker($pharPath);
        throw new \RuntimeException("PHAR build failed: output empty.\nComposer output:\n{$composerOutput}\nBox output:\n{$boxOutput}\n".'Box command: '.$boxCmd);
    }

    $totalTime = microtime(true) - $startTime;
    $sizeMb = \sprintf('%.1f', filesize($pharPath) / 1024 / 1024);
    echo "PHAR built: {$pharPath} ({$sizeMb} MB)\n";
    echo \sprintf(
        "Timings: copy=%.1fs  composer=%.1fs  box=%.1fs  total=%.1fs\n",
        $copyTime, $composerTime, $boxTime, $totalTime
    );

    try {
        phar_smoke($pharPath);
    } catch (\Throwable $e) {
        // Artifact exists here (we returned earlier if missing); delete so smoke
        // failure cannot leave a distributable PHAR behind.
        phar_remove_artifact_and_marker($pharPath);
        throw $e;
    }

    // Only successful smokes write the freshness marker.
    phar_write_freshness_marker($root, $pharPath);

    return $pharPath;
}

/**
 * Run fast no-LLM smoke checks against a built PHAR artifact.
 *
 * Executes from an isolated temporary working directory so .hatfield/* dirs
 * are created outside the repo and do not pollute the source checkout.
 *
 * Failures throw. Temp cleanup always runs in finally (concurrency-safe random dir).
 */
function phar_smoke(string $pharPath): void
{
    if (!is_file($pharPath) || !is_readable($pharPath)) {
        throw new \RuntimeException('PHAR smoke: artifact missing or unreadable: '.$pharPath);
    }

    $tmpCwd = sys_get_temp_dir().'/hatfield-phar-smoke-'.bin2hex(random_bytes(8));
    if (!mkdir($tmpCwd, 0755, true) && !is_dir($tmpCwd)) {
        throw new \RuntimeException('PHAR smoke: could not create isolated cwd: '.$tmpCwd);
    }

    try {
        // Isolate HOME inside the temp cwd so the PHAR does NOT read
        // the real user's ~/.hatfield/settings.yaml which may reference
        // providers/models not available in the packaged PHAR.
        $homeDir = $tmpCwd.'/home';
        if (!mkdir($homeDir.'/.hatfield', 0755, true) && !is_dir($homeDir.'/.hatfield')) {
            throw new \RuntimeException('PHAR smoke: could not create isolated home settings dir');
        }
        write_file_checked(
            $homeDir.'/.hatfield/settings.yaml',
            "ai:\n    default_model: null\n",
        );

        $smokeEnv = getenv('APP_ENV');
        $smokeEnv = (false !== $smokeEnv && '' !== $smokeEnv) ? $smokeEnv : 'prod';
        $phpBin = \PHP_BINARY;
        // Explicit override root: installed PHAR defaults to XDG/HOME cache,
        // so smoke proves identity layout under a disposable absolute root.
        $cacheRoot = $tmpCwd.'/cache-root';
        if (!mkdir($cacheRoot, 0755, true) && !is_dir($cacheRoot)) {
            throw new \RuntimeException('PHAR smoke: could not create isolated cache root');
        }
        $envPrefix = 'HOME='.escapeshellarg($homeDir)
            .' APP_ENV='.escapeshellarg($smokeEnv)
            .' HATFIELD_CACHE_DIR='.escapeshellarg($cacheRoot)
            .' ';

        $listOutput = run_checked(
            $envPrefix.$phpBin.' '.escapeshellarg($pharPath).' list 2>&1',
            $tmpCwd,
        );
        if (!str_contains($listOutput, 'agent')) {
            throw new \RuntimeException('PHAR smoke list failed: agent command not found');
        }
        echo "  smoke list: ok\n";

        $aboutOutput = run_checked(
            $envPrefix.$phpBin.' '.escapeshellarg($pharPath).' about 2>&1',
            $tmpCwd,
        );
        if (!str_contains($aboutOutput, 'Environment')) {
            throw new \RuntimeException('PHAR smoke about failed: no Environment line');
        }
        echo "  smoke about: ok\n";

        $helpOutput = run_checked(
            $envPrefix.$phpBin.' '.escapeshellarg($pharPath).' agent --help 2>&1',
            $tmpCwd,
        );
        if (!str_contains($helpOutput, 'Usage:')) {
            throw new \RuntimeException('PHAR smoke agent --help failed: no Usage line');
        }
        echo "  smoke agent --help: ok\n";

        $versionOutput = run_checked(
            $envPrefix.$phpBin.' '.escapeshellarg($pharPath).' --version 2>&1',
            $tmpCwd,
        );
        if (!str_contains($versionOutput, 'Hatfield') || !str_contains($versionOutput, 'commit')) {
            throw new \RuntimeException('PHAR smoke --version failed: expected Hatfield version with commit identity, got: '.$versionOutput);
        }
        echo "  smoke --version: ok ({$versionOutput})\n";

        // Installed artifacts no longer write Symfony cache under project CWD.
        if (is_dir($tmpCwd.'/.hatfield/cache')) {
            throw new \RuntimeException('PHAR smoke isolation failed: project .hatfield/cache must not be used for installed PHAR cache');
        }
        echo "  smoke project-cache isolation: ok (no project .hatfield/cache)\n";

        $expectedContent = hash_file('sha256', $pharPath);
        if (false === $expectedContent) {
            throw new \RuntimeException('PHAR smoke cache-isolation failed: could not hash PHAR archive');
        }
        $canonicalPath = realpath($pharPath);
        if (false === $canonicalPath) {
            throw new \RuntimeException('PHAR smoke cache-isolation failed: could not realpath PHAR archive');
        }
        $expectedPathHash = hash('sha256', $canonicalPath);
        $expectedCacheDir = $cacheRoot.'/'.$smokeEnv.'/'.$expectedContent.'-'.$expectedPathHash;
        if (!is_dir($expectedCacheDir)) {
            throw new \RuntimeException('PHAR smoke cache-isolation failed: expected identity cache dir missing: '.$expectedCacheDir);
        }
        echo "  smoke cache-isolation: ok (env+content+path identity under override root)\n";

        echo "PHAR smoke test: ok\n";
    } finally {
        try {
            remove_path_checked($tmpCwd);
        } catch (\Throwable $cleanupError) {
            // Intentional local degradation: smoke result is more important
            // than temp cleanup, but surface a diagnostic.
            fwrite(\STDERR, 'PHAR smoke cleanup warning: '.$cleanupError->getMessage()."\n");
        }
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
 *
 * Returns normally when no exact-run process/tmux leaks remain. On leaks, calls
 * fail_quality() and never returns, so subsequent cleanup is skipped.
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
 * Delete only the exact-run Symfony/QA cache roots for this castor check.
 *
 * Removes `.hatfield/cache-$qaRunId`, legacy sibling worker roots
 * `.hatfield/cache-$qaRunId-paraT*`, and lane-scoped worker roots
 * `.hatfield/cache-$qaRunId-<lane>-T*` under the project root. Preserves the
 * persistent `.hatfield/cache`, generic `.hatfield/cache-paraT*`, other QA
 * run ids, absolute/external HATFIELD_CACHE_DIR values, and anything whose
 * basename is not an exact match for this run.
 *
 * Must only be called after exact-run process/tmux leak assertion succeeds.
 *
 * @return list<string> absolute paths that were removed
 */
function cleanup_exact_qa_run_cache_roots(string $qaRunId, ?string $projectRoot = null): array
{
    $segment = sanitize_qa_run_id_segment($qaRunId);
    // Refuse empty/collapsed ids that would match too broadly.
    if ('' === $segment || ('qa-run' === $segment && 'qa-run' !== $qaRunId)) {
        return [];
    }

    $root = $projectRoot ?? project_root_dir();
    $rootReal = realpath($root);
    if (false === $rootReal || !is_dir($rootReal)) {
        return [];
    }

    $hatfieldDir = $rootReal.'/.hatfield';
    if (!is_dir($hatfieldDir)) {
        return [];
    }

    // Resolve once — loop-invariant parent guard for every candidate.
    $hatfieldReal = realpath($hatfieldDir);
    if (false === $hatfieldReal) {
        return [];
    }

    $primaryBase = 'cache-'.$segment;
    $legacyWorkerPattern = '/^'.preg_quote($primaryBase, '/').'-paraT[A-Za-z0-9._-]+$/';
    $laneWorkerPattern = '/^'.preg_quote($primaryBase, '/').'-(?:unit|tui|llm-real)-T[A-Za-z0-9._-]+$/';
    $removed = [];

    $entries = @scandir($hatfieldDir);
    if (false === $entries) {
        return [];
    }

    foreach ($entries as $entry) {
        if ('.' === $entry || '..' === $entry) {
            continue;
        }

        $isPrimary = $entry === $primaryBase;
        $isWorker = 1 === preg_match($legacyWorkerPattern, $entry)
            || 1 === preg_match($laneWorkerPattern, $entry);
        if (!$isPrimary && !$isWorker) {
            continue;
        }

        $candidate = $hatfieldDir.'/'.$entry;
        if (!is_dir($candidate) && !is_link($candidate)) {
            continue;
        }

        // Refuse paths that escape the project .hatfield tree (symlink escapes).
        $candidateReal = realpath($candidate);
        if (false === $candidateReal) {
            // Dangling link or race — still only remove if basename-owned under .hatfield.
            if (is_link($candidate)) {
                remove_path_checked($candidate);
                // Report absolute path consistently with real-dir removals.
                $removed[] = $hatfieldReal.\DIRECTORY_SEPARATOR.$entry;
            }

            continue;
        }

        if (!str_starts_with($candidateReal, $hatfieldReal.\DIRECTORY_SEPARATOR)) {
            continue;
        }

        if (basename($candidateReal) !== $entry) {
            continue;
        }

        remove_path_checked($candidateReal);
        $removed[] = $candidateReal;
    }

    if ([] !== $removed) {
        echo 'QA run cache cleanup: removed '.\count($removed)." exact-run cache root(s) for HATFIELD_QA_RUN_ID={$segment}\n";
    }

    return $removed;
}

/**
 * ParaTest worker budget for check E2E lanes (conservative under parallel castor check).
 *
 * Under castor check, llm-real defaults to 1 worker to avoid process contention with
 * unit/TUI lanes. Standalone castor test:llm-real defaults to 2 workers against the
 * shared llama-proxy endpoint.
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
        if ($inCheck) {
            // Live LLM under concurrent gate load: one worker avoids ParaTest
            // process crashes seen when llm-real raced unit workers.
            $processes = 'llm-real' === $lane ? 1 : $default;
        } else {
            // Standalone llm-real still shares one llama-proxy/model endpoint.
            // Two workers keep some parallelism without the 4-worker contention
            // that previously produced flaky multi-hop tool completions.
            $processes = 'llm-real' === $lane ? 2 : $default;
        }
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
 * @param float|null $checkWallDeadline absolute hrtime-seconds deadline from check() entry
 *
 * @return array{entries: int, bytes: ?int, raw: array<string, mixed>}
 */
function fetch_llama_proxy_cache_stats(?float $checkWallDeadline = null): array
{
    $url = llama_proxy_admin_base_url().'/__llama_proxy/cache/stats';
    $headerArgs = llama_proxy_admin_curl_headers();
    $headerShell = '';
    foreach ($headerArgs as $part) {
        $headerShell .= ' '.escapeshellarg($part);
    }

    $shellTimeout = 8;
    $curlMax = 5;
    if (null !== $checkWallDeadline) {
        $remaining = $checkWallDeadline - (hrtime(true) / 1e9);
        if ($remaining <= 0.0) {
            throw new \RuntimeException('castor check exceeded absolute wall clock before llama-proxy cache stats');
        }
        $shellTimeout = max(1, min($shellTimeout, (int) floor($remaining)));
        $curlMax = max(1, min($curlMax, $shellTimeout));
    }

    $cmd = 'timeout --kill-after=3s '.$shellTimeout.'s curl -sS -m '.$curlMax.' -f'.$headerShell.' '.escapeshellarg($url);
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
 *
 * @param float|null $checkWallDeadline absolute hrtime-seconds deadline from check() entry
 */
function begin_castor_check_llama_proxy_cache_guard(?float $checkWallDeadline = null): ?int
{
    if (!llama_proxy_cache_guard_enabled()) {
        echo "llama-proxy cache guard: disabled (HATFIELD_LLM_CACHE_GUARD=0)\n";

        return null;
    }

    $stats = fetch_llama_proxy_cache_stats($checkWallDeadline);
    $entries = $stats['entries'];
    echo 'llama-proxy cache guard: baseline entries='.$entries."\n";

    return $entries;
}

function assert_castor_check_llama_proxy_cache_unchanged(?int $baselineEntries, ?float $checkWallDeadline = null): void
{
    if (null === $baselineEntries) {
        return;
    }

    $stats = fetch_llama_proxy_cache_stats($checkWallDeadline);
    $after = $stats['entries'];
    if ($after > $baselineEntries) {
        throw new \RuntimeException(\sprintf("llama-proxy cache grew from %d to %d entries during `castor check` — uncached live LLM request(s) occurred.\nWarm the proxy cache first: run `castor test:llm-real`, verify `curl %s/__llama_proxy/cache/stats`, then rerun `castor check`.\n".'After clearing the proxy cache you must warm again before the gate passes.', $baselineEntries, $after, llama_proxy_admin_base_url()));
    }

    echo 'llama-proxy cache guard: ok (entries '.$baselineEntries.' → '.$after.")\n";
}

/**
 * @param float|null $checkWallDeadline absolute hrtime-seconds deadline from check() entry
 */
function check_llm_generation_ready(?float $checkWallDeadline = null): void
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

    $shellTimeout = 15;
    $curlMax = 10;
    if (null !== $checkWallDeadline) {
        $remaining = $checkWallDeadline - (hrtime(true) / 1e9);
        if ($remaining <= 0.0) {
            throw new \RuntimeException('castor check exceeded absolute wall clock before llm generation preflight');
        }
        $shellTimeout = max(1, min($shellTimeout, (int) floor($remaining)));
        $curlMax = max(1, min($curlMax, $shellTimeout));
    }

    $cmd = qa_check_run_env_command().' timeout --kill-after=5s '.$shellTimeout.'s curl -sS -m '.$curlMax.' -o /dev/null -w "%{http_code}"'
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

/**
 * Absolute ignored root for ShipMonk dead-code Symfony DIC warmup + PHPStan tmp.
 *
 * Pinned under var/ so standalone `castor dead-code` and the check lane never
 * consume a developer `.hatfield/cache/dev` XML or a QA-run HATFIELD_CACHE_DIR.
 */
function dead_code_cache_root_dir(): string
{
    $root = project_root_dir();
    $dir = $root.'/var/phpstan-dead-code';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new \RuntimeException(\sprintf('Unable to create dead-code cache directory "%s".', $dir));
    }

    return $dir;
}

/**
 * Warm a fresh Symfony DIC XML under var/phpstan-dead-code/ and return its path.
 *
 * Always sets HATFIELD_CACHE_DIR to the same ignored root so Kernel writes the
 * container beside the copied analyser input. Deletes a stale target first so a
 * fresh checkout cannot keep an old developer XML.
 */
function ensure_dead_code_symfony_container_xml(): string
{
    $root = project_root_dir();
    $cacheRoot = dead_code_cache_root_dir();
    $target = $cacheRoot.'/symfony-container.xml';
    $source = $cacheRoot.'/dev/Ineersa_CodingAgent_KernelDevDebugContainer.xml';

    if (is_file($target) && !unlink($target)) {
        throw new \RuntimeException(\sprintf('Unable to remove stale dead-code container XML "%s".', $target));
    }

    // Override after qa_observability_env_command(): that helper may export a
    // standalone QA HATFIELD_CACHE_DIR, but dead-code warmup must stay pinned.
    $cmd = qa_observability_env_command()
        .' HATFIELD_CACHE_DIR='.escapeshellarg($cacheRoot)
        .' APP_ENV=dev APP_DEBUG=1 '
        .escapeshellarg(\PHP_BINARY).' '
        .escapeshellarg($root.'/bin/console')
        .' about --no-ansi --no-interaction';
    $about = run_quiet_command($cmd);
    if (0 !== $about->getExitCode()) {
        $detail = trim($about->getErrorOutput()."\n".$about->getOutput());
        throw new \RuntimeException('Failed warming Symfony container for dead-code detection under '.$cacheRoot.('' !== $detail ? ': '.$detail : '.'));
    }

    if (!is_file($source)) {
        throw new \RuntimeException(\sprintf('Symfony container XML missing after dead-code warmup. Expected "%s" under HATFIELD_CACHE_DIR=%s.', $source, $cacheRoot));
    }

    if (!copy($source, $target)) {
        throw new \RuntimeException(\sprintf('Unable to copy Symfony container XML to "%s".', $target));
    }

    return $target;
}

/**
 * Absolute path of the ShipMonk dead-code baseline file.
 */
function dead_code_baseline_path(): string
{
    return project_root_dir().'/phpstan.dead-code-baseline.neon';
}

/**
 * Absolute path of the dedicated dead-code PHPStan config.
 */
function dead_code_phpstan_config_path(): string
{
    return project_root_dir().'/phpstan.dead-code.neon';
}

/**
 * Build the PHPStan command that regenerates phpstan.dead-code-baseline.neon.
 *
 * Baseline generation cannot use --error-format=json (conflicts with
 * --generate-baseline). LLM_MODE still needs quiet, non-TTY output.
 */
function dead_code_baseline_phpstan_command(): string
{
    return qa_observability_env_command().' '.
        \PHP_BINARY.' vendor/bin/phpstan analyse -c '.
        escapeshellarg(dead_code_phpstan_config_path()).
        ' --no-progress --generate-baseline '.
        escapeshellarg(dead_code_baseline_path()).
        (is_llm_mode() ? ' --no-ansi' : '');
}

/**
 * Valid empty ShipMonk baseline include used only while regenerating.
 *
 * PHPStan rejects --generate-baseline when the current baseline still points at
 * deleted source paths and reportUnmatchedIgnoredErrors remains true. The
 * maintenance command therefore swaps in this empty include for the duration of
 * generation, then restores the previous baseline if generation fails.
 */
function dead_code_empty_baseline_contents(): string
{
    return "parameters:\n\tignoreErrors: []\n";
}

/**
 * Regenerate a ShipMonk dead-code baseline file after source deletions.
 *
 * Keeps reportUnmatchedIgnoredErrors: true for ordinary detector runs. Temporarily
 * replaces the baseline include with a valid empty baseline, runs $phpstanCommand,
 * and restores the previous baseline file if generation fails.
 *
 * @return array{exitCode: int, output: string}
 */
function regenerate_dead_code_baseline_at(string $baselinePath, string $phpstanCommand, ?string $workingDirectory = null): array
{
    $backupPath = $baselinePath.'.pre-regen';
    $hadBaseline = is_file($baselinePath);
    $previousContents = $hadBaseline ? file_get_contents($baselinePath) : null;
    if ($hadBaseline && false === $previousContents) {
        throw new \RuntimeException(\sprintf('Unable to read dead-code baseline "%s".', $baselinePath));
    }

    if ($hadBaseline) {
        if (!copy($baselinePath, $backupPath)) {
            throw new \RuntimeException(\sprintf('Unable to back up dead-code baseline to "%s".', $backupPath));
        }
    } elseif (is_file($backupPath) && !unlink($backupPath)) {
        throw new \RuntimeException(\sprintf('Unable to remove stale dead-code baseline backup "%s".', $backupPath));
    }

    if (false === file_put_contents($baselinePath, dead_code_empty_baseline_contents())) {
        throw new \RuntimeException(\sprintf('Unable to write temporary empty dead-code baseline "%s".', $baselinePath));
    }

    $process = Process::fromShellCommandline($phpstanCommand, $workingDirectory ?? project_root_dir());
    $process->setTimeout(null);
    $process->run();
    $exitCode = $process->getExitCode() ?? 1;
    $output = $process->getOutput().$process->getErrorOutput();

    if (0 !== $exitCode) {
        if ($hadBaseline) {
            if (false === file_put_contents($baselinePath, $previousContents)) {
                throw new \RuntimeException(\sprintf('Dead-code baseline generation failed (exit code %d) and restoring "%s" also failed.', $exitCode, $baselinePath));
            }
        } elseif (is_file($baselinePath) && !unlink($baselinePath)) {
            throw new \RuntimeException(\sprintf('Dead-code baseline generation failed (exit code %d) and removing temporary "%s" also failed.', $exitCode, $baselinePath));
        }
    }

    if (is_file($backupPath) && !unlink($backupPath)) {
        throw new \RuntimeException(\sprintf('Unable to remove dead-code baseline backup "%s".', $backupPath));
    }

    return [
        'exitCode' => $exitCode,
        'output' => $output,
    ];
}

/**
 * Regenerate phpstan.dead-code-baseline.neon after source deletions.
 *
 * @return array{exitCode: int, output: string}
 */
function regenerate_dead_code_baseline(): array
{
    return regenerate_dead_code_baseline_at(
        dead_code_baseline_path(),
        dead_code_baseline_phpstan_command(),
        project_root_dir(),
    );
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
