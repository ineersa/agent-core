<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\TestCase;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Test helper: creates an isolated in-memory SQLite EntityManager.
 *
 * Intended ONLY for unit-level tests that need session-metadata persistence
 * but cannot boot the full Symfony kernel (e.g., ModelSelectionServiceTest,
 * SessionAwareModelResolverTest, and AgentCore model-resolution tests).
 * These tests manually construct HatfieldSessionStore with specific
 * AppConfig settings, home/project directories, and YAML config files
 * that vary per test case — incompatible with a single shared kernel boot.
 *
 * For integration/functional tests, use IsolatedKernelTestCase instead.
 * That base class boots a real Symfony kernel with the proper test-
 * environment DB, migrations, and container services.
 *
 * ORMSetup/DriverManager/SchemaTool are used here because the tests
 * manage an isolated in-memory database without the full Symfony
 * container lifecycle. The entity paths are resolved from __DIR__
 * (assuming the standard repo layout tests/CodingAgent/TestCase/).
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
