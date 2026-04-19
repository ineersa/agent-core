<?php

declare(strict_types=1);

namespace dev;

use Castor\Attribute\AsTask;

use function CastorTasks\dev_php_exec;
use function CastorTasks\is_llm_mode;
use function CastorTasks\persist_process_output;
use function CastorTasks\phpunit_inputs_available;
use function CastorTasks\relative_report_path;
use function CastorTasks\report_path;
use function CastorTasks\run_quiet_command;
use function CastorTasks\summarize_junit_xml;
use function CastorTasks\summarize_php_cs_fixer_json;
use function CastorTasks\summarize_phpstan_json;
use function CastorTasks\summarize_summaries_log;
use function CastorTasks\write_empty_junit_report;

#[AsTask(description: 'Run composer command')]
function composer(string $cmd): void
{
    dev_php_exec('composer '.$cmd);
}

#[AsTask(description: 'Install PHP dependencies')]
function composer_install(): void
{
    dev_php_exec('composer install --no-interaction');
}

#[AsTask(description: 'Update PHP dependencies')]
function composer_update(): void
{
    dev_php_exec('composer update');
}

#[AsTask(description: 'Run PHPUnit tests (LLM_MODE=true => concise output + JUnit report)')]
function test(): void
{
    if (!phpunit_inputs_available()) {
        write_empty_junit_report('phpunit.junit.xml');
        echo \sprintf(
            'test: skipped (no phpunit config/tests); junit=%s',
            relative_report_path('phpunit.junit.xml')
        ).\PHP_EOL;

        return;
    }

    if (!is_llm_mode()) {
        dev_php_exec('vendor/bin/phpunit');

        return;
    }

    $junitPath = report_path('phpunit.junit.xml');
    $command = \sprintf(
        'vendor/bin/phpunit --colors=never --no-progress --no-results --log-junit %s',
        escapeshellarg($junitPath)
    );

    $process = run_quiet_command($command);
    persist_process_output($process, 'phpunit.log');

    $summary = summarize_junit_xml($junitPath);

    if (0 !== $process->getExitCode()) {
        throw new \RuntimeException(\sprintf('test failed (%s); junit=%s; log=%s', $summary, relative_report_path('phpunit.junit.xml'), relative_report_path('phpunit.log')));
    }

    echo \sprintf(
        'test: ok (%s); junit=%s',
        $summary,
        relative_report_path('phpunit.junit.xml')
    ).\PHP_EOL;
}

#[AsTask(description: 'Run PHP CS Fixer (LLM_MODE=true => concise output)')]
function cs_fix(): void
{
    $command = 'vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php';

    if (!is_llm_mode()) {
        dev_php_exec($command);

        return;
    }

    $process = run_quiet_command($command.' --format=json --show-progress=none --no-ansi');
    persist_process_output($process, 'php-cs-fixer.log');

    $stdout = trim($process->getOutput());
    if ('' !== $stdout) {
        file_put_contents(report_path('php-cs-fixer.json'), $stdout.\PHP_EOL);
    }

    $summary = summarize_php_cs_fixer_json($stdout);

    if (0 !== $process->getExitCode()) {
        throw new \RuntimeException(\sprintf('cs-fix failed (%s); report=%s; log=%s', $summary, relative_report_path('php-cs-fixer.json'), relative_report_path('php-cs-fixer.log')));
    }

    echo \sprintf(
        'cs-fix: ok (%s)',
        $summary
    ).\PHP_EOL;
}

#[AsTask(description: 'Run PHPStan (LLM_MODE=true => concise output + JSON report)')]
function phpstan(): void
{
    $command = 'vendor/bin/phpstan analyse -c phpstan.dist.neon';

    if (!is_llm_mode()) {
        dev_php_exec($command);

        return;
    }

    $process = run_quiet_command($command.' --error-format=json --no-progress --no-ansi');
    persist_process_output($process, 'phpstan.log');

    $stdout = trim($process->getOutput());
    if ('' !== $stdout) {
        file_put_contents(report_path('phpstan.json'), $stdout.\PHP_EOL);
    }

    $summary = summarize_phpstan_json($stdout);

    if (0 !== $process->getExitCode()) {
        throw new \RuntimeException(\sprintf('phpstan failed (%s); report=%s; log=%s', $summary, relative_report_path('phpstan.json'), relative_report_path('phpstan.log')));
    }

    echo \sprintf(
        'phpstan: ok (%s)',
        $summary
    ).\PHP_EOL;
}

#[AsTask(description: 'Run cs-fix, phpstan, tests, and strict summary validation')]
function check(): void
{
    $failures = [];

    foreach ([
        'cs-fix' => static function (): void {
            cs_fix();
        },
        'phpstan' => static function (): void {
            phpstan();
        },
        'test' => static function (): void {
            test();
        },
        'summaries' => static function (): void {
            summaries();
        },
    ] as $step => $runner) {
        try {
            $runner();
        } catch (\Throwable $exception) {
            $failures[] = \sprintf('%s: %s', $step, $exception->getMessage());
        }
    }

    if ([] !== $failures) {
        throw new \RuntimeException("quality failed:\n - ".implode("\n - ", $failures));
    }

    echo 'quality: ok'.\PHP_EOL;
}

#[AsTask(description: 'Alias of dev:check')]
function quality(): void
{
    check();
}

/**
 * Generate per-file method indexes from docblock summaries.
 *
 * Options: --all, --changed, --force, --dry-run, --strict, --migrate, --skip-namespace
 * Pass specific files/dirs as arguments to process only those.
 *
 * @param list<string> $extraArgs
 */
#[AsTask(description: 'Generate per-file method indexes from docblock summaries (no LLM)')]
function index_methods(array $extraArgs = []): void
{
    $command = 'php scripts/generate-method-index.php';

    $args = [] !== $extraArgs ? $extraArgs : \array_slice($_SERVER['argv'], 2);
    if ([] !== $args) {
        $command .= ' '.implode(' ', array_map('escapeshellarg', $args));
    }

    dev_php_exec($command);
}

/** Validate that all classes and methods have docblock summaries. */
#[AsTask(description: 'Check docblock summary coverage (read-only)')]
function summaries(): void
{
    $command = 'php scripts/generate-method-index.php --strict --all';

    if (!is_llm_mode()) {
        dev_php_exec($command);

        return;
    }

    $process = run_quiet_command($command);
    persist_process_output($process, 'summaries.log');

    $missing = summarize_summaries_log(trim($process->getOutput()));

    if (0 !== $process->getExitCode()) {
        throw new \RuntimeException(\sprintf('summaries failed (%s); report=%s', $missing, relative_report_path('summaries.log')));
    }

    echo \sprintf('summaries: ok (%s)', $missing).\PHP_EOL;
}
