<?php

declare(strict_types=1);

/**
 * Static analysis, code-style, and security-audit tasks.
 *
 * These are independent tooling tasks — no live LLM dependency,
 * no PHAR dependency, no process-tree management.
 */

use Castor\Attribute\AsTask;

use function CastorTasks\is_llm_mode;
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

// ─── Legacy / alias tasks ────────────────────────────────────────

/**
 * Run CS fixer (fix in place).
 *
 * Alias for cs-fix.  Kept for backwards compatibility.
 */
#[AsTask(description: 'Run PHP CS Fixer (fix in place)')]
function cs_fixer(): void
{
    cs_fix();
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

// ─── Audit ────────────────────────────────────────────────────────

#[AsTask(name: 'audit', description: 'Run Composer security audit')]
function audit(): void
{
    $cmd = \PHP_BINARY.' '.__DIR__.'/../vendor/bin/security-checker security:check '.__DIR__.'/../composer.lock';
    passthru($cmd, $exitCode);
    exit($exitCode);
}
