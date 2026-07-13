<?php

declare(strict_types=1);

namespace Ineersa\Platform\Bridge\Generic;

/**
 * Hatfield-internal Symfony AI invocation option keys that must not reach
 * generic OpenAI-compatible provider wire bodies.
 *
 * Generic vendor Completions ModelClient merges all options into the JSON
 * request. Volatile execution metadata such as per-session run_id would change
 * llama-proxy cache keys and can cause provider 400 responses for unknown fields.
 *
 * Provider-specific bridges consume these keys in their own request mappers
 * before wire serialization.
 */
final class GenericProviderInternalOptionKeys
{
    /**
     * @var list<string>
     */
    public const array ALL = [
        'run_id',
        'provider_cache_key',
        'tools_ref',
        'turn_no',
    ];

    private function __construct()
    {
    }
}
