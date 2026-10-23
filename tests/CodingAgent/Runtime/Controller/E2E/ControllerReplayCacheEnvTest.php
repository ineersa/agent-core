<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Harness contract: controller-replay must reuse the Castor QA cache root when
 * present instead of compiling a fresh Symfony container under each tempDir.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class ControllerReplayCacheEnvTest extends ControllerReplayE2eTestCase
{
    public function testControllerSubprocessReusesQaCacheDirWhenPresent(): void
    {
        $previous = getenv('HATFIELD_CACHE_DIR');
        $qaCache = $this->projectDir.'/.hatfield/cache-qa-controller-replay-env';
        putenv('HATFIELD_CACHE_DIR='.$qaCache);
        $_ENV['HATFIELD_CACHE_DIR'] = $qaCache;
        $_SERVER['HATFIELD_CACHE_DIR'] = $qaCache;

        try {
            $env = $this->controllerSubprocessEnv();
            $this->assertSame(
                $qaCache,
                $env['HATFIELD_CACHE_DIR'] ?? null,
                'Controller E2E must inherit the QA run cache instead of a per-temp cold compile root.',
            );
            $this->assertStringNotContainsString(
                $this->tempDir,
                (string) ($env['HATFIELD_CACHE_DIR'] ?? ''),
                'Per-temp HATFIELD_CACHE_DIR overrides recreate cold Symfony compiles under gate load.',
            );
        } finally {
            if (false === $previous || '' === $previous) {
                putenv('HATFIELD_CACHE_DIR');
                unset($_ENV['HATFIELD_CACHE_DIR'], $_SERVER['HATFIELD_CACHE_DIR']);
            } else {
                putenv('HATFIELD_CACHE_DIR='.$previous);
                $_ENV['HATFIELD_CACHE_DIR'] = $previous;
                $_SERVER['HATFIELD_CACHE_DIR'] = $previous;
            }
        }
    }

    public function testControllerSubprocessOmitsCacheOverrideWhenQaCacheUnset(): void
    {
        $previous = getenv('HATFIELD_CACHE_DIR');
        putenv('HATFIELD_CACHE_DIR');
        unset($_ENV['HATFIELD_CACHE_DIR'], $_SERVER['HATFIELD_CACHE_DIR']);

        try {
            $env = $this->controllerSubprocessEnv();
            $this->assertArrayNotHasKey(
                'HATFIELD_CACHE_DIR',
                $env,
                'Without a QA cache root the subprocess should inherit ambient resolution, not invent a temp override.',
            );
        } finally {
            if (false === $previous || '' === $previous) {
                putenv('HATFIELD_CACHE_DIR');
                unset($_ENV['HATFIELD_CACHE_DIR'], $_SERVER['HATFIELD_CACHE_DIR']);
            } else {
                putenv('HATFIELD_CACHE_DIR='.$previous);
                $_ENV['HATFIELD_CACHE_DIR'] = $previous;
                $_SERVER['HATFIELD_CACHE_DIR'] = $previous;
            }
        }
    }

    protected function tempDirPrefix(): string
    {
        return 'test-replay-cache-env';
    }

    protected function replayFixtures(): array
    {
        // Env-contract only; no controller spawn or LLM turn.
        return [];
    }
}
