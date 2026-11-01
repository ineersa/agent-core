<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Repository\RunRelationshipReader;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

/**
 * Thesis: hot child/parent classification reads only operational projection rows,
 * never EventStore firstFor(), and unknown identity fails closed.
 */
final class RunRelationshipReaderTest extends IsolatedKernelTestCase
{
    public function testRelationshipSnapshotsRemainFreshWithoutManagingTheGraph(): void
    {
        $projection = self::getContainer()->get(RunOperationalProjectionRepository::class);
        $projection->replace(new RunState('relationship-parent', RunStatus::Running));
        $projection->replace(new RunState('relationship-child', RunStatus::Running, parentRunId: 'relationship-parent'));
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $reader = new RunRelationshipReader($projection);
        for ($round = 0; $round < 128; ++$round) {
            $this->assertTrue($reader->isAgentChild('relationship-child'));
            $this->assertSame('relationship-parent', $reader->readParentRunId('relationship-child'));
            $reader->requireKnownTopLevel('relationship-parent');
            $this->assertSame([], $entityManager->getUnitOfWork()->getIdentityMap());
        }
        $entityManager->getConnection()->executeStatement('UPDATE run_operational_state SET parent_run_id = NULL WHERE run_id = ?', ['relationship-child']);
        $this->assertFalse($reader->isAgentChild('relationship-child'));
    }

    public function testHotClassificationUsesProjectionOnlyAndNeverTouchesEventStore(): void
    {
        $container = self::getContainer();
        $projection = $container->get(RunOperationalProjectionRepository::class);
        $reader = new RunRelationshipReader($projection);

        $projection->replace(new RunState('parent', RunStatus::Running));
        $projection->replace(new RunState('child', RunStatus::Running, parentRunId: 'parent'));

        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->never())->method('firstFor');
        $eventStore->expects($this->never())->method('allFor');
        $container->set(EventStoreInterface::class, $eventStore);

        $this->assertFalse($reader->isAgentChild('parent'));
        $this->assertTrue($reader->isAgentChild('child'));
        $this->assertSame('parent', $reader->readParentRunId('child'));
        $this->assertNull($reader->readParentRunId('parent'));
    }

    public function testMissingOperationalIdentityFailsClosed(): void
    {
        $reader = new RunRelationshipReader(self::getContainer()->get(RunOperationalProjectionRepository::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Operational relationship for run "missing" is missing');
        $reader->isAgentChild('missing');
    }

    public function testRequireKnownTopLevelFailsClosedForMissingAndChildRows(): void
    {
        $projection = self::getContainer()->get(RunOperationalProjectionRepository::class);
        $reader = new RunRelationshipReader($projection);
        $projection->replace(new RunState('parent', RunStatus::Running));
        $projection->replace(new RunState('child', RunStatus::Running, parentRunId: 'parent'));

        $reader->requireKnownTopLevel('parent');

        try {
            $reader->requireKnownTopLevel('missing');
            $this->fail('Expected missing relationship to fail closed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Operational relationship for run "missing" is missing', $e->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is an agent child');
        $reader->requireKnownTopLevel('child');
    }
}
