<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Config;

use PHPUnit\Framework\TestCase;

final class DoctrineConfigTest extends TestCase
{
    public function testDoctrineAndMigrationConfigIsPreparedForStage03Persistence(): void
    {
        /** @var array<string, array<array<string, mixed>>> $config */
        $config = require dirname(__DIR__, 2).'/config/doctrine.php';

        self::assertArrayHasKey('doctrine', $config);
        self::assertArrayHasKey('doctrine_migrations', $config);

        /** @var array<string, mixed> $migrationConfig */
        $migrationConfig = $config['doctrine_migrations'][0];

        self::assertArrayHasKey('migrations_paths', $migrationConfig);
        self::assertArrayHasKey('Ineersa\\AgentCore\\Infrastructure\\Doctrine\\Migrations', $migrationConfig['migrations_paths']);
    }
}
