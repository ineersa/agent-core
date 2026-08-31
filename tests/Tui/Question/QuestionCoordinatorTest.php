<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Question;

use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Question\QuestionKind;
use Ineersa\Tui\Question\QuestionRequest;
use Ineersa\Tui\Question\QuestionSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QuestionCoordinator::class)]
final class QuestionCoordinatorTest extends TestCase
{
    // ─── Enqueue / activation ──────────────────────────────────────────

    public function testEnqueueActivatesFirstRequest(): void
    {
        $coordinator = new QuestionCoordinator();
        $request = $this->tuiRequest('r1');

        $coordinator->enqueue($request);

        $this->assertSame($request, $coordinator->activeRequest());
        $this->assertTrue($coordinator->actionRequired());
        $this->assertNotNull($coordinator->activeRequest());
        $this->assertTrue($coordinator->actionRequired());
    }

    public function testEnqueueSecondRequestQueuesBehindActive(): void
    {
        $coordinator = new QuestionCoordinator();
        $r1 = $this->tuiRequest('r1');
        $r2 = $this->tuiRequest('r2');

        $coordinator->enqueue($r1);
        $coordinator->enqueue($r2);

        // First request remains active; second is queued
        $this->assertSame($r1, $coordinator->activeRequest());
    }

    public function testActionRequiredFalseInitially(): void
    {
        $coordinator = new QuestionCoordinator();

        $this->assertFalse($coordinator->actionRequired());
        $this->assertNull($coordinator->activeRequest());
        $this->assertNull($coordinator->activeRequest());
    }

    // ─── Answer / advance ──────────────────────────────────────────────

    public function testAnswerResolvesActiveAndAdvancesToNext(): void
    {
        $coordinator = new QuestionCoordinator();
        $r1 = $this->tuiRequest('r1');
        $r2 = $this->tuiRequest('r2');

        $coordinator->enqueue($r1);
        $coordinator->enqueue($r2);

        $coordinator->answer('foo');

        $this->assertSame($r2, $coordinator->activeRequest());
        $this->assertNotNull($coordinator->activeRequest());
        $this->assertTrue($coordinator->actionRequired());
    }

    public function testFifoOrderPreservedWithThreeRequests(): void
    {
        $coordinator = new QuestionCoordinator();
        $r1 = $this->tuiRequest('r1');
        $r2 = $this->tuiRequest('r2');
        $r3 = $this->tuiRequest('r3');

        $coordinator->enqueue($r1);
        $coordinator->enqueue($r2);
        $coordinator->enqueue($r3);

        $this->assertSame($r1, $coordinator->activeRequest());

        $coordinator->answer('a');
        $this->assertSame($r2, $coordinator->activeRequest());

        $coordinator->answer('b');
        $this->assertSame($r3, $coordinator->activeRequest());

        $coordinator->answer('c');
        $this->assertNull($coordinator->activeRequest());
        $this->assertFalse($coordinator->actionRequired());
    }

    // ─── Reject / cancel ───────────────────────────────────────────────

    public function testRejectAdvancesQueue(): void
    {
        $coordinator = new QuestionCoordinator();
        $r1 = $this->tuiRequest('r1');
        $r2 = $this->tuiRequest('r2');

        $coordinator->enqueue($r1);
        $coordinator->enqueue($r2);

        $coordinator->reject();

        $this->assertSame($r2, $coordinator->activeRequest());
        $this->assertNotNull($coordinator->activeRequest());
        $this->assertTrue($coordinator->actionRequired());
    }

    public function testCancelAdvancesQueue(): void
    {
        $coordinator = new QuestionCoordinator();
        $r1 = $this->tuiRequest('r1');
        $r2 = $this->tuiRequest('r2');

        $coordinator->enqueue($r1);
        $coordinator->enqueue($r2);

        $coordinator->cancel();

        $this->assertSame($r2, $coordinator->activeRequest());
        $this->assertNotNull($coordinator->activeRequest());
        $this->assertTrue($coordinator->actionRequired());
    }

    public function testRejectEmptyIsNoOp(): void
    {
        $coordinator = new QuestionCoordinator();

        // Should not throw
        $coordinator->reject();
        $this->assertFalse($coordinator->actionRequired());
    }

    public function testCancelEmptyIsNoOp(): void
    {
        $coordinator = new QuestionCoordinator();

        $coordinator->cancel();
        $this->assertFalse($coordinator->actionRequired());
    }

    // ─── Local callback behavior ───────────────────────────────────────

    public function testLocalCallbackInvokedForTuiSource(): void
    {
        $coordinator = new QuestionCoordinator();
        $request = $this->tuiRequest('r1');
        $received = null;

        $coordinator->enqueue($request, static function (mixed $value) use (&$received): void {
            $received = $value;
        });

        $coordinator->answer('hello');

        $this->assertSame('hello', $received);
        $this->assertNull($coordinator->activeRequest());
    }

    public function testCallbackInvokedForAgentCoreSource(): void
    {
        $coordinator = new QuestionCoordinator();
        $request = $this->agentCoreRequest('r1');
        $received = null;

        $coordinator->enqueue($request, static function (mixed $value) use (&$received): void {
            $received = $value;
        });

        $coordinator->answer('hello');

        // AgentCore callbacks are now invoked so upper layers can
        // dispatch answer_human commands to the runtime.
        $this->assertSame('hello', $received);
        $this->assertNull($coordinator->activeRequest());
    }

    public function testCallbackNotCalledOnReject(): void
    {
        $coordinator = new QuestionCoordinator();
        $called = false;

        $coordinator->enqueue($this->tuiRequest('r1'), static function () use (&$called): void {
            $called = true;
        });

        $coordinator->reject();

        $this->assertFalse($called);
        $this->assertNull($coordinator->activeRequest());
    }

    public function testCallbackNotCalledOnCancel(): void
    {
        $coordinator = new QuestionCoordinator();
        $called = false;

        $coordinator->enqueue($this->tuiRequest('r1'), static function () use (&$called): void {
            $called = true;
        });

        $coordinator->cancel();

        $this->assertFalse($called);
        $this->assertNull($coordinator->activeRequest());
    }

    // ─── Cancel callback behavior ─────────────────────────────────────

    public function testCancelCallbackInvokedForAgentCoreSource(): void
    {
        $coordinator = new QuestionCoordinator();
        $request = $this->agentCoreRequest('r1');
        $cancelFired = false;

        $coordinator->enqueue(
            $request,
            onAnswer: static function (): void {},
            onCancel: static function () use (&$cancelFired): void {
                $cancelFired = true;
            },
        );

        $coordinator->cancel();

        $this->assertTrue($cancelFired);
        $this->assertNull($coordinator->activeRequest());
    }

    public function testCancelCallbackAdvanceQueue(): void
    {
        $coordinator = new QuestionCoordinator();
        $r1 = $this->agentCoreRequest('r1');
        $r2 = $this->agentCoreRequest('r2');
        $cancelFired = false;

        $coordinator->enqueue(
            $r1,
            onCancel: static function () use (&$cancelFired): void {
                $cancelFired = true;
            },
        );
        $coordinator->enqueue($r2);

        $coordinator->cancel();

        // r1 cancelled, r2 becomes active
        $this->assertTrue($cancelFired);
        $this->assertSame($r2, $coordinator->activeRequest());
        $this->assertTrue($coordinator->actionRequired());
    }

    public function testCancelCallbackThrowingStillAdvancesQueue(): void
    {
        $coordinator = new QuestionCoordinator();

        $coordinator->enqueue(
            $this->agentCoreRequest('r1'),
            onCancel: static function (): void {
                throw new \RuntimeException('Callback explosion');
            },
        );
        $coordinator->enqueue($this->agentCoreRequest('r2'));

        // The callback exception propagates, but the finally block
        // in cancel() already advanced the queue.
        try {
            $coordinator->cancel();
            $this->fail('Expected RuntimeException from cancel callback was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('Callback explosion', $e->getMessage());
        }

        // Advance happened in finally block — r2 should be active.
        $this->assertSame('r2', $coordinator->activeRequest()?->requestId);
        $this->assertTrue($coordinator->actionRequired());
    }

    public function testMultipleQueuedCancelCallbacks(): void
    {
        $coordinator = new QuestionCoordinator();
        $cancelled = [];

        $coordinator->enqueue(
            $this->agentCoreRequest('r1'),
            onCancel: static function () use (&$cancelled): void {
                $cancelled[] = 'r1';
            },
        );
        $coordinator->enqueue(
            $this->agentCoreRequest('r2'),
            onCancel: static function () use (&$cancelled): void {
                $cancelled[] = 'r2';
            },
        );

        $coordinator->cancel(); // cancels r1
        $coordinator->cancel(); // cancels r2

        $this->assertSame(['r1', 'r2'], $cancelled);
        $this->assertNull($coordinator->activeRequest());
    }

    public function testMultipleCallbacksInFifoOrder(): void
    {
        $coordinator = new QuestionCoordinator();
        $results = [];

        $coordinator->enqueue($this->tuiRequest('r1'), static function (mixed $v) use (&$results): void {
            $results[] = "r1:$v";
        });
        $coordinator->enqueue($this->tuiRequest('r2'), static function (mixed $v) use (&$results): void {
            $results[] = "r2:$v";
        });
        $coordinator->enqueue($this->tuiRequest('r3'), static function (mixed $v) use (&$results): void {
            $results[] = "r3:$v";
        });

        $coordinator->answer('a');
        $coordinator->answer('b');
        $coordinator->answer('c');

        $this->assertSame(['r1:a', 'r2:b', 'r3:c'], $results);
        $this->assertNull($coordinator->activeRequest());
    }

    // ─── Mixed source handling ─────────────────────────────────────────

    public function testAgentCoreRequestBetweenTuiRequests(): void
    {
        $coordinator = new QuestionCoordinator();
        $results = [];

        $coordinator->enqueue($this->tuiRequest('r1'), static function (mixed $v) use (&$results): void {
            $results[] = "r1:$v";
        });
        $coordinator->enqueue($this->agentCoreRequest('r2'), static function (mixed $v) use (&$results): void {
            $results[] = "r2:$v";
        });
        $coordinator->enqueue($this->tuiRequest('r3'), static function (mixed $v) use (&$results): void {
            $results[] = "r3:$v";
        });

        // r1 active — answer it
        $coordinator->answer('a');
        $this->assertSame(['r1:a'], $results);

        // r2 now active — AgentCore, callback IS invoked so the
        // answer_human command can be dispatched to the runtime.
        $this->assertSame('r2', $coordinator->activeRequest()?->requestId);
        $coordinator->answer('b');
        $this->assertSame(['r1:a', 'r2:b'], $results);

        // r3 now active
        $coordinator->answer('c');
        $this->assertSame(['r1:a', 'r2:b', 'r3:c'], $results);
        $this->assertNull($coordinator->activeRequest());
    }

    // ─── Status tracking ───────────────────────────────────────────────

    public function testActiveStatusIsNullWhenEmpty(): void
    {
        $coordinator = new QuestionCoordinator();
        $this->assertNull($coordinator->activeRequest());
    }

    public function testActiveStatusAfterAnswerLastRequest(): void
    {
        $coordinator = new QuestionCoordinator();
        $coordinator->enqueue($this->tuiRequest('r1'));

        $coordinator->answer('ok');

        $this->assertNull($coordinator->activeRequest());
    }

    // ─── Answer with no active is no-op ────────────────────────────────

    public function testAnswerWithNoActiveRequestIsNoOp(): void
    {
        $coordinator = new QuestionCoordinator();

        // Should not throw
        $coordinator->answer('foo');

        $this->assertNull($coordinator->activeRequest());
        $this->assertFalse($coordinator->actionRequired());
    }

    // ─── Enqueue without callback ──────────────────────────────────────

    public function testEnqueueWithoutCallbackDoesNotThrowOnAnswer(): void
    {
        $coordinator = new QuestionCoordinator();
        $coordinator->enqueue($this->tuiRequest('r1'));

        // Should not throw even though no callback was registered
        $coordinator->answer('ok');

        $this->assertNull($coordinator->activeRequest());
    }

    // ─── Duplicate requestId guard ───────────────────────────────────

    public function testDuplicateRequestIdThrows(): void
    {
        $coordinator = new QuestionCoordinator();
        $request = $this->tuiRequest('dup-id');

        $coordinator->enqueue($request);

        $this->expectException(\InvalidArgumentException::class);
        $coordinator->enqueue($this->tuiRequest('dup-id'));
    }

    public function testHasRequestReturnsTrueForEnqueuedId(): void
    {
        $coordinator = new QuestionCoordinator();

        $this->assertFalse($coordinator->hasRequest('r1'));

        $coordinator->enqueue($this->tuiRequest('r1'));

        $this->assertTrue($coordinator->hasRequest('r1'));
    }

    public function testHasRequestReturnsFalseAfterAdvance(): void
    {
        $coordinator = new QuestionCoordinator();
        $coordinator->enqueue($this->tuiRequest('r1'));

        $coordinator->answer('ok');

        // After the request is answered, it is removed from tracking
        $this->assertFalse($coordinator->hasRequest('r1'));
    }

    public function testHasRequestReturnsFalseForUnknownId(): void
    {
        $coordinator = new QuestionCoordinator();

        $this->assertFalse($coordinator->hasRequest('nonexistent'));
    }

    // ─── Fresh ownership (per-session composition) ─────────────────────

    public function testFreshCoordinatorStartsWithNoState(): void
    {
        $coordinator = new QuestionCoordinator();

        $this->assertNull($coordinator->activeRequest());
        $this->assertNull($coordinator->activeRequest());
        $this->assertFalse($coordinator->actionRequired());
    }

    public function testFreshCoordinatorDoesNotRetainPriorSessionQuestions(): void
    {
        // Per-session composition creates a fresh coordinator per iteration;
        // a new instance must never see state from an earlier session.
        $first = new QuestionCoordinator();
        $first->enqueue($this->tuiRequest('r1'));
        $first->enqueue($this->tuiRequest('r2'));
        $this->assertTrue($first->actionRequired());

        $second = new QuestionCoordinator();
        $this->assertNull($second->activeRequest());
        $this->assertFalse($second->actionRequired());
        $this->assertFalse($second->hasRequest('r1'));

        // The same ID can be enqueued in the fresh instance without the
        // duplicate guard firing (no shared request-id tracking).
        $second->enqueue($this->tuiRequest('r1'));
        $this->assertTrue($second->hasRequest('r1'));
    }

    // ─── removeForRun (child live-view leave/switch) ───────────────────

    public function testRemoveForRunDropsActiveAndQueuedForOneRunWithoutCallbacks(): void
    {
        $coordinator = new QuestionCoordinator();
        $cancelFired = [];
        $answerFired = [];

        $coordinator->enqueue(
            $this->agentCoreRequestForRun('child-a-active', 'child-a'),
            onAnswer: static function () use (&$answerFired): void {
                $answerFired[] = 'child-a-active';
            },
            onCancel: static function () use (&$cancelFired): void {
                $cancelFired[] = 'child-a-active';
            },
        );
        $coordinator->enqueue(
            $this->agentCoreRequestForRun('parent-q', 'parent-run'),
            onAnswer: static function () use (&$answerFired): void {
                $answerFired[] = 'parent-q';
            },
        );
        $coordinator->enqueue(
            $this->agentCoreRequestForRun('child-a-queued', 'child-a'),
            onCancel: static function () use (&$cancelFired): void {
                $cancelFired[] = 'child-a-queued';
            },
        );
        $coordinator->enqueue(
            $this->agentCoreRequestForRun('child-b-q', 'child-b'),
        );

        $this->assertSame('child-a-active', $coordinator->activeRequest()?->requestId);

        $coordinator->removeForRun('child-a');

        $this->assertSame([], $cancelFired, 'removeForRun must not invoke cancel callbacks');
        $this->assertSame([], $answerFired, 'removeForRun must not invoke answer callbacks');
        $this->assertFalse($coordinator->hasRequest('child-a-active'));
        $this->assertFalse($coordinator->hasRequest('child-a-queued'));
        $this->assertTrue($coordinator->hasRequest('parent-q'));
        $this->assertTrue($coordinator->hasRequest('child-b-q'));
        $this->assertSame('parent-q', $coordinator->activeRequest()?->requestId);

        $coordinator->answer('ok');
        $this->assertSame(['parent-q'], $answerFired);
        $this->assertSame('child-b-q', $coordinator->activeRequest()?->requestId);
    }

    public function testRemoveForRunWithNoMatchingRunIsNoOp(): void
    {
        $coordinator = new QuestionCoordinator();
        $coordinator->enqueue($this->agentCoreRequestForRun('parent-q', 'parent-run'));

        $coordinator->removeForRun('missing-run');

        $this->assertSame('parent-q', $coordinator->activeRequest()?->requestId);
        $this->assertTrue($coordinator->hasRequest('parent-q'));
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    private function tuiRequest(string $id, string $prompt = 'Test?'): QuestionRequest
    {
        return new QuestionRequest(
            requestId: $id,
            source: QuestionSource::Tui,
            kind: QuestionKind::Text,
            prompt: $prompt,
        );
    }

    private function agentCoreRequest(string $id, string $prompt = 'Test?'): QuestionRequest
    {
        return new QuestionRequest(
            requestId: $id,
            source: QuestionSource::AgentCore,
            kind: QuestionKind::Text,
            prompt: $prompt,
        );
    }

    private function agentCoreRequestForRun(string $id, string $runId, string $prompt = 'Test?'): QuestionRequest
    {
        return new QuestionRequest(
            requestId: $id,
            source: QuestionSource::AgentCore,
            kind: QuestionKind::Text,
            prompt: $prompt,
            runId: $runId,
        );
    }
}
