<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\ControllerE2eTestCase;
use PHPUnit\Framework\TestCase;

/**
 * Env-mapping contract for controller E2E cache reuse.
 *
 * Unit-level only: uses a test-local concrete harness to call the protected
 * helper without replay fixtures, temp project setup, or process spawning.
 */
final class ControllerE2eCacheEnvTest extends TestCase
{
    public function testReusesQaCacheDirWhenPresent(): void
    {
        $previous = $this->snapshot('HATFIELD_CACHE_DIR');
        $qaCache = '/tmp/hatfield-qa-cache-controller-e2e-env';

        try {
            $this->put('HATFIELD_CACHE_DIR', $qaCache);
            $env = $this->harness()->exposeControllerSubprocessEnv();
            $this->assertSame(['HATFIELD_CACHE_DIR' => $qaCache], $env);
        } finally {
            $this->restore('HATFIELD_CACHE_DIR', $previous);
        }
    }

    public function testOmitsCacheOverrideWhenQaCacheUnset(): void
    {
        $previous = $this->snapshot('HATFIELD_CACHE_DIR');

        try {
            $this->clear('HATFIELD_CACHE_DIR');
            $env = $this->harness()->exposeControllerSubprocessEnv();
            $this->assertSame([], $env);
        } finally {
            $this->restore('HATFIELD_CACHE_DIR', $previous);
        }
    }

    public function testOmitsCacheOverrideWhenQaCacheEmpty(): void
    {
        $previous = $this->snapshot('HATFIELD_CACHE_DIR');

        try {
            $this->put('HATFIELD_CACHE_DIR', '   ');
            $env = $this->harness()->exposeControllerSubprocessEnv();
            $this->assertSame([], $env);
        } finally {
            $this->restore('HATFIELD_CACHE_DIR', $previous);
        }
    }

    private function harness(): ControllerE2eCacheEnvHarness
    {
        return new ControllerE2eCacheEnvHarness('ControllerE2eCacheEnvHarness');
    }

    /**
     * @return array{process: string|false, env: mixed, server: mixed, envHadKey: bool, serverHadKey: bool}
     */
    private function snapshot(string $key): array
    {
        return [
            'process' => getenv($key),
            'env' => $_ENV[$key] ?? null,
            'server' => $_SERVER[$key] ?? null,
            'envHadKey' => \array_key_exists($key, $_ENV),
            'serverHadKey' => \array_key_exists($key, $_SERVER),
        ];
    }

    private function put(string $key, string $value): void
    {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function clear(string $key): void
    {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    /**
     * @param array{process: string|false, env: mixed, server: mixed, envHadKey: bool, serverHadKey: bool} $saved
     */
    private function restore(string $key, array $saved): void
    {
        if (false === $saved['process']) {
            putenv($key);
        } else {
            putenv($key.'='.$saved['process']);
        }

        if (!$saved['envHadKey']) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $saved['env'];
        }

        if (!$saved['serverHadKey']) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $saved['server'];
        }
    }
}

/**
 * Test-local concrete harness that exposes only the env-mapping helper.
 *
 * Overrides setUp/tearDown so construction never creates a temp project or
 * requires LLAMA_CPP_SMOKE_TEST / replay fixtures.
 */
final class ControllerE2eCacheEnvHarness extends ControllerE2eTestCase
{
    /**
     * @return array<string, string>
     */
    public function exposeControllerSubprocessEnv(): array
    {
        return $this->controllerSubprocessEnv();
    }

    protected function tempDirPrefix(): string
    {
        return 'unused-controller-e2e-cache-env';
    }

    protected function setUp(): void
    {
        TestCase::setUp();
    }

    protected function tearDown(): void
    {
        TestCase::tearDown();
    }
}
