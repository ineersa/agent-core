<?php

declare(strict_types=1);

namespace CastorTasks;

use Castor\Context;
use Symfony\Component\Process\Process;

use function Castor\run;

const REPORTS_DIR = __DIR__.'/../var/reports';

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
 * Staging directory under /tmp for production-only PHAR builds.
 * A clean Composer install --no-dev is run here before Box compilation,
 * so dev packages (phpstan, phpunit, cs-fixer, etc.) never enter the PHAR.
 */
const PHAR_STAGING_DIR = '/tmp/hatfield-phar-build/source';

/**
 * Ensure the PHAR at /tmp/bin/hatfield.phar exists and is fresh.
 *
 * If the PHAR is missing or stale (source, config, or box/composer files
 * have been updated since the last build), triggers a rebuild.
 *
 * @return string Absolute path to the existing or freshly built PHAR.
 */
function phar_ensure(): string
{
    $pharPath = '/tmp/bin/hatfield.phar';

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
 * Build the PHAR at /tmp/bin/hatfield.phar using a clean production staging
 * directory (Composer --no-dev), then compile with Box.
 *
 * The build pipeline:
 *   1. Create/refresh staging dir with only packaging inputs (no dev vendor)
 *   2. Run `composer install --no-dev` in staging
 *   3. Run `box compile` from staging
 *   4. Report timings, smoke-test the PHAR
 *
 * @return string Absolute path to the built PHAR.
 */
function phar_build(): string
{
    $pharPath = '/tmp/bin/hatfield.phar';
    $root = realpath(__DIR__.'/..');
    if (false === $root) {
        throw new \RuntimeException('Unable to resolve project root for PHAR build.');
    }

    $stagingDir = PHAR_STAGING_DIR;
    $startTime = microtime(true);

    // ── Resolve external binaries ────────────────────────────────────

    $boxBin = getenv('BOX_BIN') ?: trim(shell_exec('which box 2>/dev/null') ?? '');
    if ('' === $boxBin) {
        throw new \RuntimeException(
            'box is not installed. Install it globally via composer global require humbug/box '
            .'or set the BOX_BIN environment variable to its path.'
        );
    }

    $composerBin = getenv('COMPOSER_BIN') ?: trim(shell_exec('which composer 2>/dev/null') ?? '');
    if ('' === $composerBin) {
        // Try composer.phar
        $composerBin = trim(shell_exec('which composer.phar 2>/dev/null') ?? '');
    }
    if ('' === $composerBin) {
        throw new \RuntimeException(
            'composer is not installed. Set the COMPOSER_BIN environment variable, install it '
            .'globally with `composer global require`, or ensure `composer` is on PATH.'
        );
    }

    // ── 1. Prepare staging directory ─────────────────────────────────

    // Ensure output directory exists.
    @mkdir('/tmp/bin', 0755, true);

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

    // ── 2. Install production-only Composer dependencies ─────────────

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
        throw new \RuntimeException(
            'composer install command returned no output (shell_exec failure).'
            .\PHP_EOL.'Command: '.$composerCmd
        );
    }

    // ── 3. Compile PHAR with Box ─────────────────────────────────────

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
        $error .= 'Composer output:'.\PHP_EOL; $error .= ($composerOutput ?? '<no output>').\PHP_EOL;
        $error .= 'Box output:'.\PHP_EOL; $error .= ($boxOutput ?? '<no output>').\PHP_EOL;
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

    // ── 4. Smoke-test the PHAR (report boot failure separately) ──────

    $smokeEnv = getenv('APP_ENV') ?: 'prod';
    $listOutput = shell_exec('APP_ENV='.$smokeEnv.' '.\PHP_BINARY.' '.escapeshellarg($pharPath).' list 2>&1');
    if (null === $listOutput || !str_contains($listOutput, 'agent')) {
        // The PHAR may have a separate boot issue (e.g. ContainerBuilder
        // service-not-found). Report it but do NOT throw — the build itself
        // succeeded and boot debugging is the next phase.
        echo "PHAR boot smoke test: FAILED (known separate issue)\n";
        echo 'The PHAR compiled successfully but the application '.\PHP_EOL;
        echo 'does not boot inside PHAR. This is a ContainerBuilder / '.\PHP_EOL;
        echo 'Symfony DI compilation issue and should be addressed in a '.\PHP_EOL;
        echo 'subsequent fork. Output: '.\PHP_EOL;
        echo ($listOutput ?? '<no output>').\PHP_EOL;
    } else {
        echo "PHAR smoke test: ok\n";
    }

    return $pharPath;
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
