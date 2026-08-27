<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\Support;

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Migrations\StartupDatabaseMigrator;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Seeds and inspects accepted process state in the isolated SQLite database
 * used by a real controller-replay subprocess.
 *
 * The short-lived test kernel uses the identical CWD and database environment
 * as the controller, then exercises the existing migration and ProcessStore
 * services rather than writing SQLite directly.
 */
final class ControllerReplayBackgroundProcessSeeder
{
    /**
     * @return array{id: int, log: string, status: string, pid: string}
     */
    public static function seedAcceptedFinished(
        string $isolatedProjectDir,
        string $appDbEnvPath,
        string $transportDbEnvPath,
        string $sessionId,
        string $prefix,
    ): array {
        return self::withContainer($isolatedProjectDir, $appDbEnvPath, $transportDbEnvPath, static function (ContainerInterface $container) use ($sessionId, $prefix): array {
            /** @var StartupDatabaseMigrator $migrator */
            $migrator = $container->get(StartupDatabaseMigrator::class);
            $migrator();

            /** @var BackgroundProcessConfig $config */
            $config = $container->get(BackgroundProcessConfig::class);
            if (!is_dir($config->storageDir) && !mkdir($config->storageDir, 0o777, true) && !is_dir($config->storageDir)) {
                throw new \RuntimeException('Unable to create isolated background process storage directory.');
            }

            $basePath = $config->storageDir.'/'.$prefix;
            $logPath = $basePath.'.log';
            $statusPath = $basePath.'.status';
            $pidPath = $basePath.'.pid';
            file_put_contents($logPath, 'seeded output');
            file_put_contents($statusPath, '0');
            file_put_contents($pidPath, '999999');

            /** @var ProcessStore $store */
            $store = $container->get(ProcessStore::class);
            $id = $store->insertRecord([
                'pid' => 999999,
                'pgid' => null,
                'session_id' => $sessionId,
                'command' => 'seeded fixture',
                'log_path' => $logPath,
                'status_path' => $statusPath,
                'started_at' => new \DateTimeImmutable(),
            ]);
            $store->markFinished($id, 0, new \DateTimeImmutable());

            /** @var BackgroundProcessManager $manager */
            $manager = $container->get(BackgroundProcessManager::class);
            $manager->markBackgroundedForRecord($id, $sessionId);

            return [
                'id' => $id,
                'log' => $logPath,
                'status' => $statusPath,
                'pid' => $pidPath,
            ];
        });
    }

    public static function recordExists(
        string $isolatedProjectDir,
        string $appDbEnvPath,
        string $transportDbEnvPath,
        int $id,
    ): bool {
        return self::withContainer($isolatedProjectDir, $appDbEnvPath, $transportDbEnvPath, static function (ContainerInterface $container) use ($id): bool {
            /** @var StartupDatabaseMigrator $migrator */
            $migrator = $container->get(StartupDatabaseMigrator::class);
            $migrator();

            /** @var ProcessStore $store */
            $store = $container->get(ProcessStore::class);

            return null !== $store->fetchById($id);
        });
    }

    /**
     * @template T
     *
     * @param callable(ContainerInterface): T $callback
     *
     * @return T
     */
    private static function withContainer(
        string $isolatedProjectDir,
        string $appDbEnvPath,
        string $transportDbEnvPath,
        callable $callback,
    ): mixed {
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

            return $callback($container);
        } finally {
            if ($kernel instanceof KernelInterface) {
                $kernel->shutdown();
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
            self::restore('HATFIELD_TEST_DATABASE_PATH', $saved['HATFIELD_TEST_DATABASE_PATH'], $saved['env']['HATFIELD_TEST_DATABASE_PATH'], $saved['server']['HATFIELD_TEST_DATABASE_PATH']);
            self::restore('HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH', $saved['HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH'], $saved['env']['HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH'], $saved['server']['HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH']);
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
