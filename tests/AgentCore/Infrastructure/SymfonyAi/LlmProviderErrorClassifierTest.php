<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmProviderErrorClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmProviderErrorClassifier
 */
final class LlmProviderErrorClassifierTest extends TestCase
{
    private LlmProviderErrorClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new LlmProviderErrorClassifier();
    }

    // ── Auth errors ────────────────────────────────────────────────────────

    public function testClassifyAuthenticationException(): void
    {
        $result = $this->classifier->classify([
            'type' => 'Symfony\AI\Exception\AuthenticationException',
            'message' => 'Invalid API key',
            'http_status_code' => 401,
        ]);

        self::assertFalse($result['retryable']);
        self::assertSame(LlmProviderErrorClassifier::CATEGORY_AUTH, $result['error_category']);
        self::assertStringContainsString('authentication failed', strtolower($result['user_message']));
        // The detail message is included but truncated — it's not a raw body leak.
    }

    public function testClassify401StatusCode(): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => 'HTTP 401 returned',
            'http_status_code' => 401,
        ]);

        self::assertFalse($result['retryable']);
        self::assertSame(LlmProviderErrorClassifier::CATEGORY_AUTH, $result['error_category']);
    }

    // ── Bad request errors ─────────────────────────────────────────────────

    public function testClassifyBadRequestException(): void
    {
        $result = $this->classifier->classify([
            'type' => 'Symfony\AI\Exception\BadRequestException',
            'message' => 'Invalid model parameter',
            'http_status_code' => 400,
        ]);

        self::assertFalse($result['retryable']);
        self::assertSame(LlmProviderErrorClassifier::CATEGORY_BAD_REQUEST, $result['error_category']);
    }

    public function testClassify400StatusCode(): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => 'Bad Request',
            'http_status_code' => 400,
        ]);

        self::assertFalse($result['retryable']);
        self::assertSame(LlmProviderErrorClassifier::CATEGORY_BAD_REQUEST, $result['error_category']);
    }

    // ── Transient 429 rate limit (retryable) ───────────────────────────────

    public function testClassifyTransient429(): void
    {
        $result = $this->classifier->classify([
            'type' => 'Symfony\AI\Exception\RateLimitExceededException',
            'message' => 'Rate limit exceeded',
            'http_status_code' => 429,
        ]);

        self::assertTrue($result['retryable']);
        self::assertSame(LlmProviderErrorClassifier::CATEGORY_RATE_LIMIT, $result['error_category']);
        self::assertStringContainsString('rate limit', strtolower($result['user_message']));
    }

    public function testClassifyTransient429StatusCode(): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => 'Too Many Requests',
            'http_status_code' => 429,
        ]);

        self::assertTrue($result['retryable']);
        self::assertSame(LlmProviderErrorClassifier::CATEGORY_RATE_LIMIT, $result['error_category']);
    }

    // ── Terminal billing/quota 429 ─────────────────────────────────────────

    public function testClassifyBilling429(): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => 'You have insufficient_quota for this model',
            'http_status_code' => 429,
        ]);

        self::assertFalse($result['retryable']);
        self::assertSame(LlmProviderErrorClassifier::CATEGORY_QUOTA_BILLING, $result['error_category']);
        self::assertStringContainsString('quota or billing', strtolower($result['user_message']));
    }

    public function testClassifyBilling429WithQuotaExceeded(): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => 'quota exceeded for current month',
            'http_status_code' => 429,
        ]);

        self::assertFalse($result['retryable']);
        self::assertSame(LlmProviderErrorClassifier::CATEGORY_QUOTA_BILLING, $result['error_category']);
    }

    // ── Server errors (retryable) ──────────────────────────────────────────

    public function testClassifyServer500(): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => 'Internal Server Error',
            'http_status_code' => 500,
        ]);

        self::assertTrue($result['retryable']);
        self::assertSame(LlmProviderErrorClassifier::CATEGORY_SERVER, $result['error_category']);
    }

    #[DataProvider('retryableServerStatusCodes')]
    public function testClassifyServerErrors(int $statusCode): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => 'Server Error',
            'http_status_code' => $statusCode,
        ]);

        self::assertTrue($result['retryable']);
        self::assertSame(LlmProviderErrorClassifier::CATEGORY_SERVER, $result['error_category']);
    }

    /** @return list<array{int}> */
    public static function retryableServerStatusCodes(): array
    {
        return [[500], [502], [503], [504]];
    }

    // ── Timeout errors (retryable) ─────────────────────────────────────────

    #[DataProvider('retryableTimeoutStatusCodes')]
    public function testClassifyTimeoutErrors(int $statusCode): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => 'Request timed out',
            'http_status_code' => $statusCode,
        ]);

        self::assertTrue($result['retryable']);
        self::assertSame(LlmProviderErrorClassifier::CATEGORY_TIMEOUT, $result['error_category']);
    }

    /** @return list<array{int}> */
    public static function retryableTimeoutStatusCodes(): array
    {
        return [[408], [425]];
    }

    // ── Network errors (retryable) ─────────────────────────────────────────

    #[DataProvider('networkErrorMessageProvider')]
    public function testClassifyNetworkErrors(string $message): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => $message,
            // No http_status_code — transport exception
        ]);

        self::assertTrue($result['retryable']);
        self::assertSame(LlmProviderErrorClassifier::CATEGORY_NETWORK, $result['error_category']);
        self::assertStringContainsString('network error', strtolower($result['user_message']));
    }

    /** @return list<array{string}> */
    public static function networkErrorMessageProvider(): array
    {
        return [
            ['Connection timed out after 30000ms'],
            ['Connection refused'],
            ['Connection reset by peer'],
            ['Broken pipe'],
            ['Could not resolve host: api.example.com'],
            ['Cannot connect to server'],
        ];
    }

    // ── Quota billing from message pattern without status code ─────────────

    #[DataProvider('billingMessageProvider')]
    public function testClassifyBillingFromMessageWithoutStatusCode(string $message): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => $message,
        ]);

        self::assertFalse($result['retryable']);
        self::assertSame(LlmProviderErrorClassifier::CATEGORY_QUOTA_BILLING, $result['error_category']);
    }

    /** @return list<array{string}> */
    public static function billingMessageProvider(): array
    {
        return [
            ['insufficient_quota'],
            ['You have exceeded your monthly usage limit'],
            ['out of budget error'],
        ];
    }

    // ── Unknown errors ─────────────────────────────────────────────────────

    public function testClassifyUnknownError(): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => 'Something unexpected happened',
            'http_status_code' => 418, // I'm a teapot
        ]);

        self::assertFalse($result['retryable']);
        self::assertSame(LlmProviderErrorClassifier::CATEGORY_PROVIDER, $result['error_category']);
        self::assertStringContainsString('Something unexpected', $result['user_message']);
    }

    // ── Response body preview is stripped ──────────────────────────────────

    public function testClassifyStripsResponseBodyPreview(): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => 'error',
            'http_status_code' => 503,
            'response_body_preview' => 'sensitive data that should not be exposed',
        ]);

        self::assertArrayNotHasKey('response_body_preview', $result);
    }

    // ── Original error fields preserved ────────────────────────────────────

    public function testClassifyPreservesOriginalFields(): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => 'Server Error',
            'http_status_code' => 500,
            'response_error_code' => 'internal_error',
            'response_error_type' => 'server_error',
            'request_model' => 'gpt-4',
        ]);

        // Original fields preserved
        self::assertSame('RuntimeException', $result['type']);
        self::assertSame('Server Error', $result['message']);
        self::assertSame(500, $result['http_status_code']);
        self::assertSame('internal_error', $result['response_error_code']);
        self::assertSame('server_error', $result['response_error_type']);
        self::assertSame('gpt-4', $result['request_model']);

        // New classification fields
        self::assertTrue($result['retryable']);
        self::assertSame(LlmProviderErrorClassifier::CATEGORY_SERVER, $result['error_category']);
        self::assertIsString($result['user_message']);
        self::assertStringContainsString('500', $result['user_message']);
    }

    // ── Empty error ────────────────────────────────────────────────────────

    public function testClassifyEmptyError(): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => '',
        ]);

        self::assertFalse($result['retryable']);
        self::assertSame(LlmProviderErrorClassifier::CATEGORY_PROVIDER, $result['error_category']);
    }
}
