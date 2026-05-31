<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\SafeGuard;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\ApprovalSessionTracker;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ApprovalSessionTracker — verifies in-memory pending/approved
 * state lifecycle and forced answer resolution.
 */
final class ApprovalSessionTrackerTest extends TestCase
{
    public function testMarkPendingAndHasPending(): void
    {
        $tracker = new ApprovalSessionTracker();
        $this->assertFalse($tracker->hasPending('bash:rm -rf /tmp'));

        $tracker->markPending('bash:rm -rf /tmp', 'q-1', 'run-1');
        $this->assertTrue($tracker->hasPending('bash:rm -rf /tmp'));
    }

    public function testForceAnswerResolvesOnFirstCall(): void
    {
        $tracker = new ApprovalSessionTracker();
        $tracker->markPending('bash:rm -rf /tmp', 'q-1', 'run-1');
        $tracker->forceAnswer('bash:rm -rf /tmp', 'Allow once');

        // First resolveAnswer returns the forced answer
        $answer = $tracker->resolveAnswer('bash:rm -rf /tmp');
        $this->assertSame('Allow once', $answer);

        // Second resolve returns null (consumed)
        $this->assertNull($tracker->resolveAnswer('bash:rm -rf /tmp'));
    }

    public function testForceAnswerRemovesPending(): void
    {
        $tracker = new ApprovalSessionTracker();
        $tracker->markPending('bash:rm -rf /tmp', 'q-1', 'run-1');
        $tracker->forceAnswer('bash:rm -rf /tmp', 'Allow once');

        // After resolve, pending is gone
        $tracker->resolveAnswer('bash:rm -rf /tmp');
        $this->assertFalse($tracker->hasPending('bash:rm -rf /tmp'));
    }

    public function testApproveAndConsume(): void
    {
        $tracker = new ApprovalSessionTracker();

        $this->assertFalse($tracker->isApproved('bash:rm -rf /tmp'));
        $this->assertFalse($tracker->consumeApproval('bash:rm -rf /tmp'));

        $tracker->approve('bash:rm -rf /tmp');
        $this->assertTrue($tracker->isApproved('bash:rm -rf /tmp'));

        // consume returns true and removes
        $this->assertTrue($tracker->consumeApproval('bash:rm -rf /tmp'));
        $this->assertFalse($tracker->isApproved('bash:rm -rf /tmp'));

        // second consume returns false
        $this->assertFalse($tracker->consumeApproval('bash:rm -rf /tmp'));
    }

    public function testRemoveCleansUpAllStates(): void
    {
        $tracker = new ApprovalSessionTracker();
        $tracker->markPending('key1', 'q-1', 'run-1');
        $tracker->approve('key1');
        $tracker->forceAnswer('key1', 'Deny');

        $tracker->remove('key1');

        $this->assertFalse($tracker->hasPending('key1'));
        $this->assertFalse($tracker->isApproved('key1'));
        $this->assertNull($tracker->resolveAnswer('key1'));
    }

    public function testResolveAnswerWithoutEventReaderReturnsNull(): void
    {
        $tracker = new ApprovalSessionTracker();
        $tracker->markPending('bash:rm -rf /tmp', 'q-1', 'run-1');

        // No event reader, no forced answer → null
        $answer = $tracker->resolveAnswer('bash:rm -rf /tmp');
        $this->assertNull($answer);
    }

    public function testResolveAnswerRemovesPendingRegardlessOfResult(): void
    {
        $tracker = new ApprovalSessionTracker();
        $tracker->markPending('bash:rm -rf /tmp', 'q-1', 'run-1');

        $tracker->resolveAnswer('bash:rm -rf /tmp');
        $this->assertFalse($tracker->hasPending('bash:rm -rf /tmp'));
    }

    public function testResolveAnswerReturnsNullForUnknownKey(): void
    {
        $tracker = new ApprovalSessionTracker();
        $this->assertNull($tracker->resolveAnswer('nonexistent-key'));
    }
}
