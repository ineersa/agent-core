<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E\Support;

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Migrations\StartupDatabaseMigrator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Test-only bootstrap: create a real hatfield_session row against an isolated
 * APP_ENV=test SQLite pair without invoking the LLM or a production CLI.
 *
 * Boots a short-lived CodingAgent Kernel under the exact HATFIELD_CWD /
 * HATFIELD_TEST_* paths the tmux pane will use, runs StartupDatabaseMigrator
 * (schema + transport ensurer), then HatfieldSessionStore::createSession().
 */
final class TuiE2eSessionCatalogSeeder
{
    /**
     * @param non-empty-string $isolatedProjectDir Absolute isolated app CWD
     * @param non-empty-string $appDbEnvPath       HATFIELD_TEST_DATABASE_PATH value
     * @param non-empty-string $transportDbEnvPath HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH value
     *
     * @return non-empty-string Numeric session id
     */
    public static function createSession(
        string $isolatedProjectDir,
        string $appDbEnvPath,
        string $transportDbEnvPath,
        string $prompt = 'seeded resume session',
    ): string {
        if (!is_dir($isolatedProjectDir)) {
            throw new \RuntimeException('Isolated project dir missing: '.$isolatedProjectDir);
        }

        $saved = [
            'cwd' => getcwd(),
            'APP_ENV' => getenv('APP_ENV'),
            'APP_DEBUG' => getenv('APP_DEBUG'),
            'APP_SECRET' => getenv('APP_SECRET'),
            'HATFIELD_CWD' => getenv('HATFIELD_CWD'),
            'HATFIELD_TEST_DATABASE_PATH' => getenv('HATFIELD_TEST_DATABASE_PATH'),
            'HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH' => getenv('HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH'),
            'env' => [
                'APP_ENV' => $_ENV['APP_ENV'] ?? null,
                'APP_DEBUG' => $_ENV['APP_DEBUG'] ?? null,
                'APP_SECRET' => $_ENV['APP_SECRET'] ?? null,
                'HATFIELD_CWD' => $_ENV['HATFIELD_CWD'] ?? null,
                'HATFIELD_TEST_DATABASE_PATH' => $_ENV['HATFIELD_TEST_DATABASE_PATH'] ?? null,
                'HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH' => $_ENV['HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH'] ?? null,
            ],
            'server' => [
                'APP_ENV' => $_SERVER['APP_ENV'] ?? null,
                'APP_DEBUG' => $_SERVER['APP_DEBUG'] ?? null,
                'APP_SECRET' => $_SERVER['APP_SECRET'] ?? null,
                'HATFIELD_CWD' => $_SERVER['HATFIELD_CWD'] ?? null,
                'HATFIELD_TEST_DATABASE_PATH' => $_SERVER['HATFIELD_TEST_DATABASE_PATH'] ?? null,
                'HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH' => $_SERVER['HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH'] ?? null,
            ],
        ];

        $kernel = null;
        $keepStatic = null;
        try {
            if (class_exists(StaticDriver::class)) {
                $keepStatic = StaticDriver::isKeepStaticConnections();
                StaticDriver::setKeepStaticConnections(false);
            }

            if (!@chdir($isolatedProjectDir)) {
                throw new \RuntimeException('Unable to chdir into isolated project: '.$isolatedProjectDir);
            }

            self::put('APP_ENV', 'test');
            self::put('APP_DEBUG', '0');
            self::put('APP_SECRET', 'test-secret');
            self::put('HATFIELD_CWD', $isolatedProjectDir);
            self::put('HATFIELD_TEST_DATABASE_PATH', $appDbEnvPath);
            self::put('HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH', $transportDbEnvPath);

            $kernel = new Kernel('test', false);
            $kernel->boot();

            $container = $kernel->getContainer();
            if ($container->has('test.service_container')) {
                $container = $container->get('test.service_container');
            }

            /** @var StartupDatabaseMigrator $migrator */
            $migrator = $container->get(StartupDatabaseMigrator::class);
            ($migrator)();

            /** @var HatfieldSessionStore $store */
            $store = $container->get(HatfieldSessionStore::class);
            $sessionId = $store->createSession($prompt);
            if ('' === $sessionId) {
                throw new \RuntimeException('HatfieldSessionStore::createSession returned an empty id');
            }

            return $sessionId;
        } finally {
            if ($kernel instanceof KernelInterface) {
                $kernel->shutdown();
                // Kernel boot registers an exception handler; pop it so PHPUnit
                // does not mark the calling test risky.
                restore_exception_handler();
            }
            if (null !== $keepStatic && class_exists(StaticDriver::class)) {
                StaticDriver::setKeepStaticConnections($keepStatic);
            }

            if (\is_string($saved['cwd']) && '' !== $saved['cwd']) {
                @chdir($saved['cwd']);
            }

            self::restore('APP_ENV', $saved['APP_ENV'], $saved['env']['APP_ENV'], $saved['server']['APP_ENV']);
            self::restore('APP_DEBUG', $saved['APP_DEBUG'], $saved['env']['APP_DEBUG'], $saved['server']['APP_DEBUG']);
            self::restore('APP_SECRET', $saved['APP_SECRET'], $saved['env']['APP_SECRET'], $saved['server']['APP_SECRET']);
            self::restore('HATFIELD_CWD', $saved['HATFIELD_CWD'], $saved['env']['HATFIELD_CWD'], $saved['server']['HATFIELD_CWD']);
            self::restore(
                'HATFIELD_TEST_DATABASE_PATH',
                $saved['HATFIELD_TEST_DATABASE_PATH'],
                $saved['env']['HATFIELD_TEST_DATABASE_PATH'],
                $saved['server']['HATFIELD_TEST_DATABASE_PATH'],
            );
            self::restore(
                'HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH',
                $saved['HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH'],
                $saved['env']['HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH'],
                $saved['server']['HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH'],
            );
        }
    }

    private static function put(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key.'='.$value);
    }

    private static function restore(string $key, mixed $getenvValue, mixed $envValue, mixed $serverValue): void
    {
        if (false === $getenvValue || null === $getenvValue) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            putenv($key.'='.$getenvValue);
        }

        if (null === $envValue) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $envValue;
        }

        if (null === $serverValue) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $serverValue;
        }
    }
}
