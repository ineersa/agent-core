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
        'usage_limit_reached',
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
        // Session 37: cached Codex WebSocket still looked open, send failed with
        // Amp\Websocket\WebsocketClosedException; wrapper message + previous class must retry.
        'request frame could not be sent',
        'websocketclosed',
    ];

    private const array NON_RETRYABLE_EXCEPTION_CATEGORIES = [
        'AuthenticationException' => self::CATEGORY_AUTH,
        'AuthorizationException' => self::CATEGORY_AUTH,
        'BadRequestException' => self::CATEGORY_BAD_REQUEST,
        'ContentFilterException' => self::CATEGORY_BAD_REQUEST,
        'ExceedContextSizeException' => self::CATEGORY_BAD_REQUEST,
        'InvalidArgumentException' => self::CATEGORY_BAD_REQUEST,
        'InvalidRequestException' => self::CATEGORY_BAD_REQUEST,
        'MaxOutputTokensException' => self::CATEGORY_BAD_REQUEST,
        'MissingModelSupportException' => self::CATEGORY_BAD_REQUEST,
        'ModelNotFoundException' => self::CATEGORY_BAD_REQUEST,
        'ValidationException' => self::CATEGORY_BAD_REQUEST,
    ];

    private const array CANCELLATION_EXCEPTION_TYPES = [
        'CancelledException',
        'CancellationException',
        'CanceledException',
    ];

    private const array NON_RETRYABLE_STRUCTURED_SIGNAL_CATEGORIES = [
        'authentication_error' => self::CATEGORY_AUTH,
        'invalid_api_key' => self::CATEGORY_AUTH,
        'permission_denied' => self::CATEGORY_AUTH,
        'unauthorized' => self::CATEGORY_AUTH,
        'bad_request' => self::CATEGORY_BAD_REQUEST,
        'content_filter' => self::CATEGORY_BAD_REQUEST,
        'content_policy_violation' => self::CATEGORY_BAD_REQUEST,
        'invalid_argument' => self::CATEGORY_BAD_REQUEST,
        'invalid_parameter' => self::CATEGORY_BAD_REQUEST,
        'invalid_request_error' => self::CATEGORY_BAD_REQUEST,
        'model_not_found' => self::CATEGORY_BAD_REQUEST,
        'policy_violation' => self::CATEGORY_BAD_REQUEST,
        'safety_violation' => self::CATEGORY_BAD_REQUEST,
        'unsupported_feature' => self::CATEGORY_BAD_REQUEST,
        'unsupported_model' => self::CATEGORY_BAD_REQUEST,
        'validation_error' => self::CATEGORY_BAD_REQUEST,
    ];

    /**
     * Exact structured provider signals retained for server categorization.
     *
     * Retry eligibility no longer depends on this list: unknown provider-operation
     * failures use the bounded default-retry policy. These tokens only produce the
     * more specific server category and safe user message.
     */
    private const array TRANSIENT_SERVER_STRUCTURED_SIGNALS = [
        'server_is_overloaded',
        'service_unavailable_error',
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
        $previousExceptionClass = $error['previous_exception_class'] ?? null;
        $previousExceptionMessage = $error['previous_exception_message'] ?? null;

        $allErrorText = implode(' ', array_filter([
            $errorMessage,
            \is_string($responseErrorMessage) ? $responseErrorMessage : '',
            \is_string($responseErrorCode) ? $responseErrorCode : '',
            \is_string($responseErrorType) ? $responseErrorType : '',
            \is_string($previousExceptionClass) ? $previousExceptionClass : '',
            \is_string($previousExceptionMessage) ? $previousExceptionMessage : '',
        ], static fn (string $v): bool => '' !== $v));

        // Priority-based classification using composite text and structured fields.
        // Known permanent conditions are denied before transport/default handling.
        // Only errors reaching this classifier from the provider-operation boundary
        // receive the bounded default-retry fallback.
        [$category, $retryable, $userMessage] = $this->classifyByExceptionType($errorType, $allErrorText, $statusCode)
            ?? $this->classifyByStatusCode($statusCode, $allErrorText, $responseErrorCode, $responseErrorType, $retryAfterMs)
            ?? $this->classifyByMessagePattern($allErrorText)
            ?? $this->classifyByStructuredSignal($responseErrorCode, $responseErrorType, $errorMessage)
            ?? $this->classifyByTransportPattern($allErrorText)
            ?? [
                self::CATEGORY_PROVIDER,
                true,
                self::defaultRetryMessage($errorMessage),
            ];

        $result = $error + [
            'retryable' => $retryable,
            'error_category' => $category,
            'user_message' => $userMessage,
        ];

        // Strip classifier-only / potentially sensitive fields. user_message is the
        // sanitized diagnostic for display. Previous-exception fields are classification
        // inputs only and must not survive into persisted LlmStepFailed payloads.
        unset(
            $result['response_body_preview'],
            $result['previous_exception_class'],
            $result['previous_exception_message'],
        );

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
    private function classifyByExceptionType(string $errorType, string $allErrorText, ?int $statusCode): ?array
    {
        $shortType = self::shortExceptionType($errorType);

        $nonRetryableCategory = self::NON_RETRYABLE_EXCEPTION_CATEGORIES[$shortType] ?? null;
        if (self::CATEGORY_AUTH === $nonRetryableCategory || 401 === $statusCode) {
            $detail = self::truncate($allErrorText, 200);

            return [self::CATEGORY_AUTH, false, \sprintf('LLM provider authentication failed. Check your API key or OAuth credentials.%s', '' !== $detail ? ' '.$detail : '')];
        }

        if (self::CATEGORY_BAD_REQUEST === $nonRetryableCategory || 400 === $statusCode) {
            return [self::CATEGORY_BAD_REQUEST, false, \sprintf('LLM provider rejected the request: %s', self::truncate($allErrorText, 200))];
        }

        // Let structured permanent HTTP failures outrank transient wrapper types.
        if (\in_array($statusCode, [402, 403, 404, 405, 413, 415, 422, 501], true)
            || (429 === $statusCode && self::matchesAny($allErrorText, self::TERMINAL_BILLING_PATTERNS))
            || (\in_array($statusCode, [500, 502, 503, 504], true) && self::matchesAny($allErrorText, self::CONTEXT_OVERFLOW_PATTERNS))
        ) {
            return null;
        }

        if ('TimeoutException' === $shortType
            || (\in_array($shortType, self::CANCELLATION_EXCEPTION_TYPES, true) && str_contains($allErrorText, 'TimeoutException'))
        ) {
            return [self::CATEGORY_TIMEOUT, true, 'LLM provider request timed out (retryable). Will retry automatically.'];
        }

        if (\in_array($shortType, self::CANCELLATION_EXCEPTION_TYPES, true)) {
            return [self::CATEGORY_UNKNOWN, false, 'LLM request was cancelled.'];
        }

        if (is_a($errorType, \Error::class, true)
            || is_a($errorType, \LogicException::class, true)
            || is_a($errorType, \ErrorException::class, true)
            || is_a($errorType, \JsonException::class, true)
        ) {
            return [self::CATEGORY_UNKNOWN, false, 'LLM request failed before reaching a retryable provider condition.'];
        }

        return 'RateLimitExceededException' === $shortType
            ? [self::CATEGORY_RATE_LIMIT, true, 'LLM provider rate limit reached (retryable). Will retry automatically.']
            : null;
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

        if (\in_array($statusCode, [401, 403], true)) {
            return [self::CATEGORY_AUTH, false, \sprintf('LLM provider authorization failed (HTTP %d). Check your credentials and permissions.', $statusCode)];
        }

        if (402 === $statusCode) {
            return [self::CATEGORY_QUOTA_BILLING, false, 'LLM provider quota or billing limit reached. Try switching provider/model or updating your quota.'];
        }

        if (\in_array($statusCode, [400, 404, 405, 413, 415, 422, 501], true)) {
            return [self::CATEGORY_BAD_REQUEST, false, \sprintf('LLM provider rejected or does not support the request (HTTP %d).', $statusCode)];
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

        if (self::matchesAny($errorMessage, self::TERMINAL_BILLING_PATTERNS)) {
            return [self::CATEGORY_QUOTA_BILLING, false, 'LLM provider quota or billing limit reached. Try switching provider/model or updating your quota.'];
        }

        if (self::matchesAny($errorMessage, self::CONTEXT_OVERFLOW_PATTERNS)) {
            return [self::CATEGORY_BAD_REQUEST, false, \sprintf('LLM provider context limit exceeded: %s', self::truncate($errorMessage, 200))];
        }

        return null;
    }

    /**
     * @return array{string, bool, string}|null
     */
    private function classifyByStructuredSignal(mixed $responseErrorCode, mixed $responseErrorType, string $errorMessage): ?array
    {
        $serverFailure = false;
        foreach (self::structuredSignals($responseErrorCode, $responseErrorType, $errorMessage) as $signal) {
            $category = self::NON_RETRYABLE_STRUCTURED_SIGNAL_CATEGORIES[$signal] ?? null;
            if (self::CATEGORY_AUTH === $category) {
                return [self::CATEGORY_AUTH, false, 'LLM provider rejected the request credentials or permissions.'];
            }
            if (self::CATEGORY_BAD_REQUEST === $category) {
                return [self::CATEGORY_BAD_REQUEST, false, 'LLM provider rejected or does not support the request.'];
            }
            $serverFailure = $serverFailure || \in_array($signal, self::TRANSIENT_SERVER_STRUCTURED_SIGNALS, true);
        }

        return $serverFailure
            ? [self::CATEGORY_SERVER, true, 'LLM provider server temporarily unavailable (retryable). Will retry automatically.']
            : null;
    }

    /**
     * @return array{string, bool, string}|null
     */
    private function classifyByTransportPattern(string $errorMessage): ?array
    {
        return self::matchesAny($errorMessage, self::TRANSPORT_ERROR_PATTERNS)
            ? [self::CATEGORY_NETWORK, true, 'LLM provider network error (retryable). Check your connection and try again.']
            : null;
    }

    /**
     * @return list<string>
     */
    private static function structuredSignals(mixed $responseErrorCode, mixed $responseErrorType, string $errorMessage): array
    {
        $signals = [];
        foreach ([$responseErrorCode, $responseErrorType] as $value) {
            if (\is_string($value) && '' !== $value) {
                $signals[] = strtolower($value);
            }
        }

        // Bounded stream form from ResultConverter::generateErrorMessage(): [code/type/param]
        if (1 === preg_match('/\[([^]]+)]/', $errorMessage, $matches)) {
            foreach (explode('/', $matches[1]) as $segment) {
                $segment = strtolower(trim($segment));
                if ('' !== $segment) {
                    $signals[] = $segment;
                }
            }
        }

        return $signals;
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

    private static function shortExceptionType(string $errorType): string
    {
        $separator = strrpos($errorType, '\\');

        return false === $separator ? $errorType : substr($errorType, $separator + 1);
    }

    private static function defaultRetryMessage(string $errorMessage): string
    {
        $detail = self::truncate($errorMessage, 200);

        return 'LLM provider error (retryable).'
            .('' !== $detail ? ' '.$detail : '')
            .' Will retry automatically.';
    }

    private static function truncate(string $value, int $maxLength): string
    {
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength).'...';
    }
}
