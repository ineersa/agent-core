<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\SymfonyAi\Http;

use Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http\LlmHttpRetryPolicy;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http\LlmHttpRetryStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\RetryableHttpClient;

/**
 * @covers \Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http\LlmHttpRetryStrategy
 */
final class LlmHttpRetryStrategyTest extends TestCase
{
    // ── Strategy wired through native RetryableHttpClient ────────────────

    public function testRetriesRetryableStatusThenSucceeds(): void
    {
        $mock = new MockHttpClient([
            new MockResponse('Service Unavailable', ['http_code' => 503]),
            new MockResponse('ok', ['http_code' => 200]),
        ]);
        $client = $this->retryableClient($mock, new LlmHttpRetryPolicy(maxRetries: 2, baseDelayMs: 0));

        $response = $client->request('POST', 'https://api.test/chat');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent(false));
        // Initial attempt + 1 retry = 2 requests.
        $this->assertSame(2, $mock->getRequestsCount());
    }

    public function testDoesNotRetryTerminalBilling429(): void
    {
        $mock = new MockHttpClient([
            new MockResponse('{"error":{"message":"insufficient_quota"}}', ['http_code' => 429]),
        ]);
        $client = $this->retryableClient($mock, new LlmHttpRetryPolicy(maxRetries: 2, baseDelayMs: 0));

        $response = $client->request('POST', 'https://api.test/chat');
        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame(1, $mock->getRequestsCount());
    }

    public function testRetriesTransientBodyOnNonstandardStatus(): void
    {
        // 529 is not in the retryable status list; the body pattern decides.
        $mock = new MockHttpClient([
            new MockResponse('{"error":{"message":"overloaded"}}', ['http_code' => 529]),
            new MockResponse('ok', ['http_code' => 200]),
        ]);
        $client = $this->retryableClient($mock, new LlmHttpRetryPolicy(maxRetries: 2, baseDelayMs: 0));

        $response = $client->request('POST', 'https://api.test/chat');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $mock->getRequestsCount());
    }

    public function testStopsAfterConfiguredMaxRetries(): void
    {
        $mock = new MockHttpClient([
            new MockResponse('Server Error', ['http_code' => 503]),
            new MockResponse('Server Error', ['http_code' => 503]),
        ]);
        $client = $this->retryableClient($mock, new LlmHttpRetryPolicy(maxRetries: 1, baseDelayMs: 0));

        $response = $client->request('POST', 'https://api.test/chat');
        $this->assertSame(503, $response->getStatusCode());
        // Initial attempt + 1 retry = 2 requests, then the last error is returned.
        $this->assertSame(2, $mock->getRequestsCount());
    }

    public function testRetriesTransportTimeout(): void
    {
        $attempts = 0;

        $mock = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$attempts): MockResponse {
                ++$attempts;

                if (1 === $attempts) {
                    // Simulate a transport-level timeout (never reaches HTTP).
                    return new MockResponse('', ['error' => 'Connection timed out after 30000ms']);
                }

                return new MockResponse('ok', ['http_code' => 200]);
            },
        );
        $client = $this->retryableClient($mock, new LlmHttpRetryPolicy(maxRetries: 2, baseDelayMs: 0));

        $response = $client->request('POST', 'https://api.test/chat');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $attempts);
    }

    // ── Strategy decisions (AsyncContext unit level) ─────────────────────

    public function testShouldRetryRequestsBodyForErrorStatus(): void
    {
        $strategy = new LlmHttpRetryStrategy(new LlmHttpRetryPolicy());

        $context = $this->context(httpCode: 503);
        $this->assertNull($strategy->shouldRetry($context, null, null));
        $this->assertTrue($strategy->shouldRetry($context, 'Service Unavailable', null));
    }

    public function testShouldRetryPassesThroughSuccessWithoutBody(): void
    {
        $strategy = new LlmHttpRetryStrategy(new LlmHttpRetryPolicy());

        $this->assertFalse($strategy->shouldRetry($this->context(httpCode: 200), null, null));
    }

    public function testShouldRetryRejectsTerminalBillingBody(): void
    {
        $strategy = new LlmHttpRetryStrategy(new LlmHttpRetryPolicy());

        $this->assertFalse($strategy->shouldRetry($this->context(httpCode: 429), '{"error":{"message":"insufficient_quota"}}', null));
    }

    public function testShouldRetryRejectsTerminalBillingTransportText(): void
    {
        $strategy = new LlmHttpRetryStrategy(new LlmHttpRetryPolicy());

        $this->assertFalse($strategy->shouldRetry($this->context(), null, new TransportException('insufficient_quota')));
    }

    public function testGetDelayUsesRetryAfterMsHeader(): void
    {
        $strategy = new LlmHttpRetryStrategy(new LlmHttpRetryPolicy(maxDelayMs: 60_000));

        $this->assertSame(500, $strategy->getDelay($this->context(headers: ['retry-after-ms' => '500']), null, null));
    }

    public function testGetDelayUsesExponentialBackoffByRetryCount(): void
    {
        $strategy = new LlmHttpRetryStrategy(new LlmHttpRetryPolicy(baseDelayMs: 1_000, maxDelayMs: 60_000));

        // retry_count is 0-based: attempt 2 => 1000 * 2^2 = 4000ms.
        $this->assertSame(4_000, $strategy->getDelay($this->context(retryCount: 2), null, null));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function retryableClient(MockHttpClient $mock, LlmHttpRetryPolicy $policy): RetryableHttpClient
    {
        return new RetryableHttpClient($mock, new LlmHttpRetryStrategy($policy), maxRetries: $policy->maxRetries);
    }

    /**
     * Build an AsyncContext backed by a MockResponse.
     *
     * @param array<string, string> $headers
     */
    private function context(int $httpCode = 200, int $retryCount = 0, array $headers = []): AsyncContext
    {
        $passthru = null;
        $client = new MockHttpClient();
        $response = new MockResponse('', [
            'http_code' => $httpCode,
            'response_headers' => $headers,
        ]);
        $info = ['retry_count' => $retryCount];

        return new AsyncContext($passthru, $client, $response, $info, null, 0);
    }
}
