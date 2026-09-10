<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Amp\CancelledException;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\ContentFilterException;
use Symfony\AI\Platform\Exception\ExceedContextSizeException;
use Symfony\AI\Platform\Exception\InvalidArgumentException as AiInvalidArgumentException;
use Symfony\AI\Platform\Exception\InvalidRequestException;
use Symfony\AI\Platform\Exception\MaxOutputTokensException;
use Symfony\AI\Platform\Exception\MissingModelSupportException;
use Symfony\AI\Platform\Exception\ModelNotFoundException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\Exception\ValidationException;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class LlmProviderErrorClassifier
{
    public const string CATEGORY_AUTH = 'auth';
    public const string CATEGORY_BAD_REQUEST = 'bad_request';
    public const string CATEGORY_RATE_LIMIT = 'rate_limit';
    public const string CATEGORY_SERVER = 'server';
    public const string CATEGORY_TIMEOUT = 'timeout';
    public const string CATEGORY_NETWORK = 'network';
    public const string CATEGORY_PROVIDER = 'provider';
    public const string CATEGORY_UNKNOWN = 'unknown';

    private const array BAD_REQUEST_EXCEPTIONS = [
        BadRequestException::class,
        ContentFilterException::class,
        ExceedContextSizeException::class,
        AiInvalidArgumentException::class,
        InvalidRequestException::class,
        MaxOutputTokensException::class,
        MissingModelSupportException::class,
        ModelNotFoundException::class,
        ValidationException::class,
    ];

    /**
     * @param array<string, mixed> $error
     *
     * @return array<string, mixed>
     */
    public function classify(array $error): array
    {
        $errorType = \is_string($error['type'] ?? null) ? $error['type'] : '';
        $statusCode = \is_int($error['http_status_code'] ?? null) ? $error['http_status_code'] : null;
        $message = \is_string($error['message'] ?? null) ? $error['message'] : '';

        [$category, $retryable, $userMessage] = $this->classifyPermanentException($errorType)
            ?? $this->classifyStatus($statusCode)
            ?? $this->classifyTransientStreamException($errorType)
            ?? $this->classifyTransientMessage($message)
            ?? [self::CATEGORY_PROVIDER, true, 'LLM provider request failed.'];

        $result = array_replace($error, [
            'retryable' => $retryable,
            'error_category' => $category,
            'user_message' => $userMessage,
        ]);

        unset(
            $result['response_body_preview'],
            $result['response_error_message'],
            $result['previous_exception_class'],
            $result['previous_exception_message'],
        );

        return $result;
    }

    /** @return array{string, bool, string}|null */
    private function classifyPermanentException(string $type): ?array
    {
        if (is_a($type, CancelledException::class, true)) {
            return [self::CATEGORY_UNKNOWN, false, 'LLM request was cancelled.'];
        }

        if (is_a($type, \Error::class, true)
            || is_a($type, \LogicException::class, true)
            || is_a($type, \ErrorException::class, true)
            || is_a($type, \JsonException::class, true)
        ) {
            return [self::CATEGORY_UNKNOWN, false, 'LLM request failed before reaching a retryable provider condition.'];
        }

        if (is_a($type, AuthenticationException::class, true)) {
            return [self::CATEGORY_AUTH, true, 'LLM provider authentication failed. Retrying with current credentials.'];
        }

        foreach (self::BAD_REQUEST_EXCEPTIONS as $exceptionClass) {
            if (is_a($type, $exceptionClass, true)) {
                return [self::CATEGORY_BAD_REQUEST, true, 'LLM provider rejected the request.'];
            }
        }

        if (is_a($type, TimeoutExceptionInterface::class, true)) {
            return [self::CATEGORY_TIMEOUT, true, 'LLM provider request timed out.'];
        }

        if (is_a($type, TransportExceptionInterface::class, true)) {
            return [self::CATEGORY_NETWORK, true, 'LLM provider transport failed.'];
        }

        return null;
    }

    /** @return array{string, bool, string}|null */
    private function classifyStatus(?int $statusCode): ?array
    {
        return match ($statusCode) {
            400, 404, 405, 413, 415, 422, 501 => [self::CATEGORY_BAD_REQUEST, true, 'LLM provider rejected the request.'],
            401, 403 => [self::CATEGORY_AUTH, true, 'LLM provider authentication or authorization failed.'],
            408, 425 => [self::CATEGORY_TIMEOUT, true, 'LLM provider request timed out.'],
            429 => [self::CATEGORY_RATE_LIMIT, true, 'LLM provider rate limit interrupted the request.'],
            500, 502, 503, 504 => [self::CATEGORY_SERVER, true, 'LLM provider server error interrupted the request.'],
            default => null,
        };
    }

    /** @return array{string, bool, string}|null */
    private function classifyTransientStreamException(string $type): ?array
    {
        if (is_a($type, RateLimitExceededException::class, true)) {
            return [self::CATEGORY_RATE_LIMIT, true, 'LLM provider rate limit interrupted the response stream.'];
        }

        if (is_a($type, ServerException::class, true)) {
            return [self::CATEGORY_SERVER, true, 'LLM provider server error interrupted the response stream.'];
        }

        return null;
    }

    /** @return array{string, bool, string}|null */
    private function classifyTransientMessage(string $message): ?array
    {
        $normalized = strtolower($message);
        if ('' === $normalized) {
            return null;
        }

        if (str_contains($normalized, 'idle timeout')
            || str_contains($normalized, 'timed out')
            || str_contains($normalized, 'timeout')
            || str_contains($normalized, 'buffer timeout')
        ) {
            return [self::CATEGORY_TIMEOUT, true, 'LLM provider request timed out.'];
        }

        if (str_contains($normalized, 'connection closed')
            || str_contains($normalized, 'connection reset')
            || str_contains($normalized, 'broken pipe')
            || str_contains($normalized, 'network')
        ) {
            return [self::CATEGORY_NETWORK, true, 'LLM provider transport failed.'];
        }

        return null;
    }
}
