<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Model;

/**
 * A reference to a specific model by provider and model name.
 *
 * Format: provider/model — parsed from a single string (e.g. deepseek/deepseek-v4-pro)
 * and rendered back to the same string form.
 *
 * Canonical public type shared by Hatfield config/runtime and ExtensionApi::callModel().
 */
final readonly class AiModelReference
{
    public function __construct(
        public string $providerId,
        public string $modelName,
    ) {
    }

    /**
     * Parse a model reference string like "deepseek/deepseek-v4-pro".
     *
     * @throws \InvalidArgumentException if the string is malformed
     */
    public static function parse(string $ref): self
    {
        $parts = explode('/', $ref, 2);

        if (2 !== \count($parts) || '' === $parts[0] || '' === $parts[1]) {
            throw new \InvalidArgumentException(\sprintf('Invalid model reference "%s". Expected format: provider/model (e.g. deepseek/deepseek-v4-pro).', $ref));
        }

        return new self(
            providerId: $parts[0],
            modelName: $parts[1],
        );
    }

    /**
     * Parse a model reference string, returning null on failure instead of throwing.
     */
    public static function tryParse(string $ref): ?self
    {
        $parts = explode('/', $ref, 2);

        if (2 !== \count($parts) || '' === $parts[0] || '' === $parts[1]) {
            return null;
        }

        return new self(
            providerId: $parts[0],
            modelName: $parts[1],
        );
    }

    public function toString(): string
    {
        return $this->providerId.'/'.$this->modelName;
    }
}
