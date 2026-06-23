<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\AgentCore\Contract\RunStoreInterface;
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
        self::assertNull($result);
    }

    public function testGetHandlesParentRun(): void
    {
        $store = self::getContainer()->get(ChildAwareRunStore::class);
        $parentStore = self::getContainer()->get(SessionRunStore::class);

        // Write a parent-run state and verify the router finds it.
        $state = new RunState(runId: 'parent-99', status: RunStatus::Running, version: 0);
        $parentStore->compareAndSwap($state, 0);

        $result = $store->get('parent-99');
        self::assertNotNull($result);
        self::assertSame('parent-99', $result->runId);

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
        self::assertTrue($success);

        // Verify the state was written.
        $read = $store->get('parent-cas');
        self::assertNotNull($read);
        self::assertSame('parent-cas', $read->runId);

        // Cleanup: write cancelled.
        $current = $store->get('parent-cas');
        if (null !== $current) {
            $cancelled = new RunState(runId: 'parent-cas', status: RunStatus::Cancelled, version: $current->version);
            $store->compareAndSwap($cancelled, $current->version);
        }
    }
}
