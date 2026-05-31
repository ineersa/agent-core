<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;

/**
 * Minimal test helper for creating in-memory SQLite EntityManagers.
 *
 * Centralises entity metadata path resolution so individual tests
 * do not repeat hardcoded __DIR__/../../../src/CodingAgent/Entity.
 *
 * KernelTestCase is not used here because these are focused unit tests
 * for tool internals — they do not require the full Symfony container.
 * For functional/E2E tests that exercise the full runtime, use
 * KernelTestCase or castor test:controller instead.
 */
final class OrmTestHelper
{
    /**
     * Project root is resolved once from this file's location
     * (tests/CodingAgent/Tool/OrmTestHelper.php → project root).
     */
    public static function createEntityManager(): EntityManager
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $projectRoot = dirname(__DIR__, 3);

        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: [$projectRoot.'/src/CodingAgent/Entity'],
            isDevMode: true,
            proxyDir: sys_get_temp_dir(),
        );

        $config->enableNativeLazyObjects(true);

        return new EntityManager($connection, $config);
    }
}
