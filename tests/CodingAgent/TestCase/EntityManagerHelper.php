<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\TestCase;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Test helper: creates an isolated in-memory SQLite EntityManager with
 * only the CodingAgent entity mappings loaded.
 *
 * Useful for tests that need DB-backed session metadata but do not
 * need the full Symfony kernel. Keeps ORMSetup/DriverManager/SchemaTool
 * confined to test infrastructure.
 */
final class EntityManagerHelper
{
    /**
     * Create an EntityManager backed by an in-memory SQLite database
     * with the CodingAgent entity schema deployed.
     *
     * Paths are resolved from the tests/ directory, which lives two
     * levels below the project root in the standard layout.
     */
    public static function createInMemorySqlite(): EntityManagerInterface
    {
        $entityPath = dirname(__DIR__, 3).'/src/CodingAgent/Entity';

        $config = ORMSetup::createAttributeMetadataConfiguration(
            [$entityPath],
            isDevMode: true,
        );

        // ORM 3.6+ requires native lazy objects to be explicitly enabled
        // in standalone configuration (DoctrineBundle auto-enables this).
        $config->enableNativeLazyObjects(true);

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ], $config);

        $em = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($em);
        $classes = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->createSchema($classes);

        return $em;
    }
}
