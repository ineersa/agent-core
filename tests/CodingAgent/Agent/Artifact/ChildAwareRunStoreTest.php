<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathResolver;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\ChildAwareRunStore;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

final class ChildAwareRunStoreTest extends IsolatedKernelTestCase
{
    public function testGetReturnsNullForUnknownRunId(): void
    {
        $store = self::getContainer()->get(ChildAwareRunStore::class);

        $result = $store->get('nonexistent-run-id');
        $this->assertNull($result);
    }

    public function testGetHandlesParentRun(): void
    {
        $store = self::getContainer()->get(ChildAwareRunStore::class);
        $parentStore = self::getContainer()->get(SessionRunStore::class);

        // Write a parent-run state and verify the router finds it.
        $state = new RunState(runId: 'parent-99', status: RunStatus::Running, version: 0);
        $parentStore->compareAndSwap($state, 0);

        $result = $store->get('parent-99');
        $this->assertNotNull($result);
        $this->assertSame('parent-99', $result->runId);

        // Cleanup: mark cancelled.
        $current = $store->get('parent-99');
        if (null !== $current) {
            $cancelled = new RunState(runId: 'parent-99', status: RunStatus::Cancelled, version: $current->version);
            $store->compareAndSwap($cancelled, $current->version);
        }
    }

    public function testCompareAndSwapHandlesParentRun(): void
    {
        $store = self::getContainer()->get(ChildAwareRunStore::class);

        $state = new RunState(runId: 'parent-cas', status: RunStatus::Running, version: 0);
        $success = $store->compareAndSwap($state, 0);
        $this->assertTrue($success);

        // Verify the state was written.
        $read = $store->get('parent-cas');
        $this->assertNotNull($read);
        $this->assertSame('parent-cas', $read->runId);

        // Cleanup: write cancelled.
        $current = $store->get('parent-cas');
        if (null !== $current) {
            $cancelled = new RunState(runId: 'parent-cas', status: RunStatus::Cancelled, version: $current->version);
            $store->compareAndSwap($cancelled, $current->version);
        }
    }

    public function testCompareAndSwapWritesNestedChildStateUnderArtifactPath(): void
    {
        /** @var HatfieldSessionStore $hatfield */
        $hatfield = self::getContainer()->get(HatfieldSessionStore::class);
        /** @var AgentArtifactRegistry $registry */
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        /** @var AgentArtifactPathResolver $pathResolver */
        $pathResolver = self::getContainer()->get(AgentArtifactPathResolver::class);
        $store = self::getContainer()->get(ChildAwareRunStore::class);

        $mainParentId = $hatfield->createSession('Nested child run store main');
        $forkRunId = 'nested-rs-fork-'.bin2hex(random_bytes(4));
        $forkArtifactId = 'agent_fork_rs_'.bin2hex(random_bytes(4));
        $registry->create($mainParentId, $forkArtifactId, $forkRunId, 'fork', AgentArtifactKindEnum::Fork);

        $scoutRunId = 'nested-rs-scout-'.bin2hex(random_bytes(4));
        $scoutArtifactId = 'agent_scout_rs_'.bin2hex(random_bytes(4));
        $registry->create($forkRunId, $scoutArtifactId, $scoutRunId, 'scout', AgentArtifactKindEnum::Subagent);

        $state = new RunState(runId: $scoutRunId, status: RunStatus::Running, version: 0);
        $this->assertTrue($store->compareAndSwap($state, 0));

        $statePath = $pathResolver->statePath($forkRunId, $scoutArtifactId);
        $this->assertFileExists($statePath, 'Nested child state must persist under fork-scoped artifact directory');

        $read = $store->get($scoutRunId);
        $this->assertNotNull($read);
        $this->assertSame($scoutRunId, $read->runId);
        $this->assertSame(RunStatus::Running, $read->status);
    }
}
