<?php

declare(strict_types=1);

use Castor\Attribute\AsTask;

use function Castor\run;

/**
 * Run full QA: deptrac, phpunit, phpstan, cs-fixer check.
 */
#[AsTask(description: 'Run full QA (deptrac, phpunit, phpstan, cs-fixer)')]
function check(): void
{
    $failures = [];

    foreach ([
        'deptrac' => static fn () => deptrac(),
        'test' => static fn () => test(),
        'phpstan' => static fn () => phpstan(),
        'cs-check' => static fn () => cs_check(),
    ] as $step => $runner) {
        try {
            $runner();
        } catch (Throwable $exception) {
            $failures[] = sprintf('%s: %s', $step, $exception->getMessage());
        }
    }

    if ([] !== $failures) {
        throw new RuntimeException("quality failed:\n - ".implode("\n - ", $failures));
    }

    echo 'quality: ok'.\PHP_EOL;
}

/**
 * Alias for check().
 */
#[AsTask(description: 'Alias for check')]
function quality(): void
{
    check();
}

/**
 * Run deptrac architecture boundary validation.
 */
#[AsTask(description: 'Run Deptrac architecture boundary validation')]
function deptrac(): void
{
    run('vendor/bin/deptrac analyze --config-file=depfile.yaml --no-progress');
}

/**
 * Run PHPUnit tests.
 */
#[AsTask(description: 'Run PHPUnit tests')]
function test(): void
{
    run('vendor/bin/phpunit --colors=always');
}

/**
 * Run CS fixer (fix in place).
 */
#[AsTask(description: 'Run PHP CS Fixer (fix in place)')]
function cs_fix(): void
{
    run('vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php');
}

/**
 * Run CS fixer dry-run (check only).
 */
#[AsTask(description: 'Run PHP CS Fixer (dry-run, check only)')]
function cs_check(): void
{
    run('vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff');
}

/**
 * Run PHPStan static analysis.
 */
#[AsTask(description: 'Run PHPStan static analysis')]
function phpstan(): void
{
    run('vendor/bin/phpstan analyse -c phpstan.dist.neon --no-progress');
}

/**
 * Remove generated QA caches.
 */
#[AsTask(name: 'cache:clear', description: 'Remove generated QA caches (deptrac, php-cs-fixer, phpstan)')]
function cache_clear(): void
{
    $files = [
        __DIR__.'/../.deptrac.cache',
        __DIR__.'/../.php-cs-fixer.cache',
    ];
    $dirs = [
        __DIR__.'/../var/phpstan',
    ];

    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
            echo 'Removed '.basename($file).\PHP_EOL;
        }
    }

    foreach ($dirs as $dir) {
        if (is_dir($dir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $entry) {
                $entry->isDir() ? rmdir((string) $entry) : unlink((string) $entry);
            }
            rmdir($dir);
            echo 'Removed '.basename($dir).' directory'.\PHP_EOL;
        }
    }

    echo 'cache:clear done'."\n";
}

/**
 * Install dependencies.
 */
#[AsTask(description: 'Install dependencies')]
function install(): void
{
    run('composer install --no-interaction');
}
