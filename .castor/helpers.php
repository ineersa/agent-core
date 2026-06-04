<?php

declare(strict_types=1);

namespace CastorTasks;

use Castor\Context;
use Symfony\Component\Process\Process;

use function Castor\run;

const REPORTS_DIR = __DIR__.'/../var/reports';

// ─── PHAR packaging constants ──────────────────────────────────────────
// Centralised so output paths, staging directories, and tooling references
// have a single source of truth.  Every function that needs the PHAR path
// calls hatfield_phar_path() instead of hard-coding /tmp/bin/hatfield.phar.
//
// Environment overrides (optional):
//   HATFIELD_PHAR_PATH        — Override the PHAR output file path.
//   HATFIELD_PHAR_STAGING_DIR — Override the production Composer staging dir.
//   HATFIELD_PHAR_BOX_BIN     — Override the Box binary (defaults to
//                                tools/phar/vendor/bin/box when the isolated
//                                toolchain is present).

/** Default PHAR output path. */
const HATFIELD_PHAR_DEFAULT = '/tmp/bin/hatfield.phar';

/** Default staging directory for production-only Composer installs. */
const HATFIELD_PHAR_STAGING_DEFAULT = '/tmp/hatfield-phar-build/source';

/**
 * Resolve the PHAR output path.
 *
 * Respects HATFIELD_PHAR_PATH if set; otherwise returns the hard default
 * /tmp/bin/hatfield.phar.  Relative overrides are resolved against the
 * project root directory.
 */
function hatfield_phar_path(): string
{
    $override = getenv('HATFIELD_PHAR_PATH');

    if (false !== $override && '' !== $override) {
        if (str_starts_with($override, '/')) {
            return $override;
        }

        $root = realpath(__DIR__.'/..');
        if (false !== $root) {
            return $root.'/'.$override;
        }
    }

    return HATFIELD_PHAR_DEFAULT;
}

/**
 * Resolve the PHAR staging directory.
 *
 * Respects HATFIELD_PHAR_STAGING_DIR if set; otherwise returns the hard
 * default /tmp/hatfield-phar-build/source.
 */
function hatfield_phar_staging_dir(): string
{
    $override = getenv('HATFIELD_PHAR_STAGING_DIR');

    if (false !== $override && '' !== $override) {
        return $override;
    }

    return HATFIELD_PHAR_STAGING_DEFAULT;
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
        if (is_executable($localBoxBin)) {
            return $localBoxBin;
        }
        // If install somehow failed, fall through to the global lookup.
        if (null !== $output && '' !== trim($output)) {
            echo "  tools/phar/ composer install output:\n  ".str_replace("\n", "\n  ", trim($output))."\n";
        }
    }

    // 3. Global Box (PATH, or the legacy BOX_BIN env).
    $globalBox = getenv('BOX_BIN') ?: trim(shell_exec('which box 2>/dev/null') ?? '');
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
    $composerBin = getenv('COMPOSER_BIN') ?: trim(shell_exec('which composer 2>/dev/null') ?? '');
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
function phar_ensure(): string
{
    $pharPath = hatfield_phar_path();

    if (is_file($pharPath) && is_readable($pharPath)) {
        // Quick stale detection: compare mtime against key meta-files
        // and latest source/config file.
        $pharMtime = filemtime($pharPath);
        $root = realpath(__DIR__.'/..');
        if (false === $root) {
            throw new \RuntimeException('Unable to resolve project root for PHAR ensure.');
        }

        // Check meta-files that directly affect the build output.
        foreach (['box.json', 'composer.lock'] as $file) {
            $path = $root.'/'.$file;
            if (is_file($path) && filemtime($path) > $pharMtime) {
                echo "PHAR stale: {$file} changed. Rebuilding.\n";
                phar_build();

                return $pharPath;
            }
        }

        // Check the most recently changed source/config file.
        $latestSrc = trim(shell_exec(
            'find '.escapeshellarg($root.'/src').' '.escapeshellarg($root.'/config')
            .' -type f -printf "%T@\\n" 2>/dev/null | sort -rn | head -1'
        ) ?? '');
        if ('' !== $latestSrc && (float) $latestSrc > (float) $pharMtime) {
            echo "PHAR stale: source/config files changed. Rebuilding.\n";
            phar_build();

            return $pharPath;
        }

        return $pharPath;
    }

    // Build if missing.
    echo "PHAR not found. Building.\n";
    phar_build();

    return $pharPath;
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

    $stagingDir = hatfield_phar_staging_dir();
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
    $composerEnv = getenv('APP_ENV') ?: 'prod';
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

    $boxEnv = getenv('APP_ENV') ?: 'prod';
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
        $error .= ($composerOutput ?? '<no output>').\PHP_EOL;
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
    $tmpCwd = sys_get_temp_dir().'/hatfield-phar-smoke-'.getmypid();
    @mkdir($tmpCwd, 0755, true);
    if (!is_dir($tmpCwd)) {
        echo "PHAR smoke test: SKIP (could not create isolated cwd: {$tmpCwd})\n";

        return;
    }

    $smokeEnv = getenv('APP_ENV') ?: 'prod';
    $failed = [];
    $phpBin = \PHP_BINARY;

    // 1. `list` — verifies the Symfony Console application boots and
    //    all commands (including `agent`) are registered.
    $listOutput = shell_exec(
        'cd '.escapeshellarg($tmpCwd).' && APP_ENV='.$smokeEnv.' '.$phpBin.' '
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
        'cd '.escapeshellarg($tmpCwd).' && APP_ENV='.$smokeEnv.' '.$phpBin.' '
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
        'cd '.escapeshellarg($tmpCwd).' && APP_ENV='.$smokeEnv.' '.$phpBin.' '
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

    // Clean up smoke artifacts.
    shell_exec('rm -rf '.escapeshellarg($tmpCwd));

    if ([] !== $failed) {
        echo 'PHAR smoke test: FAIL ('.\count($failed).' failures: '.implode(', ', $failed).")\n";
        echo "  The PHAR compiled successfully but one or more boot checks failed.\n";
    } else {
        echo "PHAR smoke test: ok\n";
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
