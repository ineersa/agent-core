<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\Ai;

/**
 * Token pricing metadata for a model.
 *
 * All values in USD per 1M tokens (matching the OpenAI-style convention).
 * Zero means free; null means unknown/not tracked.
 */
final readonly class AiCost
{
    public function __construct(
        public float $input = 0.0,
        public float $output = 0.0,
        public float $cacheRead = 0.0,
        public float $cacheWrite = 0.0,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            input: (float) ($data['input'] ?? 0.0),
            output: (float) ($data['output'] ?? 0.0),
            cacheRead: (float) ($data['cache_read'] ?? 0.0),
            cacheWrite: (float) ($data['cache_write'] ?? 0.0),
        );
    }
}
