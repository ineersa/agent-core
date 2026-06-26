<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Question;

use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Question\QuestionKind;
use Ineersa\Tui\Question\QuestionRequest;
use Ineersa\Tui\Question\QuestionSource;
use Ineersa\Tui\Question\QuestionStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QuestionCoordinator::class)]
final class QuestionCoordinatorTest extends TestCase
{
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

    // ─── Enqueue / activation ──────────────────────────────────────────

    public function testEnqueueActivatesFirstRequest(): void
    {
        $coordinator = new QuestionCoordinator();
        $request = $this->tuiRequest('r1');

        $coordinator->enqueue($request);

        self::assertSame($request, $coordinator->activeRequest());
        self::assertTrue($coordinator->actionRequired());
        self::assertSame(QuestionStatus::Pending, $coordinator->activeStatus());
    }

    public function testEnqueueSecondRequestQueuesBehindActive(): void
    {
        $coordinator = new QuestionCoordinator();
        $r1 = $this->tuiRequest('r1');
        $r2 = $this->tuiRequest('r2');

        $coordinator->enqueue($r1);
        $coordinator->enqueue($r2);

        // First request remains active; second is queued
        self::assertSame($r1, $coordinator->activeRequest());
    }

    public function testActionRequiredFalseInitially(): void
    {
        $coordinator = new QuestionCoordinator();

        self::assertFalse($coordinator->actionRequired());
        self::assertNull($coordinator->activeRequest());
        self::assertNull($coordinator->activeStatus());
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

        self::assertSame($r2, $coordinator->activeRequest());
        self::assertSame(QuestionStatus::Pending, $coordinator->activeStatus());
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

        self::assertSame($r1, $coordinator->activeRequest());

        $coordinator->answer('a');
        self::assertSame($r2, $coordinator->activeRequest());

        $coordinator->answer('b');
        self::assertSame($r3, $coordinator->activeRequest());

        $coordinator->answer('c');
        self::assertNull($coordinator->activeRequest());
        self::assertFalse($coordinator->actionRequired());
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

        self::assertSame($r2, $coordinator->activeRequest());
        self::assertSame(QuestionStatus::Pending, $coordinator->activeStatus());
    }

    public function testCancelAdvancesQueue(): void
    {
        $coordinator = new QuestionCoordinator();
        $r1 = $this->tuiRequest('r1');
        $r2 = $this->tuiRequest('r2');

        $coordinator->enqueue($r1);
        $coordinator->enqueue($r2);

        $coordinator->cancel();

        self::assertSame($r2, $coordinator->activeRequest());
        self::assertSame(QuestionStatus::Pending, $coordinator->activeStatus());
    }

    public function testRejectEmptyIsNoOp(): void
    {
        $coordinator = new QuestionCoordinator();

        // Should not throw
        $coordinator->reject();
        self::assertFalse($coordinator->actionRequired());
    }

    public function testCancelEmptyIsNoOp(): void
    {
        $coordinator = new QuestionCoordinator();

        $coordinator->cancel();
        self::assertFalse($coordinator->actionRequired());
    }

    // ─── Local callback behavior ───────────────────────────────────────

    public function testLocalCallbackInvokedForTuiSource(): void
    {
        $coordinator = new QuestionCoordinator();
        $request = $this->tuiRequest('r1');
        $received = null;

        $coordinator->enqueue($request, function (mixed $value) use (&$received): void {
            $received = $value;
        });

        $coordinator->answer('hello');

        self::assertSame('hello', $received);
        self::assertNull($coordinator->activeRequest());
    }

    public function testCallbackInvokedForAgentCoreSource(): void
    {
        $coordinator = new QuestionCoordinator();
        $request = $this->agentCoreRequest('r1');
        $received = null;

        $coordinator->enqueue($request, function (mixed $value) use (&$received): void {
            $received = $value;
        });

        $coordinator->answer('hello');

        // AgentCore callbacks are now invoked so upper layers can
        // dispatch answer_human commands to the runtime.
        self::assertSame('hello', $received);
        self::assertNull($coordinator->activeRequest());
    }

    public function testCallbackNotCalledOnReject(): void
    {
        $coordinator = new QuestionCoordinator();
        $called = false;

        $coordinator->enqueue($this->tuiRequest('r1'), function () use (&$called): void {
            $called = true;
        });

        $coordinator->reject();

        self::assertFalse($called);
        self::assertNull($coordinator->activeRequest());
    }

    public function testCallbackNotCalledOnCancel(): void
    {
        $coordinator = new QuestionCoordinator();
        $called = false;

        $coordinator->enqueue($this->tuiRequest('r1'), function () use (&$called): void {
            $called = true;
        });

        $coordinator->cancel();

        self::assertFalse($called);
        self::assertNull($coordinator->activeRequest());
    }

    // ─── Cancel callback behavior ─────────────────────────────────────

    public function testCancelCallbackInvokedForAgentCoreSource(): void
    {
        $coordinator = new QuestionCoordinator();
        $request = $this->agentCoreRequest('r1');
        $cancelFired = false;

        $coordinator->enqueue(
            $request,
            onAnswer: function (): void {},
            onCancel: function () use (&$cancelFired): void {
                $cancelFired = true;
            },
        );

        $coordinator->cancel();

        self::assertTrue($cancelFired);
        self::assertNull($coordinator->activeRequest());
    }

    public function testCancelCallbackAdvanceQueue(): void
    {
        $coordinator = new QuestionCoordinator();
        $r1 = $this->agentCoreRequest('r1');
        $r2 = $this->agentCoreRequest('r2');
        $cancelFired = false;

        $coordinator->enqueue(
            $r1,
            onCancel: function () use (&$cancelFired): void {
                $cancelFired = true;
            },
        );
        $coordinator->enqueue($r2);

        $coordinator->cancel();

        // r1 cancelled, r2 becomes active
        self::assertTrue($cancelFired);
        self::assertSame($r2, $coordinator->activeRequest());
        self::assertTrue($coordinator->actionRequired());
    }

    public function testCancelCallbackThrowingStillAdvancesQueue(): void
    {
        $coordinator = new QuestionCoordinator();

        $coordinator->enqueue(
            $this->agentCoreRequest('r1'),
            onCancel: function (): void {
                throw new \RuntimeException('Callback explosion');
            },
        );
        $coordinator->enqueue($this->agentCoreRequest('r2'));

        // The callback exception propagates, but the finally block
        // in cancel() already advanced the queue.
        try {
            $coordinator->cancel();
            self::fail('Expected RuntimeException from cancel callback was not thrown');
        } catch (\RuntimeException $e) {
            self::assertSame('Callback explosion', $e->getMessage());
        }

        // Advance happened in finally block — r2 should be active.
        self::assertSame('r2', $coordinator->activeRequest()?->requestId);
        self::assertTrue($coordinator->actionRequired());
    }

    public function testMultipleQueuedCancelCallbacks(): void
    {
        $coordinator = new QuestionCoordinator();
        $cancelled = [];

        $coordinator->enqueue(
            $this->agentCoreRequest('r1'),
            onCancel: function () use (&$cancelled): void {
                $cancelled[] = 'r1';
            },
        );
        $coordinator->enqueue(
            $this->agentCoreRequest('r2'),
            onCancel: function () use (&$cancelled): void {
                $cancelled[] = 'r2';
            },
        );

        $coordinator->cancel(); // cancels r1
        $coordinator->cancel(); // cancels r2

        self::assertSame(['r1', 'r2'], $cancelled);
        self::assertNull($coordinator->activeRequest());
    }

    public function testMultipleCallbacksInFifoOrder(): void
    {
        $coordinator = new QuestionCoordinator();
        $results = [];

        $coordinator->enqueue($this->tuiRequest('r1'), function (mixed $v) use (&$results): void {
            $results[] = "r1:$v";
        });
        $coordinator->enqueue($this->tuiRequest('r2'), function (mixed $v) use (&$results): void {
            $results[] = "r2:$v";
        });
        $coordinator->enqueue($this->tuiRequest('r3'), function (mixed $v) use (&$results): void {
            $results[] = "r3:$v";
        });

        $coordinator->answer('a');
        $coordinator->answer('b');
        $coordinator->answer('c');

        self::assertSame(['r1:a', 'r2:b', 'r3:c'], $results);
        self::assertNull($coordinator->activeRequest());
    }

    // ─── Mixed source handling ─────────────────────────────────────────

    public function testAgentCoreRequestBetweenTuiRequests(): void
    {
        $coordinator = new QuestionCoordinator();
        $results = [];

        $coordinator->enqueue($this->tuiRequest('r1'), function (mixed $v) use (&$results): void {
            $results[] = "r1:$v";
        });
        $coordinator->enqueue($this->agentCoreRequest('r2'), function (mixed $v) use (&$results): void {
            $results[] = "r2:$v";
        });
        $coordinator->enqueue($this->tuiRequest('r3'), function (mixed $v) use (&$results): void {
            $results[] = "r3:$v";
        });

        // r1 active — answer it
        $coordinator->answer('a');
        self::assertSame(['r1:a'], $results);

        // r2 now active — AgentCore, callback IS invoked so the
        // answer_human command can be dispatched to the runtime.
        self::assertSame('r2', $coordinator->activeRequest()?->requestId);
        $coordinator->answer('b');
        self::assertSame(['r1:a', 'r2:b'], $results);

        // r3 now active
        $coordinator->answer('c');
        self::assertSame(['r1:a', 'r2:b', 'r3:c'], $results);
        self::assertNull($coordinator->activeRequest());
    }

    // ─── Status tracking ───────────────────────────────────────────────

    public function testActiveStatusIsNullWhenEmpty(): void
    {
        $coordinator = new QuestionCoordinator();
        self::assertNull($coordinator->activeStatus());
    }

    public function testActiveStatusAfterAnswerLastRequest(): void
    {
        $coordinator = new QuestionCoordinator();
        $coordinator->enqueue($this->tuiRequest('r1'));

        $coordinator->answer('ok');

        self::assertNull($coordinator->activeStatus());
    }

    // ─── Answer with no active is no-op ────────────────────────────────

    public function testAnswerWithNoActiveRequestIsNoOp(): void
    {
        $coordinator = new QuestionCoordinator();

        // Should not throw
        $coordinator->answer('foo');

        self::assertNull($coordinator->activeRequest());
        self::assertFalse($coordinator->actionRequired());
    }

    // ─── Enqueue without callback ──────────────────────────────────────

    public function testEnqueueWithoutCallbackDoesNotThrowOnAnswer(): void
    {
        $coordinator = new QuestionCoordinator();
        $coordinator->enqueue($this->tuiRequest('r1'));

        // Should not throw even though no callback was registered
        $coordinator->answer('ok');

        self::assertNull($coordinator->activeRequest());
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

        self::assertFalse($coordinator->hasRequest('r1'));

        $coordinator->enqueue($this->tuiRequest('r1'));

        self::assertTrue($coordinator->hasRequest('r1'));
    }

    public function testHasRequestReturnsFalseAfterAdvance(): void
    {
        $coordinator = new QuestionCoordinator();
        $coordinator->enqueue($this->tuiRequest('r1'));

        $coordinator->answer('ok');

        // After the request is answered, it is removed from tracking
        self::assertFalse($coordinator->hasRequest('r1'));
    }

    public function testHasRequestReturnsFalseForUnknownId(): void
    {
        $coordinator = new QuestionCoordinator();

        self::assertFalse($coordinator->hasRequest('nonexistent'));
    }

    // ─── Reset (session switch lifecycle) ──────────────────────────────

    public function testResetWithNoActiveQuestionIsNoOp(): void
    {
        $coordinator = new QuestionCoordinator();

        $coordinator->reset();

        self::assertNull($coordinator->activeRequest());
        self::assertFalse($coordinator->actionRequired());
    }

    public function testResetClearsActiveQuestion(): void
    {
        $coordinator = new QuestionCoordinator();
        $coordinator->enqueue($this->tuiRequest('r1'));

        self::assertTrue($coordinator->actionRequired());

        $coordinator->reset();

        self::assertNull($coordinator->activeRequest());
        self::assertNull($coordinator->activeStatus());
        self::assertFalse($coordinator->actionRequired());
    }

    public function testResetClearsQueuedQuestions(): void
    {
        $coordinator = new QuestionCoordinator();
        $coordinator->enqueue($this->tuiRequest('r1'));
        $coordinator->enqueue($this->tuiRequest('r2'));
        $coordinator->enqueue($this->tuiRequest('r3'));

        // r1 is active, r2+r3 are queued
        self::assertSame('r1', $coordinator->activeRequest()?->requestId);

        $coordinator->reset();

        // After reset, everything is cleared — including the queue
        self::assertNull($coordinator->activeRequest());
        self::assertFalse($coordinator->actionRequired());

        // Enqueueing after reset works normally
        $coordinator->enqueue($this->tuiRequest('r4'));
        self::assertSame('r4', $coordinator->activeRequest()?->requestId);
    }

    public function testResetDoesNotInvokeCallbacks(): void
    {
        $coordinator = new QuestionCoordinator();
        $answerFired = false;
        $cancelFired = false;

        $coordinator->enqueue(
            $this->tuiRequest('r1'),
            onAnswer: function () use (&$answerFired): void {
                $answerFired = true;
            },
            onCancel: function () use (&$cancelFired): void {
                $cancelFired = true;
            },
        );
        $coordinator->enqueue($this->tuiRequest('r2'));

        $coordinator->reset();

        self::assertFalse($answerFired, 'Answer callback must not be invoked during reset');
        self::assertFalse($cancelFired, 'Cancel callback must not be invoked during reset');
        self::assertNull($coordinator->activeRequest());
    }

    public function testResetClearsRequestIdsTracking(): void
    {
        $coordinator = new QuestionCoordinator();
        $coordinator->enqueue($this->tuiRequest('r1'));
        $coordinator->enqueue($this->tuiRequest('r2'));

        self::assertTrue($coordinator->hasRequest('r1'));
        self::assertTrue($coordinator->hasRequest('r2'));

        $coordinator->reset();

        self::assertFalse($coordinator->hasRequest('r1'));
        self::assertFalse($coordinator->hasRequest('r2'));

        // After reset, the same ID can be re-enqueued without triggering the duplicate guard
        $coordinator->enqueue($this->tuiRequest('r1'));
        self::assertTrue($coordinator->hasRequest('r1'));
    }
}
