<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\ChildAwareRunStore;
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
        $state = new RunState(runId: 'parent-99', status: RunStatus::Running, version: 0, model: 'test-model');
        $parentStore->compareAndSwap($state, 0);

        $result = $store->get('parent-99');
        $this->assertNotNull($result);
        $this->assertSame('parent-99', $result->runId);

        // Cleanup: mark cancelled.
        $current = $store->get('parent-99');
        if (null !== $current) {
            $cancelled = new RunState(runId: 'parent-99', status: RunStatus::Cancelled, version: $current->version, model: 'test-model');
            $store->compareAndSwap($cancelled, $current->version);
        }
    }

    public function testCompareAndSwapHandlesParentRun(): void
    {
        $store = self::getContainer()->get(ChildAwareRunStore::class);

        $state = new RunState(runId: 'parent-cas', status: RunStatus::Running, version: 0, model: 'test-model');
        $success = $store->compareAndSwap($state, 0);
        $this->assertTrue($success);

        // Verify the state was written.
        $read = $store->get('parent-cas');
        $this->assertNotNull($read);
        $this->assertSame('parent-cas', $read->runId);

        // Cleanup: write cancelled.
        $current = $store->get('parent-cas');
        if (null !== $current) {
            $cancelled = new RunState(runId: 'parent-cas', status: RunStatus::Cancelled, version: $current->version, model: 'test-model');
            $store->compareAndSwap($cancelled, $current->version);
        }
    }
}
