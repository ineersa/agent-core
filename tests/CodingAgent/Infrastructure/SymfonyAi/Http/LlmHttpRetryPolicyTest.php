<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\SymfonyAi\Http;

use Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http\LlmHttpRetryPolicy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http\LlmHttpRetryPolicy
 */
final class LlmHttpRetryPolicyTest extends TestCase
{
    // ── Constructor defaults ────────────────────────────────────────────────

    public function testConstructWithExplicitValues(): void
    {
        $policy = new LlmHttpRetryPolicy(
            timeout: 60,
            maxDuration: 300,
            maxRetries: 5,
            baseDelayMs: 2000,
            maxDelayMs: 120_000,
        );

        self::assertSame(60, $policy->timeout);
        self::assertSame(300, $policy->maxDuration);
        self::assertSame(5, $policy->maxRetries);
        self::assertSame(2_000, $policy->baseDelayMs);
        self::assertSame(120_000, $policy->maxDelayMs);
    }

    public function testConstructDefaults(): void
    {
        $policy = new LlmHttpRetryPolicy();

        self::assertSame(LlmHttpRetryPolicy::DEFAULT_TIMEOUT, $policy->timeout);
        self::assertSame(LlmHttpRetryPolicy::DEFAULT_MAX_DURATION, $policy->maxDuration);
        self::assertSame(LlmHttpRetryPolicy::DEFAULT_MAX_RETRIES, $policy->maxRetries);
        self::assertSame(LlmHttpRetryPolicy::DEFAULT_BASE_DELAY_MS, $policy->baseDelayMs);
        self::assertSame(LlmHttpRetryPolicy::DEFAULT_MAX_DELAY_MS, $policy->maxDelayMs);
    }

    public function testConstructWithZeroRetriesDisablesRetry(): void
    {
        $policy = new LlmHttpRetryPolicy(maxRetries: 0);
        self::assertSame(0, $policy->maxRetries);
    }

    public function testConstructRejectsNegativeTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LlmHttpRetryPolicy(timeout: -1);
    }

    public function testConstructRejectsZeroTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LlmHttpRetryPolicy(timeout: 0);
    }

    public function testConstructRejectsNegativeMaxRetries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LlmHttpRetryPolicy(maxRetries: -1);
    }

    // ── isRetryableError ────────────────────────────────────────────────────

    public function testIsRetryableErrorForTransientStatusCodes(): void
    {
        $policy = new LlmHttpRetryPolicy();
        $body = '{"error": {"message": "Service unavailable"}}';

        foreach ([408, 425, 429, 500, 502, 503, 504] as $status) {
            self::assertTrue(
                $policy->isRetryableError($status, $body),
                \sprintf('Status %d should be retryable', $status),
            );
        }
    }

    public function testIsRetryableErrorForNonRetryableStatusCodes(): void
    {
        $policy = new LlmHttpRetryPolicy();

        // 4xx client errors other than 408/425/429 are not retryable.
        foreach ([400, 401, 403, 404, 405, 413, 422, 451] as $status) {
            self::assertFalse(
                $policy->isRetryableError($status, 'error body'),
                \sprintf('Status %d should not be retryable', $status),
            );
        }
    }

    public function testIsRetryableErrorFor2xxSuccessCode(): void
    {
        $policy = new LlmHttpRetryPolicy();

        self::assertFalse($policy->isRetryableError(200, 'ok'));
        self::assertFalse($policy->isRetryableError(201, 'created'));
        self::assertFalse($policy->isRetryableError(204, 'no content'));
    }

    public function testIsRetryableErrorForTransientErrorTextPattern(): void
    {
        $policy = new LlmHttpRetryPolicy();

        // Non-standard retryable code but with transient text.
        self::assertTrue($policy->isRetryableError(400, 'upstream connect error'));
        self::assertTrue($policy->isRetryableError(400, 'overloaded'));
        self::assertTrue($policy->isRetryableError(400, 'service unavailable'));
    }

    public function testIsRetryableErrorReturnsFalseForTerminalBilling429(): void
    {
        $policy = new LlmHttpRetryPolicy();
        $billingBodies = [
            '{"error": "insufficient_quota"}',
            '{"error": {"message": "quota exceeded"}}',
            '{"error": {"message": "You have exceeded your monthly usage limit"}}',
            'out of budget',
            'available balance',
        ];

        foreach ($billingBodies as $body) {
            self::assertFalse(
                $policy->isRetryableError(429, $body),
                \sprintf('429 with body "%s" should NOT be retryable', $body),
            );
        }
    }

    // ── isTerminalBillingError ──────────────────────────────────────────────

    public function testIsTerminalBillingErrorFor429WithBillingText(): void
    {
        $policy = new LlmHttpRetryPolicy();

        self::assertTrue($policy->isTerminalBillingError(429, 'insufficient_quota'));
        self::assertTrue($policy->isTerminalBillingError(429, 'quota exceeded'));
        self::assertTrue($policy->isTerminalBillingError(429, 'billing limit'));
        self::assertTrue($policy->isTerminalBillingError(429, 'out of budget'));
    }

    public function testIsTerminalBillingErrorIgnoresNon429(): void
    {
        $policy = new LlmHttpRetryPolicy();

        self::assertFalse($policy->isTerminalBillingError(503, 'insufficient_quota'));
        self::assertFalse($policy->isTerminalBillingError(400, 'billing'));
        self::assertFalse($policy->isTerminalBillingError(500, 'quota exceeded'));
    }

    public function testIsTerminalBillingErrorReturnsFalseForTransient429(): void
    {
        $policy = new LlmHttpRetryPolicy();

        self::assertFalse($policy->isTerminalBillingError(429, 'rate limit exceeded'));
        self::assertFalse($policy->isTerminalBillingError(429, 'Too Many Requests'));
        self::assertFalse($policy->isTerminalBillingError(429, null));
        self::assertFalse($policy->isTerminalBillingError(429, ''));
    }

    // ── isRetryableTransportError ──────────────────────────────────────────

    public function testIsRetryableTransportError(): void
    {
        $policy = new LlmHttpRetryPolicy();

        $retryableMessages = [
            'Connection timed out',
            'Operation timed out after 30000ms',
            'Connection refused',
            'Connection reset by peer',
            'Broken pipe',
            'Could not resolve host',
            'Cannot connect to server',
            'Stream ended before',
            'Read error',
        ];

        foreach ($retryableMessages as $msg) {
            $e = new \RuntimeException($msg);
            self::assertTrue(
                $policy->isRetryableTransportError($e),
                \sprintf('Message "%s" should be retryable transport error', $msg),
            );
        }
    }

    public function testIsRetryableTransportErrorReturnsFalseForNonTransport(): void
    {
        $policy = new LlmHttpRetryPolicy();

        $e = new \RuntimeException('HTTP 500 Internal Server Error');
        self::assertFalse($policy->isRetryableTransportError($e));
    }

    // ── parseRetryAfterMs ──────────────────────────────────────────────────

    public function testParseRetryAfterMsForOpenAiCustomHeader(): void
    {
        $policy = new LlmHttpRetryPolicy();

        $result = $policy->parseRetryAfterMs(['retry-after-ms' => ['5000']]);
        self::assertSame(5000, $result);
    }

    public function testParseRetryAfterMsForStandardSeconds(): void
    {
        $policy = new LlmHttpRetryPolicy();

        $result = $policy->parseRetryAfterMs(['retry-after' => ['30']]);
        self::assertSame(30_000, $result);
    }

    public function testParseRetryAfterMsPrefersMsOverSeconds(): void
    {
        $policy = new LlmHttpRetryPolicy();

        $result = $policy->parseRetryAfterMs([
            'retry-after-ms' => ['2000'],
            'retry-after' => ['60'],
        ]);
        // retry-after-ms should take priority
        self::assertSame(2000, $result);
    }

    public function testParseRetryAfterMsReturnsNullWhenNoHeader(): void
    {
        $policy = new LlmHttpRetryPolicy();

        self::assertNull($policy->parseRetryAfterMs([]));
        self::assertNull($policy->parseRetryAfterMs(['content-type' => ['application/json']]));
    }

    // ── calculateDelayMs ──────────────────────────────────────────────────

    public function testCalculateDelayMsUsesRetryAfterWhenGiven(): void
    {
        $policy = new LlmHttpRetryPolicy(maxDelayMs: 120_000);

        self::assertSame(5000, $policy->calculateDelayMs(0, 5000));
        self::assertSame(30000, $policy->calculateDelayMs(2, 30000));
    }

    public function testCalculateDelayMsCapsToMaxDelay(): void
    {
        $policy = new LlmHttpRetryPolicy(maxDelayMs: 10_000);

        // retry-after exceeds cap
        self::assertSame(10000, $policy->calculateDelayMs(0, 30000));
        // backoff exceeds cap
        self::assertSame(10000, $policy->calculateDelayMs(10, null));
    }

    public function testCalculateDelayMsExponentialBackoff(): void
    {
        $policy = new LlmHttpRetryPolicy(baseDelayMs: 1000, maxDelayMs: 120_000);

        self::assertSame(1000, $policy->calculateDelayMs(0, null));  // 1000 * 2^0
        self::assertSame(2000, $policy->calculateDelayMs(1, null));  // 1000 * 2^1
        self::assertSame(4000, $policy->calculateDelayMs(2, null));  // 1000 * 2^2
        self::assertSame(8000, $policy->calculateDelayMs(3, null));  // 1000 * 2^3
    }

    // ── httpClientOptions ─────────────────────────────────────────────────

    public function testHttpClientOptions(): void
    {
        $policy = new LlmHttpRetryPolicy(timeout: 45, maxDuration: 180);
        $options = $policy->httpClientOptions();

        self::assertSame(45, $options['timeout']);
        self::assertSame(180, $options['max_duration']);
    }
}
