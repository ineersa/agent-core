<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\SafeGuard;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\ApprovalSessionTracker;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ApprovalSessionTracker — verifies in-memory pending/approved
 * state lifecycle.
 */
final class ApprovalSessionTrackerTest extends TestCase
{
    public function testMarkPendingAndApproveByQuestionId(): void
    {
        $tracker = new ApprovalSessionTracker();
        self::assertFalse($tracker->isApproved('bash:rm -rf /tmp'));

        $tracker->markPending('q-1', 'bash:rm -rf /tmp');
        $tracker->approveByQuestionId('q-1');

        self::assertTrue($tracker->isApproved('bash:rm -rf /tmp'));
    }

    public function testApproveByQuestionIdForUnknownQuestionIsNoop(): void
    {
        $tracker = new ApprovalSessionTracker();
        $tracker->approveByQuestionId('nonexistent');
        // No error, nothing approved
        self::assertFalse($tracker->isApproved('key1'));
    }

    public function testApproveAndConsume(): void
    {
        $tracker = new ApprovalSessionTracker();

        self::assertFalse($tracker->isApproved('bash:rm -rf /tmp'));
        self::assertFalse($tracker->consumeApproval('bash:rm -rf /tmp'));

        $tracker->approve('bash:rm -rf /tmp');
        self::assertTrue($tracker->isApproved('bash:rm -rf /tmp'));

        // consume returns true and removes
        self::assertTrue($tracker->consumeApproval('bash:rm -rf /tmp'));
        self::assertFalse($tracker->isApproved('bash:rm -rf /tmp'));

        // second consume returns false
        self::assertFalse($tracker->consumeApproval('bash:rm -rf /tmp'));
    }

    public function testRemoveCleansUpApprovedState(): void
    {
        $tracker = new ApprovalSessionTracker();
        $tracker->approve('key1');
        self::assertTrue($tracker->isApproved('key1'));

        $tracker->remove('key1');

        self::assertFalse($tracker->isApproved('key1'));
        self::assertFalse($tracker->consumeApproval('key1'));
    }

    public function testRemoveCleansUpPendingState(): void
    {
        $tracker = new ApprovalSessionTracker();
        $tracker->markPending('q-1', 'key1');
        $tracker->remove('key1');

        // Removing pending → approveByQuestionId should be a noop
        $tracker->approveByQuestionId('q-1');
        self::assertFalse($tracker->isApproved('key1'));
    }

    public function testRemoveByQuestionId(): void
    {
        $tracker = new ApprovalSessionTracker();
        $tracker->markPending('q-1', 'key1');
        $tracker->removeByQuestionId('q-1');

        $tracker->approveByQuestionId('q-1');
        self::assertFalse($tracker->isApproved('key1'));
    }

    public function testConsumeApprovalReturnsFalseForUnknownKey(): void
    {
        $tracker = new ApprovalSessionTracker();
        self::assertFalse($tracker->consumeApproval('nonexistent'));
    }

    public function testIsApprovedReturnsFalseForUnknownKey(): void
    {
        $tracker = new ApprovalSessionTracker();
        self::assertFalse($tracker->isApproved('nonexistent'));
    }
}
