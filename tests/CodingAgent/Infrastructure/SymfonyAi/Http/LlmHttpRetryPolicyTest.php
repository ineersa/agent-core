<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\SymfonyAi\Http;

use Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http\LlmHttpRetryPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\RetryableHttpClient;

final class LlmHttpRetryPolicyTest extends TestCase
{
    public function testConstructUsesDefaultsAndExplicitValues(): void
    {
        $defaults = new LlmHttpRetryPolicy();
        $this->assertSame(LlmHttpRetryPolicy::DEFAULT_TIMEOUT, $defaults->timeout);
        $this->assertSame(LlmHttpRetryPolicy::DEFAULT_MAX_DURATION, $defaults->maxDuration);
        $this->assertSame(LlmHttpRetryPolicy::DEFAULT_MAX_RETRIES, $defaults->maxRetries);
        $this->assertSame(LlmHttpRetryPolicy::DEFAULT_BASE_DELAY_MS, $defaults->baseDelayMs);
        $this->assertSame(LlmHttpRetryPolicy::DEFAULT_MAX_DELAY_MS, $defaults->maxDelayMs);

        $explicit = new LlmHttpRetryPolicy(60, 300, 5, 2_000, 120_000);
        $this->assertSame(60, $explicit->timeout);
        $this->assertSame(300, $explicit->maxDuration);
        $this->assertSame(5, $explicit->maxRetries);
        $this->assertSame(2_000, $explicit->baseDelayMs);
        $this->assertSame(120_000, $explicit->maxDelayMs);
    }

    public function testConstructRejectsInvalidValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LlmHttpRetryPolicy(timeout: 0);
    }

    public function testSymfonyStrategyRetriesTransportAndConfiguredStatusesWithoutInspectingBodies(): void
    {
        $mock = new MockHttpClient([
            new MockResponse('', ['error' => 'opaque transport failure']),
            new MockResponse('anything', ['http_code' => 503]),
            new MockResponse('ok', ['http_code' => 200]),
        ]);
        $client = $this->retryableClient($mock, new LlmHttpRetryPolicy(maxRetries: 2, baseDelayMs: 0));

        $this->assertSame('ok', $client->request('POST', 'https://api.test/chat')->getContent(false));
        $this->assertSame(3, $mock->getRequestsCount());
    }

    public function testSymfonyStrategyDoesNotRetryBasedOnResponseText(): void
    {
        $mock = new MockHttpClient([
            new MockResponse('overloaded service unavailable', ['http_code' => 400]),
        ]);
        $client = $this->retryableClient($mock, new LlmHttpRetryPolicy(maxRetries: 2, baseDelayMs: 0));

        $this->assertSame(400, $client->request('POST', 'https://api.test/chat')->getStatusCode());
        $this->assertSame(1, $mock->getRequestsCount());
    }

    public function testHttpClientOptions(): void
    {
        $policy = new LlmHttpRetryPolicy(timeout: 45, maxDuration: 180);

        $this->assertSame(['timeout' => 45, 'max_duration' => 180], $policy->httpClientOptions());
    }

    private function retryableClient(MockHttpClient $mock, LlmHttpRetryPolicy $policy): RetryableHttpClient
    {
        return new RetryableHttpClient($mock, $policy->retryStrategy(), maxRetries: $policy->maxRetries);
    }
}
