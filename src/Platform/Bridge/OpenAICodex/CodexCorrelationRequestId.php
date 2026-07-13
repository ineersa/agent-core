<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Symfony\Component\Uid\UuidV7;

/**
 * Resolves Codex request correlation IDs shared across handshake headers and prompt_cache_key.
 *
 * Codex model routing for some models (e.g. gpt-5.6-luna over WebSocket) rejects RFC 4122 UUID
 * version 4 values on session-id / x-client-request-id / fallback prompt_cache_key and surfaces
 * invalid_request_error/model. Pi uses time-ordered UUID version 7 session IDs; Hatfield must match.
 *
 * Explicit caller identifiers are preserved as-is: when resolve() returns an explicit
 * options['run_id'] or payload prompt_cache_key, that value is used for headers
 * and body correlation without rewriting. The caller is responsible for satisfying the backend's
 * ID contract for those values.
 */
final class CodexCorrelationRequestId
{
    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $payload
     *
     * @return array{0: string, 1: array<string, mixed>} [correlationId, options possibly augmented with run_id]
     */
    public static function resolve(array $options, array $payload): array
    {
        $explicitRunId = $options['run_id'] ?? null;
        if (\is_string($explicitRunId) && '' !== $explicitRunId) {
            return [$explicitRunId, $options];
        }

        $explicitCacheKey = $payload['prompt_cache_key'] ?? null;
        if (\is_string($explicitCacheKey) && '' !== $explicitCacheKey) {
            return [$explicitCacheKey, $options];
        }

        $generated = UuidV7::v7()->toRfc4122();
        $options['run_id'] = $generated;

        return [$generated, $options];
    }

    public static function generate(): string
    {
        return UuidV7::v7()->toRfc4122();
    }
}
