<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchChildIntentDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Entity\DeferredToolCompletionRepository;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Runtime\BackgroundWorkSnapshotProvider;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver;
use Ineersa\CodingAgent\Tests\Support\SqlLoggingKernel;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BackgroundWorkSnapshotProviderTest extends IsolatedKernelTestCase
{
    public function testReadyCandidatesAndBackgroundInventoryRespectOwnership(): void
    {
        $container = self::getContainer();
        $sessions = $container->get(HatfieldSessionStore::class);
        $root = $sessions->createSession('inventory');
        $foreign = $sessions->createSession('foreign');
        $child = Uuid::v7()->toRfc4122();
        $container->get(AgentArtifactRegistry::class)->create($root, 'owned', $child, 'scout', AgentArtifactKindEnum::Subagent);
        // A running child publishes persisted ownership before executing tools;
        // registry files are not needed to discover an active invocation.
        $container->get(RunOperationalProjectionRepository::class)->replace(new RunState($child, RunStatus::Running, parentRunId: $root));
        $repository = $container->get(DeferredToolCompletionRepository::class);
        $ids = [];
        foreach ([$root, $child, $foreign] as $run) {
            $id = $ids[$run] = Uuid::v7()->toRfc4122();
            $correlation = new DeferredToolCompletionCorrelation($id, $run, 1, 'step', 1, 'key', 'host', 'code_mode', [], 0);
            TestToolBatchRegistration::registerDeferredInvocation($container->get(ToolBatchStoreInterface::class), $correlation);
            $repository->registerPending($correlation);
            $repository->markReady($id);
        }
        $provider = $container->get(BackgroundWorkSnapshotProvider::class);
        $snapshot = $provider->snapshot($root);
        $this->assertSame([$ids[$root]], array_column($snapshot->codeMode, 'lifecycleId'));
        $this->assertSame([], $snapshot->work);
        foreach ($ids as $id) {
            $repository->markBackgrounded($id, new \DateTimeImmutable());
        }
        $snapshot = $provider->snapshot($root);
        $this->assertSame([], $snapshot->codeMode);
        $visible = array_column($snapshot->work, 'id');
        $this->assertEqualsCanonicalizing([$ids[$root], $ids[$child]], $visible);
        $this->assertNotContains($ids[$foreign], $visible);
        $this->assertSame([true, true], array_column($snapshot->work, 'stoppable'));
        $repository->markCompleted($ids[$child]);
        $this->assertSame([$ids[$root]], array_column($provider->snapshot($root)->work, 'id'));
    }

    public function testActiveReservationOwnershipDoesNotRequireOperationalStateOrRegistryReads(): void
    {
        $container = self::getContainer();
        $root = $container->get(HatfieldSessionStore::class)->createSession('reservation');
        $child = $this->reserveChild($root, 'tool');
        $container->get(DeferredSubagentBatchRepository::class)->markBackgrounded((new DeferredSubagentBatchIdentityFactory())->batchLifecycleId($root, 'tool'), new \DateTimeImmutable());
        $identity = (new DeferredSubagentBatchIdentityFactory())->childIdentity($root, 'tool', 1);
        $container->get(AgentArtifactRegistry::class)->create($root, $identity['artifactId'], $child, 'scout', AgentArtifactKindEnum::Subagent);
        $correlation = new DeferredToolCompletionCorrelation(Uuid::v7()->toRfc4122(), $child, 1, 'step', 1, 'key', 'host', 'code_mode', [], 0);
        TestToolBatchRegistration::registerDeferredInvocation($container->get(ToolBatchStoreInterface::class), $correlation);
        $repository = $container->get(DeferredToolCompletionRepository::class);
        $repository->registerPending($correlation);
        $repository->markBackgrounded($correlation->deferredId, new \DateTimeImmutable());
        $provider = $container->get(BackgroundWorkSnapshotProvider::class);
        $this->assertSame([$correlation->deferredId], array_column($provider->snapshot($root)->work, 'id'));
        $container->get(RunOperationalProjectionRepository::class)->replace(new RunState($child, RunStatus::Running, parentRunId: $root));
        $this->assertSame([$correlation->deferredId], array_column($provider->snapshot($root)->work, 'id'), 'Matching both relations must not duplicate an invocation.');
        $this->assertSame([], $provider->snapshot('foreign')->work);
        $repository->markCompleted($correlation->deferredId);
        $snapshot = $provider->snapshot($root);
        $this->assertSame([], $snapshot->work, 'Agent work is represented only by the children collection.');
        $this->assertSame([$child], array_column($snapshot->children, 'runId'));
        $this->assertTrue($snapshot->children[0]->backgrounded);
        $this->assertSame('running', $snapshot->children[0]->status);
    }

    public function testSnapshotQueriesAreBoundedAndNeverReadHistoricalRegistries(): void
    {
        $container = self::getContainer();
        $root = $container->get(HatfieldSessionStore::class)->createSession('history');
        $registry = $container->get(AgentArtifactRegistry::class);
        $registry->create($root, 'history', 'historical-run', 'fork', AgentArtifactKindEnum::Fork);
        $path = $container->get(SessionAgentArtifactPathResolver::class)->registryPath($root);
        $this->assertStringStartsWith(realpath(getcwd()).\DIRECTORY_SEPARATOR, realpath($path));
        $this->assertFalse(is_link($path));
        file_put_contents($path, 'invalid registry: live snapshots must never read this');
        $provider = $container->get(BackgroundWorkSnapshotProvider::class);
        $logger = $container->get('test.sql_logger');
        foreach ([0, 100] as $history) {
            for ($index = 0; $index < $history; ++$index) {
                $child = $this->reserveChild($root, 'history-'.$index);
                $container->get(Connection::class)->update('deferred_subagent_child', ['terminal_status' => 'completed'], ['child_run_id' => $child]);
            }
            $logger->records = [];
            $snapshot = $provider->snapshot($root);
            $queries = array_filter($logger->records, static fn (array $row): bool => str_starts_with(ltrim($row['context']['sql'] ?? ''), 'SELECT'));
            $this->assertCount(3, $queries);
            $this->assertCount(0, $snapshot->children, 'Periodic refresh never hydrates historical terminal children.');
            $this->assertSame([], array_filter($snapshot->work, static fn ($work): bool => !$work->terminal));
            $this->assertCount($history, $provider->snapshot($root, includeChildHistory: true)->children);
        }
    }

    public function testFinalReconciliationIsExactAndResumedChildrenAreRediscovered(): void
    {
        $container = self::getContainer();
        $root = $container->get(HatfieldSessionStore::class)->createSession('final');
        $foreign = $this->reserveChild('foreign', 'foreign-child');
        $child = $this->reserveChild($root, 'active');
        $provider = $container->get(BackgroundWorkSnapshotProvider::class);
        $this->assertSame([$child], array_column($provider->snapshot($root)->children, 'runId'));
        $db = $container->get(Connection::class);
        $db->update('deferred_subagent_child', ['terminal_status' => 'completed', 'terminal_completed_at' => '2026-11-02 00:00:00'], ['child_run_id' => $child]);
        $final = $provider->snapshot($root, observedChildRunIds: [$child, $foreign]);
        $this->assertSame([$child], array_column($final->children, 'runId'));
        $this->assertSame('completed', $final->children[0]->status);
        $this->assertSame([], $provider->snapshot($root)->children, 'After terminal catalog settlement no history is returned.');
        $this->assertCount(1, $provider->snapshot($root, includeChildHistory: true)->children);
        // Resume rebind clears the durable terminal checkpoint; discovery must
        // not depend on the catalog already knowing the resumed child is active.
        $this->reserveChild($root, 'resume');
        $factory = new DeferredSubagentBatchIdentityFactory();
        $container->get(DeferredSubagentChildRepository::class)->rebindExistingChildToResumeBatch(
            $factory->batchLifecycleId($root, 'resume'), 2, $child, 'resumed', 'fork', 'resume task', 'provider/model', 'low',
        );
        $this->assertContains($child, array_column($provider->snapshot($root)->children, 'runId'));
    }

    public function testActiveQueriesUseIndexedSearchesWithRetainedHistory(): void
    {
        $container = self::getContainer();
        $root = $container->get(HatfieldSessionStore::class)->createSession('plans');
        $db = $container->get(Connection::class);
        $foreign = $container->get(HatfieldSessionStore::class)->createSession('unrelated');
        $correlation = new DeferredToolCompletionCorrelation(Uuid::v7()->toRfc4122(), $foreign, 1, 'step', 1, 'key', 'host', 'code_mode', [], 0);
        TestToolBatchRegistration::registerDeferredInvocation($container->get(ToolBatchStoreInterface::class), $correlation);
        $repository = $container->get(DeferredToolCompletionRepository::class);
        $repository->registerPending($correlation);
        $repository->markCompleted($correlation->deferredId);
        $retained = $db->fetchAssociative('SELECT * FROM tool_deferred_completion WHERE deferred_id = ?', [$correlation->deferredId]);
        $this->assertIsArray($retained);
        unset($retained['id']);
        for ($i = 0; $i < 1000; ++$i) {
            $child = $this->reserveChild($root, 'history-plan-'.$i);
            $db->update('deferred_subagent_child', ['terminal_status' => 'completed'], ['child_run_id' => $child]);
            $db->insert('tool_deferred_completion', array_replace($retained, ['deferred_id' => 'retained-'.$i, 'tool_call_id' => 'retained-'.$i]));
        }
        $provider = $container->get(BackgroundWorkSnapshotProvider::class);
        $logger = $container->get('test.sql_logger');
        $logger->records = [];
        $this->assertSame([], $provider->snapshot($root)->children);
        $queries = array_values(array_filter($logger->records, static fn (array $row): bool => str_starts_with(ltrim($row['context']['sql'] ?? ''), 'SELECT')));
        $this->assertCount(3, $queries);
        foreach ([$queries[0], $queries[2]] as $query) {
            $plan = $db->fetchAllAssociative('EXPLAIN QUERY PLAN '.$query['context']['sql'], $query['context']['params'] ?? []);
            $detail = implode("\n", array_column($plan, 'detail'));
            if (str_contains($query['context']['sql'], 'tool_deferred_completion')) {
                $this->assertStringContainsString('SEARCH invocation USING INDEX idx_deferred_active_tool', $detail);
                $this->assertStringNotContainsString('SCAN invocation', $detail);
            } else {
                $this->assertStringContainsString('SEARCH active USING COVERING INDEX idx_child_active', $detail);
                $this->assertStringContainsString('SEARCH child USING INTEGER PRIMARY KEY', $detail);
                $this->assertStringContainsString('SEARCH batch USING INDEX uniq_deferred_subagent_batch_lifecycle', $detail);
                $this->assertStringNotContainsString('SEARCH batch USING INDEX uniq_deferred_subagent_batch_parent_tool', $detail);
                $this->assertStringNotContainsString('SCAN batch', $detail);
                $this->assertStringNotContainsString('SCAN child', $detail);
            }
        }
    }

    /** @param array<array-key, mixed> $options */
    protected static function createKernel(array $options = []): Kernel
    {
        return new SqlLoggingKernel('test', false);
    }

    private function reserveChild(string $root, string $tool): string
    {
        $factory = new DeferredSubagentBatchIdentityFactory();
        $child = $factory->childIdentity($root, $tool, 1);
        self::getContainer()->get(DeferredSubagentBatchRepository::class)->reserveBatch(
            lifecycleId: $factory->batchLifecycleId($root, $tool), parentRunId: $root,
            parentTurnNo: 1, parentToolCallId: $tool, parentOrderIndex: 0,
            deadlineAt: new \DateTimeImmutable('+600 seconds'),
            childIntent: new DeferredSubagentBatchChildIntentDTO(1, $child['childRunId'], $child['artifactId'], 'scout', 'task', 'provider/model', 'low'),
        );

        return $child['childRunId'];
    }
}
