<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\Pipeline;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchChildIntentDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Application\Pipeline\ChildInvocationLifecycleService;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('db')]
final class ChildInvocationLifecycleServiceTest extends IsolatedKernelTestCase
{
    public function testFailedChildInsertRollsBackTheBatchAndNextOwnerOperationWorks(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $owner = self::getContainer()->get(ChildInvocationLifecycleService::class);
        $factory = new DeferredSubagentBatchIdentityFactory();
        $id = $factory->batchLifecycleId('parent', 'call');
        $child = $factory->childIdentity('parent', 'call', 1);
        $intent = new DeferredSubagentBatchChildIntentDTO(1, $child['childRunId'], $child['artifactId'], 'scout', 'task', 'model', 'medium');
        $connection->executeStatement("CREATE TEMP TRIGGER refuse_child BEFORE INSERT ON deferred_subagent_child BEGIN SELECT RAISE(ABORT, 'refuse child'); END");
        try {
            try {
                $owner->reserveBatch($id, 'parent', 1, 'call', 0, new \DateTimeImmutable('+1 minute'), $intent, 'model');
                $this->fail('Child insertion failure must propagate.');
            } catch (Exception $error) {
                $this->assertStringContainsString('refuse child', $error->getMessage());
            }
            $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM deferred_subagent_batch WHERE lifecycle_id = ?', [$id]));
            $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM deferred_subagent_child WHERE batch_lifecycle_id = ?', [$id]));
        } finally {
            $connection->executeStatement('DROP TRIGGER refuse_child');
        }
        $batch = $owner->reserveBatch($id, 'parent', 1, 'call', 0, new \DateTimeImmutable('+1 minute'), $intent, 'model');
        $this->assertCount(1, $batch->children);
        $owner->applyLaunchSuccessState('parent', 'call', $id, new \DateTimeImmutable());
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Launched, self::getContainer()->get(DeferredSubagentBatchRepository::class)->findByLifecycleId($id)?->launchStatus);
    }

    public function testLaunchMetadataCannotMutateAnotherInvocation(): void
    {
        $owner = self::getContainer()->get(ChildInvocationLifecycleService::class);
        $factory = new DeferredSubagentBatchIdentityFactory();
        $id = $factory->batchLifecycleId('parent', 'call');
        $child = $factory->childIdentity('parent', 'call', 1);
        $intent = new DeferredSubagentBatchChildIntentDTO(1, $child['childRunId'], $child['artifactId'], 'scout', 'task', 'model', 'medium');
        $before = $owner->reserveBatch($id, 'parent', 1, 'call', 0, new \DateTimeImmutable('+1 minute'), $intent, 'model');
        foreach ([true, false] as $success) {
            try {
                if ($success) {
                    $owner->applyLaunchSuccessState('foreign-parent', 'call', $id, new \DateTimeImmutable());
                } else {
                    $owner->applyLaunchFailurePreparation('foreign-parent', 'call', $id);
                }
                $this->fail('Foreign invocation metadata must be refused.');
            } catch (ToolCallException $error) {
                $this->assertStringContainsString('reserved invocation', $error->getMessage());
            }
            $this->assertEquals($before, self::getContainer()->get(DeferredSubagentBatchRepository::class)->findByLifecycleId($id));
        }
    }
}
