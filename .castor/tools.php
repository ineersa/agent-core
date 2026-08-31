<?php

declare(strict_types=1);

/**
 * Static analysis, code-style, and security-audit tasks.
 *
 * These are independent tooling tasks — no live LLM dependency,
 * no PHAR dependency, no process-tree management.
 */

use Castor\Attribute\AsTask;

use function CastorTasks\ensure_dead_code_symfony_container_xml;
use function CastorTasks\is_llm_mode;
use function CastorTasks\regenerate_dead_code_baseline;
use function CastorTasks\summarize_deptrac_json;
use function CastorTasks\summarize_php_cs_fixer_json;
use function CastorTasks\summarize_phpstan_json;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/shared.php';
require_once __DIR__.'/env.php';

// ─── Static analysis ──────────────────────────────────────────────

#[AsTask(name: 'deptrac', description: 'Run Deptrac architecture validation')]
function deptrac(): void
{
    $cmd = qa_observability_env_command().' '.\PHP_BINARY.' vendor/bin/deptrac --config-file=depfile.yaml --no-progress --no-ansi'
        .(is_llm_mode() ? ' --formatter=json' : '');
    $exitCode = 0;
    $output = [];
    exec($cmd, $output, $exitCode);
    $output = implode("\n", $output);
    echo $output.\PHP_EOL;
    if (is_llm_mode()) {
        $summary = summarize_deptrac_json($output);
        if ('' !== $summary) {
            echo $summary;
        }
    }
    if (0 !== $exitCode) {
        fail_quality(sprintf('Deptrac failed with exit code %d', $exitCode));
    }
    exit(0);
}

#[AsTask(name: 'phpstan', description: 'Run PHPStan static analysis')]
function phpstan(?string $path = null): void
{
    $cmd = qa_observability_env_command().' '.\PHP_BINARY.' vendor/bin/phpstan analyse -c phpstan.dist.neon --no-progress'
        .(is_llm_mode() ? ' --error-format=json --no-ansi' : '');
    if (null !== $path) {
        $cmd .= ' '.$path;
    }
    $exitCode = 0;
    $output = [];
    exec($cmd, $output, $exitCode);
    $output = implode("\n", $output);
    echo $output.\PHP_EOL;
    if (is_llm_mode()) {
        $summary = summarize_phpstan_json($output);
        if ('' !== $summary) {
            echo $summary;
        }
    }
    if (0 !== $exitCode) {
        fail_quality(sprintf('PHPStan failed with exit code %d', $exitCode));
    }
}

#[AsTask(name: 'phpstan:baseline', description: 'Regenerate PHPStan baseline')]
function phpstan_baseline(): void
{
    passthru(qa_observability_env_command().' '.\PHP_BINARY.' vendor/bin/phpstan analyse -c phpstan.dist.neon --generate-baseline phpstan-baseline.neon', $exitCode);
    if (0 !== $exitCode) {
        fail_quality(sprintf('PHPStan baseline generation failed with exit code %d', $exitCode));
    }
}

#[AsTask(name: 'dead-code', description: 'Run ShipMonk dead-code detector')]
function dead_code(): void
{
    try {
        ensure_dead_code_symfony_container_xml();
    } catch (Throwable $e) {
        fail_quality($e->getMessage());
    }

    $cmd = qa_observability_env_command().' '.\PHP_BINARY.' vendor/bin/phpstan analyse -c phpstan.dead-code.neon --no-progress'
        .(is_llm_mode() ? ' --error-format=json --no-ansi' : '');
    $exitCode = 0;
    $output = [];
    exec($cmd, $output, $exitCode);
    $output = implode("\n", $output);
    echo $output.\PHP_EOL;
    if (is_llm_mode()) {
        $summary = summarize_phpstan_json($output);
        if ('' !== $summary) {
            echo $summary;
        }
    }
    if (0 !== $exitCode) {
        fail_quality(sprintf('Dead-code detector failed with exit code %d', $exitCode));
    }
}

#[AsTask(name: 'dead-code:baseline', description: 'Regenerate ShipMonk dead-code baseline')]
function dead_code_baseline(): void
{
    try {
        ensure_dead_code_symfony_container_xml();
        $result = regenerate_dead_code_baseline();
    } catch (Throwable $e) {
        fail_quality($e->getMessage());
    }

    echo $result['output'];
    if ('' !== $result['output'] && !str_ends_with($result['output'], "\n")) {
        echo \PHP_EOL;
    }
    if (0 !== $result['exitCode']) {
        fail_quality(sprintf('Dead-code baseline generation failed with exit code %d', $result['exitCode']));
    }
}

// ─── Coding style ─────────────────────────────────────────────────

#[AsTask(name: 'cs-fix', description: 'Fix coding style')]
function cs_fix(string $path = ''): void
{
    $cmd = qa_observability_env_command().' '.\PHP_BINARY.' vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --no-ansi'
        .(is_llm_mode() ? ' --format=json --show-progress=none' : ' --diff');
    if ('' !== $path) {
        $cmd .= ' '.escapeshellarg($path);
    }
    passthru($cmd, $exitCode);
    if (0 !== $exitCode) {
        fail_quality(sprintf('CS fixer failed with exit code %d', $exitCode));
    }
}

#[AsTask(name: 'cs-check', description: 'Check coding style (dry-run)')]
function cs_check(string $path = ''): void
{
    $cmd = qa_observability_env_command().' '.\PHP_BINARY.' vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --no-ansi'
        .(is_llm_mode() ? ' --format=json --show-progress=none' : ' --diff');
    if ('' !== $path) {
        $cmd .= ' '.escapeshellarg($path);
    }
    $exitCode = 0;
    $output = [];
    exec($cmd, $output, $exitCode);
    $output = implode("\n", $output);
    echo $output.\PHP_EOL;
    if (is_llm_mode()) {
        $summary = summarize_php_cs_fixer_json($output);
        if ('' !== $summary) {
            echo $summary;
        }
    }
    if (0 !== $exitCode) {
        fail_quality(sprintf('CS check failed with exit code %d', $exitCode));
    }
}

/**
 * Run static analysis (PHPStan + Deptrac).
 */
#[AsTask(name: 'analyse', description: 'Run static analysis (PHPStan + Deptrac)')]
function analyse(): void
{
    phpstan();
    deptrac();
}
