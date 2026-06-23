<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

/**
 * Classifies LLM provider errors by category, retryability, and safe user-facing message.
 *
 * Takes the raw error array from {@see \Ineersa\AgentCore\Domain\Model\PlatformInvocationResult}
 * and adds classification fields:
 *   - retryable (bool): whether the caller should retry the request
 *   - error_category (string): one of the CATEGORY_* constants
 *   - user_message (string): sanitized, user-facing diagnostic suitable for red TUI error blocks
 *
 * The original error fields (type, message, http_status_code, etc.) are preserved.
 * Raw response body previews are stripped to avoid leaking sensitive content.
 */
final class LlmProviderErrorClassifier
{
    public const string CATEGORY_AUTH = 'auth';
    public const string CATEGORY_BAD_REQUEST = 'bad_request';
    public const string CATEGORY_QUOTA_BILLING = 'quota_or_billing';
    public const string CATEGORY_RATE_LIMIT = 'rate_limit';
    public const string CATEGORY_SERVER = 'server';
    public const string CATEGORY_TIMEOUT = 'timeout';
    public const string CATEGORY_NETWORK = 'network';
    public const string CATEGORY_PROVIDER = 'provider';
    public const string CATEGORY_UNKNOWN = 'unknown';

    private const array TERMINAL_BILLING_PATTERNS = [
        'insufficient_quota',
        'quota exceeded',
        'quota_exceeded',
        'billing',
        'out of budget',
        'available balance',
        'monthly usage limit',
        'GoUsageLimitError',
        'FreeUsageLimitError',
        'insufficient_credits',
    ];

    private const array TRANSPORT_ERROR_PATTERNS = [
        'timeout',
        'timed out',
        'connection refused',
        'connection reset',
        'network error',
        'broken pipe',
        'could not resolve',
        'cannot connect',
        'stream ended before',
        'read error',
    ];

    /**
     * Context-overflow indicator patterns found in provider error messages
     * when the request exceeds the model's context window.
     */
    private const array CONTEXT_OVERFLOW_PATTERNS = [
        'context length',
        'maximum context',
        'context window',
        'token limit',
        'too many tokens',
        'reduce the length',
        'input length',
        'context_length_exceeded',
        "this model's maximum context",
        'maximum context length',
        'max context length',
        'context token limit',
        'context size',
        'context size has been exceeded',
    ];

    /**
     * Classify an LLM provider error and return an enhanced error array.
     *
     * The input is the error array produced by {@see LlmPlatformAdapter::errorResult()}
     * which includes type, message, http_status_code, response_* diagnostics, and
     * request_* summary fields.
     *
     * @param array<string, mixed> $error Raw error array
     *
     * @return array<string, mixed> Enhanced error with retryable, error_category, user_message
     */
    public function classify(array $error): array
    {
        $errorType = (string) ($error['type'] ?? '');
        $errorMessage = (string) ($error['message'] ?? '');
        $statusCode = isset($error['http_status_code']) ? (int) $error['http_status_code'] : null;
        $responseErrorCode = $error['response_error_code'] ?? null;
        $responseErrorType = $error['response_error_type'] ?? null;
        $responseErrorMessage = $error['response_error_message'] ?? null;
        $retryAfterMs = $error['retry_after_ms'] ?? null;

        // Build a composite search text from all available structured fields.
        // This ensures billing/quota/quota codes in any field are caught.
        $allErrorText = implode(' ', array_filter([
            $errorMessage,
            \is_string($responseErrorMessage) ? $responseErrorMessage : '',
            \is_string($responseErrorCode) ? $responseErrorCode : '',
            \is_string($responseErrorType) ? $responseErrorType : '',
        ], static fn (string $v): bool => '' !== $v));

        // Priority-based classification using composite text and structured fields
        [$category, $retryable, $userMessage] = $this->classifyByExceptionType($errorType, $allErrorText, $statusCode)
            ?? $this->classifyByStatusCode($statusCode, $allErrorText, $responseErrorCode, $responseErrorType, $retryAfterMs)
            ?? $this->classifyByMessagePattern($allErrorText)
            ?? [self::CATEGORY_PROVIDER, false, \sprintf('LLM provider error: %s', self::truncate($errorMessage, 200))];

        $result = $error + [
            'retryable' => $retryable,
            'error_category' => $category,
            'user_message' => $userMessage,
        ];

        // Strip potentially sensitive fields — the user_message is the
        // sanitized diagnostic for display.  Raw response body previews
        // could contain prompts, tool output, or API keys.
        unset($result['response_body_preview']);

        return $result;
    }

    /**
     * Determine whether a classified error indicates a context-overflow condition
     * (the prompt exceeds the model's context window).
     *
     * Called after {@see classify()} so the error array includes the
     * classification fields (error_category, user_message, etc.).
     *
     * @param array<string, mixed> $classifiedError
     */
    public function isContextOverflow(array $classifiedError): bool
    {
        $category = $classifiedError['error_category'] ?? self::CATEGORY_UNKNOWN;

        // Context overflow typically surfaces as a bad-request (400)
        // or a server error (500) from the provider.  Auth, rate-limit,
        // quota/billing, timeout, and network errors are not overflow.
        if (!\in_array($category, [self::CATEGORY_BAD_REQUEST, self::CATEGORY_SERVER, self::CATEGORY_PROVIDER], true)) {
            return false;
        }

        // Search the raw message and any provider-supplied response text.
        $message = (string) ($classifiedError['message'] ?? '');
        $responseMessage = (string) ($classifiedError['response_error_message'] ?? '');
        $responseBody = (string) ($classifiedError['response_body_preview'] ?? '');
        $allText = implode(' ', array_filter([$message, $responseMessage, $responseBody], static fn (string $v): bool => '' !== $v));

        return self::matchesAny($allText, self::CONTEXT_OVERFLOW_PATTERNS);
    }

    /**
     * @return array{string, bool, string}|null
     */
    private function classifyByExceptionType(string $errorType, string $errorMessage, ?int $statusCode): ?array
    {
        // Authentication / auth errors — never retryable
        if (str_contains($errorType, 'AuthenticationException') || 401 === $statusCode) {
            $detail = self::truncate($errorMessage, 200);

            return [self::CATEGORY_AUTH, false, \sprintf('LLM provider authentication failed (HTTP 401). Check your API key or OAuth credentials. %s', '' !== $detail ? $detail : '')];
        }

        // Bad request errors — never retryable
        if (str_contains($errorType, 'BadRequestException') || 400 === $statusCode) {
            $detail = self::truncate($errorMessage, 200);

            return [self::CATEGORY_BAD_REQUEST, false, \sprintf('LLM provider rejected the request (HTTP 400): %s', $detail)];
        }

        // Rate limit exceptions from Symfony AI — retryable with Retry-After hint
        if (str_contains($errorType, 'RateLimitExceededException')) {
            $userMsg = 'LLM provider rate limit reached (retryable). Will retry automatically.';

            return [self::CATEGORY_RATE_LIMIT, true, $userMsg];
        }

        return null;
    }

    /**
     * @param string          $allErrorText Composite text from all error fields
     * @param int|string|null $retryAfterMs
     *
     * @return array{string, bool, string}|null
     */
    private function classifyByStatusCode(?int $statusCode, string $allErrorText, mixed $responseErrorCode, mixed $responseErrorType, mixed $retryAfterMs): ?array
    {
        if (null === $statusCode) {
            return null;
        }

        // Terminal billing/quota from error body patterns — check all available text
        if (429 === $statusCode && self::matchesAny($allErrorText, self::TERMINAL_BILLING_PATTERNS)) {
            return [self::CATEGORY_QUOTA_BILLING, false, 'LLM provider quota or billing limit reached. Try switching provider/model or updating your quota.'];
        }

        // Build a user message with safe structured details for transient rate limits.
        if (429 === $statusCode) {
            $parts = ['LLM provider rate limit reached (retryable). Will retry automatically.'];
            if (null !== $retryAfterMs && $retryAfterMs > 0) {
                $parts[] = \sprintf('Retry after up to %ds.', (int) ceil($retryAfterMs / 1000));
            }
            if (\is_string($responseErrorCode) && '' !== $responseErrorCode) {
                $parts[] = \sprintf('Provider code: %s.', $responseErrorCode);
            }

            return [self::CATEGORY_RATE_LIMIT, true, implode(' ', $parts)];
        }

        if (\in_array($statusCode, [500, 502, 503, 504], true) && self::matchesAny($allErrorText, self::CONTEXT_OVERFLOW_PATTERNS)) {
            $detail = self::truncate($allErrorText, 200);

            return [self::CATEGORY_BAD_REQUEST, false, \sprintf('LLM provider context limit exceeded (HTTP %d): %s', $statusCode, $detail)];
        }

        return match ($statusCode) {
            408, 425 => [self::CATEGORY_TIMEOUT, true, \sprintf('LLM provider request timed out (HTTP %d — retryable). Will retry automatically.', $statusCode)],
            500, 502, 503, 504 => [self::CATEGORY_SERVER, true, \sprintf('LLM provider server error (HTTP %d — retryable). Will retry automatically.', $statusCode)],
            default => null,
        };
    }

    /**
     * @return array{string, bool, string}|null
     */
    private function classifyByMessagePattern(string $errorMessage): ?array
    {
        if ('' === $errorMessage) {
            return null;
        }

        // Check transport/network errors before general billing
        if (self::matchesAny($errorMessage, self::TRANSPORT_ERROR_PATTERNS)) {
            return [self::CATEGORY_NETWORK, true, 'LLM provider network error (retryable). Check your connection and try again.'];
        }

        // Terminal billing/quota from message
        if (self::matchesAny($errorMessage, self::TERMINAL_BILLING_PATTERNS)) {
            return [self::CATEGORY_QUOTA_BILLING, false, 'LLM provider quota or billing limit reached. Try switching provider/model or updating your quota.'];
        }

        return null;
    }

    /**
     * Check if the haystack contains any of the given patterns (case-insensitive).
     *
     * @param string[] $patterns
     */
    private static function matchesAny(string $haystack, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (false !== stripos($haystack, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private static function truncate(string $value, int $maxLength): string
    {
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength).'...';
    }
}
