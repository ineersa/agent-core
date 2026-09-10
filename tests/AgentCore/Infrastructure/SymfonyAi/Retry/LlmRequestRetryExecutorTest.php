<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi\Retry;

use Ineersa\AgentCore\Contract\Hook\LlmRequestRetryObserverInterface;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmProviderErrorClassifier;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\Retry\LlmRequestRetryExecutor;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\Retry\LlmRequestRetryPolicy;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TimeoutException;

final class LlmRequestRetryExecutorTest extends TestCase
{
    public function testHttp400ThenSuccessWithinBudget(): void
    {
        $attempts = 0;
        $observer = new class implements LlmRequestRetryObserverInterface {
            /** @var list<array<string, mixed>> */
            public array $retries = [];

            public function onRequestRetry(string $runId, ?string $stepId, array $retry): void
            {
                $this->retries[] = $retry;
            }
        };
        $executor = new LlmRequestRetryExecutor(
            policy: new LlmRequestRetryPolicy(maxRetries: 5, baseDelayMs: 0),
            clock: new MockClock(),
            retryObserver: $observer,
        );

        $result = $executor->execute(
            invoke: static function () use (&$attempts): PlatformInvocationResult {
                ++$attempts;
                if (1 === $attempts) {
                    return new PlatformInvocationResult(
                        assistantMessage: null,
                        error: (new LlmProviderErrorClassifier())->classify([
                            'type' => \Symfony\AI\Platform\Exception\BadRequestException::class,
                            'message' => '<html>Bad Request</html>',
                            'http_status_code' => 400,
                        ]),
                        stopReason: 'error',
                    );
                }

                return new PlatformInvocationResult(assistantMessage: null, stopReason: 'stop', model: 'zai/glm-5.3');
            },
            isCancelled: static fn (): bool => false,
            runId: 'run-400',
            stepId: 'step-400',
        );

        $this->assertNull($result->error);
        $this->assertSame(2, $attempts);
        $this->assertSame(1, $observer->retries[0]['attempt']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_BAD_REQUEST, $observer->retries[0]['error_category']);
    }

    public function testRetriesTimeoutThenSucceedsWithinBudget(): void
    {
        $attempts = 0;
        $observer = new class implements LlmRequestRetryObserverInterface {
            /** @var list<array<string, mixed>> */
            public array $retries = [];

            public function onRequestRetry(string $runId, ?string $stepId, array $retry): void
            {
                $this->retries[] = $retry;
            }
        };
        $clock = new MockClock();
        $executor = new LlmRequestRetryExecutor(
            policy: new LlmRequestRetryPolicy(maxRetries: 5, baseDelayMs: 10, maxDelayMs: 10),
            clock: $clock,
            retryObserver: $observer,
            logger: new TestLogger(),
        );

        $result = $executor->execute(
            invoke: static function () use (&$attempts): PlatformInvocationResult {
                ++$attempts;
                if ($attempts < 3) {
                    return new PlatformInvocationResult(
                        assistantMessage: null,
                        error: (new LlmProviderErrorClassifier())->classify([
                            'type' => TimeoutException::class,
                            'message' => 'Idle timeout reached',
                        ]),
                        stopReason: 'error',
                    );
                }

                return new PlatformInvocationResult(
                    assistantMessage: null,
                    stopReason: 'stop',
                    model: 'zai/glm-5.3',
                );
            },
            isCancelled: static fn (): bool => false,
            runId: 'run-1',
            stepId: 'step-1',
        );

        $this->assertNull($result->error);
        $this->assertSame(3, $attempts);
        $this->assertCount(2, $observer->retries);
        $this->assertSame(1, $observer->retries[0]['attempt']);
        $this->assertSame(5, $observer->retries[0]['max_attempts']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_TIMEOUT, $observer->retries[0]['error_category']);
    }

    public function testExhaustionMarksRetryExhaustedAndStops(): void
    {
        $attempts = 0;
        $executor = new LlmRequestRetryExecutor(
            policy: new LlmRequestRetryPolicy(maxRetries: 2, baseDelayMs: 0),
            clock: new MockClock(),
        );

        $result = $executor->execute(
            invoke: static function () use (&$attempts): PlatformInvocationResult {
                ++$attempts;

                return new PlatformInvocationResult(
                    assistantMessage: null,
                    error: (new LlmProviderErrorClassifier())->classify([
                        'type' => TimeoutException::class,
                        'message' => 'Idle timeout reached',
                    ]),
                    stopReason: 'error',
                );
            },
            isCancelled: static fn (): bool => false,
            runId: 'run-1',
            stepId: 'step-1',
        );

        $this->assertSame(3, $attempts);
        $this->assertFalse($result->error['retryable'] ?? true);
        $this->assertTrue($result->error['retry_exhausted'] ?? false);
        $this->assertSame(2, $result->error['retry_attempt_index'] ?? null);
        $this->assertSame(3, $result->error['retry_max_attempts'] ?? null);
        $this->assertStringContainsString('after retries were exhausted', (string) ($result->error['user_message'] ?? ''));
    }

    public function testCancellationStopsBeforeBackoffCompletes(): void
    {
        $attempts = 0;
        $cancelAfterFirst = false;
        $clock = new MockClock('2020-01-01T00:00:00+00:00');
        $executor = new LlmRequestRetryExecutor(
            policy: new LlmRequestRetryPolicy(maxRetries: 5, baseDelayMs: 1_000),
            clock: $clock,
        );

        $result = $executor->execute(
            invoke: static function () use (&$attempts, &$cancelAfterFirst): PlatformInvocationResult {
                ++$attempts;
                $cancelAfterFirst = true;

                return new PlatformInvocationResult(
                    assistantMessage: null,
                    error: (new LlmProviderErrorClassifier())->classify([
                        'type' => TimeoutException::class,
                        'message' => 'Idle timeout reached',
                    ]),
                    stopReason: 'error',
                    model: 'm',
                );
            },
            isCancelled: static function () use (&$cancelAfterFirst): bool {
                return $cancelAfterFirst;
            },
            runId: 'run-1',
            stepId: 'step-1',
        );

        $this->assertSame(1, $attempts);
        $this->assertNull($result->error);
        $this->assertSame('aborted', $result->stopReason);
        // Deadline uses injected clock; sleep must advance MockClock and still honor cancel.
        $this->assertSame('2020-01-01T00:00:00+00:00', $clock->now()->format(\DateTimeInterface::ATOM));
    }

    public function testLocalProgrammingErrorsAreNotRetried(): void
    {
        $attempts = 0;
        $executor = new LlmRequestRetryExecutor(
            policy: new LlmRequestRetryPolicy(maxRetries: 5, baseDelayMs: 0),
            clock: new MockClock(),
        );

        $result = $executor->execute(
            invoke: static function () use (&$attempts): PlatformInvocationResult {
                ++$attempts;

                return new PlatformInvocationResult(
                    assistantMessage: null,
                    error: (new LlmProviderErrorClassifier())->classify([
                        'type' => \TypeError::class,
                        'message' => 'invalid local value',
                    ]),
                    stopReason: 'error',
                );
            },
            isCancelled: static fn (): bool => false,
            runId: 'run-1',
            stepId: null,
        );

        $this->assertSame(1, $attempts);
        $this->assertFalse($result->error['retryable'] ?? true);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_UNKNOWN, $result->error['error_category'] ?? null);
        $this->assertArrayNotHasKey('retry_exhausted', $result->error ?? []);
    }

    public function testBackoffDeadlineUsesInjectedClockAndObserverReportsRetryBudget(): void
    {
        $attempts = 0;
        $observer = new class implements LlmRequestRetryObserverInterface {
            /** @var list<array<string, mixed>> */
            public array $retries = [];

            public function onRequestRetry(string $runId, ?string $stepId, array $retry): void
            {
                $this->retries[] = $retry;
            }
        };
        $clock = new MockClock('2020-01-01T00:00:00+00:00');
        $executor = new LlmRequestRetryExecutor(
            policy: new LlmRequestRetryPolicy(maxRetries: 5, baseDelayMs: 100, maxDelayMs: 100),
            clock: $clock,
            retryObserver: $observer,
        );

        $result = $executor->execute(
            invoke: static function () use (&$attempts): PlatformInvocationResult {
                ++$attempts;
                if (1 === $attempts) {
                    return new PlatformInvocationResult(
                        assistantMessage: null,
                        error: (new LlmProviderErrorClassifier())->classify([
                            'type' => TimeoutException::class,
                            'message' => 'Idle timeout reached',
                        ]),
                        stopReason: 'error',
                    );
                }

                return new PlatformInvocationResult(assistantMessage: null, stopReason: 'stop', model: 'm');
            },
            isCancelled: static fn (): bool => false,
            runId: 'run-clock',
            stepId: 'step-clock',
        );

        $this->assertNull($result->error);
        $this->assertSame(2, $attempts);
        $this->assertSame(1, $observer->retries[0]['attempt']);
        $this->assertSame(5, $observer->retries[0]['max_attempts']);
        $this->assertSame(100, $observer->retries[0]['delay_ms']);
        $this->assertSame('LLM provider request timed out.', $observer->retries[0]['reason']);
        $this->assertSame('2020-01-01T00:00:00.100000+00:00', $clock->now()->format('Y-m-d\TH:i:s.uP'));
    }

    public function testPartialFailedAttemptIsDiscardedOnRetrySuccess(): void
    {
        $attempts = 0;
        $executor = new LlmRequestRetryExecutor(
            policy: new LlmRequestRetryPolicy(maxRetries: 2, baseDelayMs: 0),
            clock: new MockClock(),
        );

        $result = $executor->execute(
            invoke: static function () use (&$attempts): PlatformInvocationResult {
                ++$attempts;
                if (1 === $attempts) {
                    return new PlatformInvocationResult(
                        assistantMessage: null,
                        deltas: [new \Symfony\AI\Platform\Result\Stream\Delta\TextDelta('partial-should-not-survive')],
                        error: (new LlmProviderErrorClassifier())->classify([
                            'type' => TimeoutException::class,
                            'message' => 'Idle timeout reached',
                        ]),
                        stopReason: 'error',
                        model: 'partial-model',
                    );
                }

                return new PlatformInvocationResult(
                    assistantMessage: null,
                    deltas: [new \Symfony\AI\Platform\Result\Stream\Delta\TextDelta('final')],
                    stopReason: 'stop',
                    model: 'final-model',
                );
            },
            isCancelled: static fn (): bool => false,
            runId: 'run-partial',
            stepId: 'step-partial',
        );

        $this->assertNull($result->error);
        $this->assertSame('final-model', $result->model);
        $this->assertCount(1, $result->deltas);
        $this->assertSame('final', $result->deltas[0]->getText());
        $this->assertSame(2, $attempts);
    }
}
