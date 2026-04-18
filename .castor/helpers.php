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
