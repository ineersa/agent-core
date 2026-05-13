<?php

declare(strict_types=1);

namespace dev;

use Castor\Attribute\AsTask;

use function Castor\run;

#[AsTask(description: 'Run PHPUnit tests')]
function test(): void
{
    if (!is_file(__DIR__.'/../phpunit.xml.dist') && !is_file(__DIR__.'/../phpunit.xml')) {
        echo 'test: skipped (no phpunit config)'.\PHP_EOL;
        return;
    }

    run('vendor/bin/phpunit --colors=always');
}

#[AsTask(description: 'Run Deptrac architecture boundary validation')]
function deptrac(): void
{
    if (!is_file(__DIR__.'/../depfile.yaml')) {
        echo 'deptrac: skipped (no depfile.yaml)'.\PHP_EOL;
        return;
    }

    run('vendor/bin/deptrac analyze --config-file=depfile.yaml --no-progress');
}

#[AsTask(description: 'Run cs-fix, deptrac, and tests (coding-agent QA)')]
function check(): void
{
    $failures = [];

    foreach ([
        'deptrac' => static function (): void {
            deptrac();
        },
        'test' => static function (): void {
            test();
        },
    ] as $step => $runner) {
        try {
            $runner();
        } catch (\Throwable $exception) {
            $failures[] = \sprintf('%s: %s', $step, $exception->getMessage());
        }
    }

    if ([] !== $failures) {
        throw new \RuntimeException("coding-agent quality failed:\n - ".implode("\n - ", $failures));
    }

    echo 'coding-agent: ok'.\PHP_EOL;
}

#[AsTask(description: 'Alias of dev:check')]
function quality(): void
{
    check();
}
