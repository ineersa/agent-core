<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Castor;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: standalone castor test migrate/filter must use an invocation-scoped
 * HATFIELD_CACHE_DIR (not shared .hatfield/cache/test), while castor check keeps
 * its run-scoped cache via qa_check_run_env_command().
 */
final class QaStandaloneTestCacheIsolationTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $envRestore = [];

    protected function tearDown(): void
    {
        foreach ($this->envRestore as $name => $previous) {
            if (null === $previous) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv($name.'='.$previous);
                $_ENV[$name] = $previous;
                $_SERVER[$name] = $previous;
            }
        }
        $this->envRestore = [];

        parent::tearDown();
    }

    public function testStandaloneHomePrefixExportsInvocationScopedCacheDir(): void
    {
        self::requireCastorFiles();
        $this->clearCacheEnv();

        $prefix = \CastorTasks\qa_test_home_shell_prefix();
        $cache = getenv('HATFIELD_CACHE_DIR');

        $this->assertIsString($cache);
        $this->assertNotSame('', $cache);
        $this->assertStringStartsWith('.hatfield/cache-test-pid-', $cache);
        $this->assertStringNotContainsString("HATFIELD_CACHE_DIR='.hatfield/cache'", $prefix);
        $this->assertStringContainsString('HATFIELD_CACHE_DIR='.escapeshellarg($cache), $prefix);

        $observability = qa_observability_env_command();
        $this->assertStringContainsString('HATFIELD_CACHE_DIR='.escapeshellarg($cache), $observability);
    }

    public function testFilteredPhpunitCommandUsesSameCacheAsMigrationPrefix(): void
    {
        self::requireCastorFiles();
        $this->clearCacheEnv();

        $migratePrefix = \CastorTasks\qa_test_home_shell_prefix();
        $cache = getenv('HATFIELD_CACHE_DIR');
        $this->assertIsString($cache);

        $phpunitCmd = qa_observability_env_command().' APP_ENV=test '.\PHP_BINARY.' vendor/bin/phpunit'
            .' --filter=QaStandaloneTestCacheIsolationTest'
            .' --exclude-group=tui-e2e-replay --exclude-group=llm-real --exclude-group=recording --exclude-group=controller-replay';

        $this->assertStringContainsString('HATFIELD_CACHE_DIR='.escapeshellarg($cache), $migratePrefix);
        $this->assertStringContainsString('HATFIELD_CACHE_DIR='.escapeshellarg($cache), $phpunitCmd);
        $this->assertSame($cache, getenv('HATFIELD_CACHE_DIR'));
    }

    public function testCheckRunEnvKeepsRunScopedCacheWhenQaRunIdSet(): void
    {
        self::requireCastorFiles();

        $qaRunId = 'qa-20260820-test-cache-isolation';
        $runCache = '.hatfield/cache-'.$qaRunId;
        $this->setEnv('HATFIELD_QA_RUN_ID', $qaRunId);
        $this->setEnv('HATFIELD_CACHE_DIR', $runCache);
        $this->setEnv('HATFIELD_QA_REPORTS_DIR', 'var/reports/'.$qaRunId);
        $this->setEnv('HATFIELD_QA_TMP_DIR', 'var/tmp/'.$qaRunId);

        $cmd = qa_check_run_env_command();

        $this->assertStringContainsString('HATFIELD_CACHE_DIR='.escapeshellarg($runCache), $cmd);
        $this->assertStringNotContainsString('cache-test-pid-', $cmd);
        $this->assertSame($runCache, getenv('HATFIELD_CACHE_DIR'));
    }

    public function testStandaloneCacheReusesExistingHatfieldCacheDir(): void
    {
        self::requireCastorFiles();

        $existing = '.hatfield/cache-preexisting-fixture';
        $this->setEnv('HATFIELD_CACHE_DIR', $existing);

        $resolved = \CastorTasks\qa_standalone_test_cache_dir();
        $prefix = \CastorTasks\qa_test_home_shell_prefix();

        $this->assertSame($existing, $resolved);
        $this->assertStringContainsString('HATFIELD_CACHE_DIR='.escapeshellarg($existing), $prefix);
    }

    private function clearCacheEnv(): void
    {
        $this->setEnv('HATFIELD_CACHE_DIR', null);
        $this->setEnv('HATFIELD_QA_RUN_ID', null);
    }

    private function setEnv(string $name, ?string $value): void
    {
        if (!\array_key_exists($name, $this->envRestore)) {
            $previous = getenv($name);
            $this->envRestore[$name] = false === $previous ? null : (string) $previous;
        }

        if (null === $value) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            return;
        }

        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private static function requireCastorFiles(): void
    {
        $root = ProjectDir::get();
        $helpersPhp = $root.'/.castor/helpers.php';
        $envPhp = $root.'/.castor/env.php';
        self::assertFileExists($helpersPhp);
        self::assertFileExists($envPhp);
        require_once $helpersPhp;
        require_once $envPhp;
        self::assertTrue(
            \function_exists('CastorTasks\qa_standalone_test_cache_dir'),
            'qa_standalone_test_cache_dir must load from .castor/helpers.php',
        );
        self::assertTrue(
            \function_exists('CastorTasks\qa_test_home_shell_prefix'),
            'qa_test_home_shell_prefix must load from .castor/helpers.php',
        );
        self::assertTrue(
            \function_exists('qa_observability_env_command'),
            'qa_observability_env_command must load from .castor/env.php',
        );
        self::assertTrue(
            \function_exists('qa_check_run_env_command'),
            'qa_check_run_env_command must load from .castor/env.php',
        );
    }
}
