<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmProviderErrorClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// phpcs:disable Ineersa.Files.LineLength.TooLong

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

        $this->assertFalse($result['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_AUTH, $result['error_category']);
        $this->assertStringContainsString('authentication failed', strtolower($result['user_message']));
        // The detail message is included but truncated — it's not a raw body leak.
    }

    public function testClassify401StatusCode(): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => 'HTTP 401 returned',
            'http_status_code' => 401,
        ]);

        $this->assertFalse($result['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_AUTH, $result['error_category']);
    }

    // ── Bad request errors ─────────────────────────────────────────────────

    public function testClassifyBadRequestException(): void
    {
        $result = $this->classifier->classify([
            'type' => 'Symfony\AI\Exception\BadRequestException',
            'message' => 'Invalid model parameter',
            'http_status_code' => 400,
        ]);

        $this->assertFalse($result['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_BAD_REQUEST, $result['error_category']);
    }

    public function testClassify400StatusCode(): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => 'Bad Request',
            'http_status_code' => 400,
        ]);

        $this->assertFalse($result['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_BAD_REQUEST, $result['error_category']);
    }

    // ── Transient 429 rate limit (retryable) ───────────────────────────────

    public function testClassifyTransient429(): void
    {
        $result = $this->classifier->classify([
            'type' => 'Symfony\AI\Exception\RateLimitExceededException',
            'message' => 'Rate limit exceeded',
            'http_status_code' => 429,
        ]);

        $this->assertTrue($result['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_RATE_LIMIT, $result['error_category']);
        $this->assertStringContainsString('rate limit', strtolower($result['user_message']));
    }

    public function testClassifyTransient429StatusCode(): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => 'Too Many Requests',
            'http_status_code' => 429,
        ]);

        $this->assertTrue($result['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_RATE_LIMIT, $result['error_category']);
    }

    public function testClassifyTransient429IncludesRetryAfter(): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => 'rate limit',
            'http_status_code' => 429,
            'retry_after_ms' => 30000,
        ]);

        $this->assertTrue($result['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_RATE_LIMIT, $result['error_category']);
        $this->assertStringContainsString('30s', $result['user_message'], 'User message should include retry-after hint');
        $this->assertStringContainsString('rate limit', strtolower($result['user_message']));
    }

    public function testClassifyTransient429IncludesProviderCode(): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => 'rate limit exceeded',
            'http_status_code' => 429,
            'response_error_code' => 'rate_limit_exceeded',
        ]);

        $this->assertTrue($result['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_RATE_LIMIT, $result['error_category']);
        $this->assertStringContainsString('rate_limit_exceeded', $result['user_message'], 'User message should include provider code');
        // Raw message text must not be leaked as-is in user_message
        $this->assertStringNotContainsString('raw sentinel', $result['user_message']);
    }

    // ── Terminal billing/quota 429 ─────────────────────────────────────────

    public function testClassifyBilling429(): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => 'You have insufficient_quota for this model',
            'http_status_code' => 429,
        ]);

        $this->assertFalse($result['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_QUOTA_BILLING, $result['error_category']);
        $this->assertStringContainsString('quota or billing', strtolower($result['user_message']));
    }

    /**
     * Terminal billing/quota detected through structured response fields
     * even when the exception message does not contain billing patterns.
     */
    public function testClassifyBillingFromResponseErrorCode(): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => 'rate limit exceeded',
            'http_status_code' => 429,
            'response_error_code' => 'insufficient_quota',
        ]);

        $this->assertFalse($result['retryable'], 'Billing from response_error_code should be terminal');
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_QUOTA_BILLING, $result['error_category']);
        $this->assertStringContainsString('quota or billing', strtolower($result['user_message']));
    }

    public function testClassifyBillingFromResponseErrorMessage(): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => 'something went wrong',
            'http_status_code' => 429,
            'response_error_message' => 'quota exceeded for current billing cycle',
        ]);

        $this->assertFalse($result['retryable'], 'Billing from response_error_message should be terminal');
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_QUOTA_BILLING, $result['error_category']);
        $this->assertStringContainsString('quota or billing', strtolower($result['user_message']));
    }

    public function testClassifyBillingFromResponseErrorType(): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => 'request rejected',
            'http_status_code' => 429,
            'response_error_type' => 'insufficient_credits',
        ]);

        $this->assertFalse($result['retryable'], 'Billing from response_error_type should be terminal');
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_QUOTA_BILLING, $result['error_category']);
    }

    public function testClassifyBilling429WithQuotaExceeded(): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => 'quota exceeded for current month',
            'http_status_code' => 429,
        ]);

        $this->assertFalse($result['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_QUOTA_BILLING, $result['error_category']);
    }

    // ── Server errors (retryable) ──────────────────────────────────────────

    public function testClassifyServer500(): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => 'Internal Server Error',
            'http_status_code' => 500,
        ]);

        $this->assertTrue($result['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_SERVER, $result['error_category']);
    }

    #[DataProvider('retryableServerStatusCodes')]
    public function testClassifyServerErrors(int $statusCode): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => 'Server Error',
            'http_status_code' => $statusCode,
        ]);

        $this->assertTrue($result['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_SERVER, $result['error_category']);
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

        $this->assertTrue($result['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_TIMEOUT, $result['error_category']);
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

        $this->assertTrue($result['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_NETWORK, $result['error_category']);
        $this->assertStringContainsString('network error', strtolower($result['user_message']));
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

        $this->assertFalse($result['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_QUOTA_BILLING, $result['error_category']);
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

        $this->assertFalse($result['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_PROVIDER, $result['error_category']);
        $this->assertStringContainsString('Something unexpected', $result['user_message']);
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

        $this->assertArrayNotHasKey('response_body_preview', $result);
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
        $this->assertSame('RuntimeException', $result['type']);
        $this->assertSame('Server Error', $result['message']);
        $this->assertSame(500, $result['http_status_code']);
        $this->assertSame('internal_error', $result['response_error_code']);
        $this->assertSame('server_error', $result['response_error_type']);
        $this->assertSame('gpt-4', $result['request_model']);

        // New classification fields
        $this->assertTrue($result['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_SERVER, $result['error_category']);
        $this->assertIsString($result['user_message']);
        $this->assertStringContainsString('500', $result['user_message']);
    }

    // ── Empty error ────────────────────────────────────────────────────────

    public function testClassifyEmptyError(): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => '',
        ]);

        $this->assertFalse($result['retryable']);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_PROVIDER, $result['error_category']);
    }

    // ── Context-overflow detection ────────────────────────────────────────

    #[DataProvider('contextOverflowProvider')]
    public function testIsContextOverflowDetectsKnownPatterns(string $message, ?int $statusCode): void
    {
        $error = ['type' => 'RuntimeException', 'message' => $message];
        if (null !== $statusCode) {
            $error['http_status_code'] = $statusCode;
        }

        $classified = $this->classifier->classify($error);
        $this->assertTrue(
            $this->classifier->isContextOverflow($classified),
            \sprintf('Expected context overflow for message: %s', $message),
        );
    }

    /** @return list<array{string, int|null}> */
    public static function contextOverflowProvider(): array
    {
        return [
            'context length exceeded' => ['context length exceeded maximum', 400],
            'maximum context length' => ["this model's maximum context length is 8192", 400],
            'context window exceeded' => ['context window exceeded', 400],
            'token limit' => ['request exceeds token limit', 400],
            'too many tokens' => ['too many tokens in input', 400],
            'reduce the length' => ['please reduce the length of the messages', 400],
            'input length' => ['input length exceeds maximum', 400],
            'context_length_exceeded' => ['error code: context_length_exceeded', 400],
            'max context length' => ['max context length is 4096 tokens', 400],
            'context token limit' => ['context token limit exceeded', 500],
            'server error with context' => ['context length', 503],
            'provider error with context' => ['maximum context window', null],
        ];
    }

    #[DataProvider('nonOverflowProvider')]
    public function testIsContextOverflowReturnsFalseForNonOverflow(int $statusCode, string $message): void
    {
        $error = ['type' => 'RuntimeException', 'message' => $message, 'http_status_code' => $statusCode];
        $classified = $this->classifier->classify($error);

        $this->assertFalse(
            $this->classifier->isContextOverflow($classified),
            \sprintf('Expected non-overflow for status=%d message=%s', $statusCode, $message),
        );
    }

    /** @return list<array{int, string}> */
    public static function nonOverflowProvider(): array
    {
        return [
            'auth 401' => [401, 'unauthorized'],
            'rate limit 429' => [429, 'rate limit exceeded'],
            'server 502' => [502, 'bad gateway'],
            'timeout 408' => [408, 'request timed out'],
            'bad request not overflow' => [400, 'invalid model parameter'],
        ];
    }

    public function testIsContextOverflowFromResponseErrorMessage(): void
    {
        $error = [
            'type' => 'RuntimeException',
            'message' => 'Bad Request',
            'http_status_code' => 400,
            'response_error_message' => 'context length exceeds maximum allowed',
        ];

        $classified = $this->classifier->classify($error);
        $this->assertTrue($this->classifier->isContextOverflow($classified));
    }

    public function testClassifyContextOverflow500IsNonRetryable(): void
    {
        $result = $this->classifier->classify([
            'type' => 'RuntimeException',
            'message' => 'Context size has been exceeded.',
            'http_status_code' => 500,
        ]);

        $this->assertFalse($result['retryable']);
        $this->assertTrue($this->classifier->isContextOverflow($result));
        $this->assertStringNotContainsString('Will retry automatically', $result['user_message']);
    }
}
