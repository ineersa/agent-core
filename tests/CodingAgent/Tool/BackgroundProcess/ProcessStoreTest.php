<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\BackgroundProcess;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;

final class ProcessStoreTest extends IsolatedKernelTestCase
{
    public function testOwnerSnapshotUsesRetainedSessionAndParentProjectionWithoutHydratingEntities(): void
    {
        $owner = self::getContainer()->get(HatfieldSessionStore::class)->createSession();
        $projection = self::getContainer()->get('test.run_operational_projection_repository');
        $projection->replace(new RunState('child', RunStatus::Running, parentRunId: $owner));
        $projection->replace(new RunState('cycle-a', RunStatus::Running, parentRunId: 'cycle-b'));
        $projection->replace(new RunState('cycle-b', RunStatus::Running, parentRunId: 'cycle-a'));
        $store = self::getContainer()->get(ProcessStore::class);
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $before = $manager->getUnitOfWork()->size();
        for ($i = 0; $i < 32; ++$i) {
            $this->assertSame($owner, $store->ownerSessionForRun($owner));
            $this->assertSame($owner, $store->ownerSessionForRun('child'));
        }
        $this->assertNull($store->ownerSessionForRun('unknown'));
        $this->assertNull($store->ownerSessionForRun('cycle-a'));
        $this->assertSame($before, $manager->getUnitOfWork()->size());
    }
}
