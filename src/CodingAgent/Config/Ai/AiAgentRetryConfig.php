<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\Ai;

/**
 * Agent-level LLM step retry configuration (post-classification auto-retry).
 *
 * Sourced from the `ai.agent_retry` block in Hatfield settings YAML.
 */
final readonly class AiAgentRetryConfig
{
    public function __construct(
        public ?int $maxAttempts = null,
        public ?int $baseDelayMs = null,
        public ?int $maxDelayMs = null,
    ) {
    }

    public function resolveMaxAttempts(): int
    {
        return $this->maxAttempts ?? 2;
    }

    public function resolveBaseDelayMs(): int
    {
        return $this->baseDelayMs ?? 1000;
    }

    public function resolveMaxDelayMs(): int
    {
        return $this->maxDelayMs ?? 60000;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            maxAttempts: self::resolveIntValue($data['max_attempts'] ?? null, 'max_attempts'),
            baseDelayMs: self::resolveIntValue($data['base_delay_ms'] ?? null, 'base_delay_ms'),
            maxDelayMs: self::resolveIntValue($data['max_delay_ms'] ?? null, 'max_delay_ms'),
        );
    }

    private static function resolveIntValue(mixed $value, string $key): ?int
    {
        if (null === $value) {
            return null;
        }

        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value)) {
            if (str_starts_with($value, 'env:')) {
                $varName = substr($value, 4);
                if ('' === $varName) {
                    throw new \InvalidArgumentException(\sprintf('The env: syntax in ai.agent_retry.%s must specify a variable name, got bare "env:".', $key));
                }

                $envValue = getenv($varName);
                if (false === $envValue || '' === $envValue) {
                    return null;
                }

                if (!is_numeric($envValue)) {
                    throw new \InvalidArgumentException(\sprintf('Environment variable "%s" (used in ai.agent_retry.%s) must be a numeric value, got "%s".', $varName, $key, $envValue));
                }

                return (int) $envValue;
            }

            if (is_numeric($value)) {
                return (int) $value;
            }

            throw new \InvalidArgumentException(\sprintf('Invalid value for ai.agent_retry.%s: expected a numeric value or env:VARNAME reference, got "%s".', $key, $value));
        }

        throw new \InvalidArgumentException(\sprintf('Invalid type for ai.agent_retry.%s: expected int, string, or null, got %s.', $key, get_debug_type($value)));
    }
}
