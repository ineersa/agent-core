<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Doctrine\DBAL\Connection;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\MemoryGenerationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmDatabaseFactoryTestService;

final class MemoryGenerationRepositoryTest extends IsolatedKernelTestCase
{
    public function testRecallExactAndPrefixQueriesSearchThePrimaryKeyIndex(): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('om-reflection-index');
        $this->assertSame($directory, realpath($directory));
        $this->assertFalse(is_link($directory));
        try {
            $factory = self::getContainer()->get('test.om_database_factory');
            $this->assertInstanceOf(OmDatabaseFactoryTestService::class, $factory);
            $connection = $factory->connectAndMigrate($directory.'/om.sqlite');
            $captured = $this->createMock(Connection::class);
            $captured->expects($this->exactly(3))->method('fetchAllAssociative')->willReturnCallback(function (string $sql, array $params) use ($connection): array {
                $plan = implode("\n", array_column($connection->fetchAllAssociative('EXPLAIN QUERY PLAN '.$sql, $params), 'detail'));
                $this->assertStringContainsString('SEARCH om_reflection USING INDEX', $plan);
                $this->assertStringNotContainsString('SCAN', $plan);
                $this->assertStringContainsString(str_ends_with($params[0], '*') ? 'reflection_id GLOB ?' : 'reflection_id = ?', $sql);

                return $connection->fetchAllAssociative($sql, $params);
            });
            $repo = new MemoryGenerationRepository($captured);
            foreach ([str_repeat('a', 64), str_repeat('a', 12), str_repeat('f', 64)] as $id) {
                $this->assertSame([], $repo->findReflectionsByIdPrefix($id));
            }
        } finally {
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }
}
