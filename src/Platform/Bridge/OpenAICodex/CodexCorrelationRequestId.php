<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Resolves Codex request correlation IDs shared across handshake headers and prompt_cache_key.
 *
 * Codex model routing for some models (e.g. gpt-5.6-luna over WebSocket) rejects RFC 4122 UUID
 * version 4 values on session-id / x-client-request-id / fallback prompt_cache_key and surfaces
 * invalid_request_error/model. Pi uses time-ordered UUID version 7 session IDs; Hatfield must match.
 *
 * Precedence: non-empty options['provider_cache_key'] (persisted Hatfield session UUIDv7), then
 * explicit payload prompt_cache_key, then non-empty options['run_id'] for non-persisted child runs,
 * then a generated UUIDv7. Numeric Hatfield session ids must not be used when provider_cache_key is
 * supplied via model resolution.
 *
 * Bounded 401 retry preserves explicit provider_cache_key, explicit prompt_cache_key, and explicit
 * run_id; only generated IDs rotate.
 */
final class CodexCorrelationRequestId
{
    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $payload
     */
    public static function resolve(array $options, array $payload): CodexCorrelationResolution
    {
        $providerCacheKey = $options['provider_cache_key'] ?? null;
        if (\is_string($providerCacheKey) && '' !== $providerCacheKey) {
            return new CodexCorrelationResolution($providerCacheKey, $options, CodexCorrelationProvenance::ExplicitProviderCacheKey);
        }

        $explicitCacheKey = $payload['prompt_cache_key'] ?? null;
        if (\is_string($explicitCacheKey) && '' !== $explicitCacheKey) {
            return new CodexCorrelationResolution($explicitCacheKey, $options, CodexCorrelationProvenance::ExplicitPromptCacheKey);
        }

        $explicitRunId = $options['run_id'] ?? null;
        if (\is_string($explicitRunId) && '' !== $explicitRunId) {
            return new CodexCorrelationResolution($explicitRunId, $options, CodexCorrelationProvenance::ExplicitRunId);
        }

        $generated = UuidV7::v7()->toRfc4122();
        $options['run_id'] = $generated;

        return new CodexCorrelationResolution($generated, $options, CodexCorrelationProvenance::Generated);
    }
}
