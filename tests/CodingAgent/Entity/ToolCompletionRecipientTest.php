<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Entity;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Entity\ToolCompletionRecipient;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

final class ToolCompletionRecipientTest extends IsolatedKernelTestCase
{
    public function testOrmColumnNamesMatchRuntimeMigrationSchema(): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $manager->getClassMetadata(ToolCompletionRecipient::class);
        $schemaColumns = array_keys($manager->getConnection()->createSchemaManager()->listTableColumns($metadata->getTableName()));
        $this->assertSame(['destination_run_id', 'message_id'], $metadata->getIdentifierColumnNames());
        $this->assertEqualsCanonicalizing($schemaColumns, $metadata->getColumnNames());
    }
}
