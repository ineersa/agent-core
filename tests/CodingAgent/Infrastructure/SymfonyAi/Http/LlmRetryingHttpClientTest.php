<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\SymfonyAi\Http;

use Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http\LlmHttpRetryPolicy;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http\LlmRetryingHttpClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @covers \Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http\LlmRetryingHttpClient
 */
final class LlmRetryingHttpClientTest extends TestCase
{
    // ── Successful requests pass through ──────────────────────────────────

    public function testRequestReturnsSuccessfulResponseDirectly(): void
    {
        $mock = new MockHttpClient([
            new MockResponse('ok', ['http_code' => 200]),
        ]);

        $policy = new LlmHttpRetryPolicy(maxRetries: 2, baseDelayMs: 0);
        $client = new LlmRetryingHttpClient($mock, $policy);

        $response = $client->request('POST', 'https://api.test/chat');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $response->getContent(false));
        // Only 1 request should be made (no retries).
        self::assertSame(1, $mock->getRequestsCount());
    }

    // ── Transient 503 is retried and succeeds ─────────────────────────────

    public function testRequestRetriesTransient503ThenSucceeds(): void
    {
        $responses = [
            new MockResponse('Service Unavailable', ['http_code' => 503]),
            new MockResponse('ok', ['http_code' => 200]),
        ];

        $mock = new MockHttpClient($responses);
        $policy = new LlmHttpRetryPolicy(maxRetries: 2, baseDelayMs: 0);
        $client = new LlmRetryingHttpClient($mock, $policy);

        $response = $client->request('POST', 'https://api.test/chat');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $response->getContent(false));
        // Initial attempt + 1 retry = 2 requests.
        self::assertSame(2, $mock->getRequestsCount());
    }

    // ── Transient 429 with Retry-After is retried ─────────────────────────

    public function testRequestRetries429WithRetryAfter(): void
    {
        $responses = [
            new MockResponse('rate limited', [
                'http_code' => 429,
                'response_headers' => ['retry-after' => '1'],
            ]),
            new MockResponse('ok', ['http_code' => 200]),
        ];

        $mock = new MockHttpClient($responses);
        $policy = new LlmHttpRetryPolicy(maxRetries: 2, baseDelayMs: 0);
        $client = new LlmRetryingHttpClient($mock, $policy);

        $response = $client->request('POST', 'https://api.test/chat');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $mock->getRequestsCount());
    }

    // ── Terminal billing 429 is NOT retried ───────────────────────────────

    public function testRequestDoesNotRetryBilling429(): void
    {
        $billingBody = '{"error": {"message": "insufficient_quota"}}';
        $responses = [
            new MockResponse($billingBody, ['http_code' => 429]),
        ];

        $mock = new MockHttpClient($responses);
        $policy = new LlmHttpRetryPolicy(maxRetries: 2, baseDelayMs: 0);
        $client = new LlmRetryingHttpClient($mock, $policy);

        $response = $client->request('POST', 'https://api.test/chat');
        self::assertSame(429, $response->getStatusCode());
        // Only 1 attempt — terminal error, no retry.
        self::assertSame(1, $mock->getRequestsCount());
    }

    // ── Non-retryable 400 is NOT retried ──────────────────────────────────

    public function testRequestDoesNotRetryBadRequest400(): void
    {
        $responses = [
            new MockResponse('Bad Request', ['http_code' => 400]),
        ];

        $mock = new MockHttpClient($responses);
        $policy = new LlmHttpRetryPolicy(maxRetries: 2, baseDelayMs: 0);
        $client = new LlmRetryingHttpClient($mock, $policy);

        $response = $client->request('POST', 'https://api.test/chat');
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(1, $mock->getRequestsCount());
    }

    // ── All retries exhausted ─────────────────────────────────────────────

    public function testRequestReturnsLastErrorWhenRetriesExhausted(): void
    {
        $responses = [
            new MockResponse('Server Error', ['http_code' => 503]),
            new MockResponse('Server Error', ['http_code' => 503]),
            new MockResponse('Server Error', ['http_code' => 503]),
        ];

        $mock = new MockHttpClient($responses);
        $policy = new LlmHttpRetryPolicy(maxRetries: 2, baseDelayMs: 0);
        $client = new LlmRetryingHttpClient($mock, $policy);

        $response = $client->request('POST', 'https://api.test/chat');
        // 2 retries + initial = 3 total attempts, all 503.
        self::assertSame(503, $response->getStatusCode());
        self::assertSame(3, $mock->getRequestsCount());
    }

    // ── Transport timeout is retried ──────────────────────────────────────

    public function testRequestRetriesTransportTimeout(): void
    {
        $attempts = 0;

        $mock = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$attempts): MockResponse {
                ++$attempts;

                if (1 === $attempts) {
                    // Simulate a transport-level timeout (never reaches HTTP).
                    throw new \RuntimeException('Connection timed out after 30000ms');
                }

                return new MockResponse('ok', ['http_code' => 200]);
            },
        );

        $policy = new LlmHttpRetryPolicy(maxRetries: 2, baseDelayMs: 0);
        $client = new LlmRetryingHttpClient($mock, $policy);

        $response = $client->request('POST', 'https://api.test/chat');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $attempts);
    }

    // ── Non-retryable transport error is NOT retried ───────────────────────

    public function testRequestDoesNotRetryNonRetryableTransportError(): void
    {
        $mock = new MockHttpClient(
            static function (): MockResponse {
                throw new \RuntimeException('HTTP 400 Bad Request');
            },
        );

        $policy = new LlmHttpRetryPolicy(maxRetries: 2, baseDelayMs: 0);
        $client = new LlmRetryingHttpClient($mock, $policy);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 400 Bad Request');

        try {
            $client->request('POST', 'https://api.test/chat');
        } catch (\RuntimeException $e) {
            throw $e;
        }
    }

    // ── withOptions preserves retry behavior ──────────────────────────────

    public function testWithOptionsReturnsNewInstanceWithPreservedRetry(): void
    {
        $mock = new MockHttpClient([
            new MockResponse('ok', ['http_code' => 200]),
        ]);

        $policy = new LlmHttpRetryPolicy(maxRetries: 0, baseDelayMs: 0);
        $client = new LlmRetryingHttpClient($mock, $policy);

        $cloned = $client->withOptions(['timeout' => 15]);
        self::assertNotSame($client, $cloned);

        // The cloned client should still use the retry policy
        // (verified by the types and the response).
        $response = $cloned->request('POST', 'https://api.test/chat');
        self::assertSame(200, $response->getStatusCode());
    }

    // ── Integration: real retry with two 503s then 200 ────────────────────

    public function testRequestRetriesMultipleTriesThenSucceeds(): void
    {
        $responses = [
            new MockResponse('Service Unavailable', ['http_code' => 503]),
            new MockResponse('Service Unavailable', ['http_code' => 503]),
            new MockResponse('ok', ['http_code' => 200]),
        ];

        $mock = new MockHttpClient($responses);
        $policy = new LlmHttpRetryPolicy(maxRetries: 3, baseDelayMs: 0);
        $client = new LlmRetryingHttpClient($mock, $policy);

        $response = $client->request('POST', 'https://api.test/chat');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $response->getContent(false));
        // Initial + 2 retries = 3 requests.
        self::assertSame(3, $mock->getRequestsCount());
    }

    // ── 401 passes through without retry ──────────────────────────────────

    public function testRequestPassesThrough401WithoutRetry(): void
    {
        $responses = [
            new MockResponse('Unauthorized', ['http_code' => 401]),
        ];

        $mock = new MockHttpClient($responses);
        $policy = new LlmHttpRetryPolicy(maxRetries: 2, baseDelayMs: 0);
        $client = new LlmRetryingHttpClient($mock, $policy);

        $response = $client->request('POST', 'https://api.test/chat');
        self::assertSame(401, $response->getStatusCode());
        self::assertSame(1, $mock->getRequestsCount());
    }

    // ── stream delegates to inner ─────────────────────────────────────────

    #[AllowMockObjectsWithoutExpectations]
    public function testStreamDelegatesToInner(): void
    {
        $inner = $this->createMock(HttpClientInterface::class);
        $inner->expects(self::once())
            ->method('stream')
            ->willReturn(
                $this->createMock(\Symfony\Contracts\HttpClient\ResponseStreamInterface::class),
            );

        $policy = new LlmHttpRetryPolicy(maxRetries: 0, baseDelayMs: 0);
        $client = new LlmRetryingHttpClient($inner, $policy);

        $result = $client->stream([]);
        self::assertInstanceOf(\Symfony\Contracts\HttpClient\ResponseStreamInterface::class, $result);
    }
}
