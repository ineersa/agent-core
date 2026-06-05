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
}
