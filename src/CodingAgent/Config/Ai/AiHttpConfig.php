<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\Ai;

/**
 * HTTP retry and timeout configuration for LLM provider requests.
 *
 * Sourced from the `ai.http` block in Hatfield settings YAML.
 * Each value supports a plain integer, a numeric string, or the
 * `env:VARNAME` syntax to reference an environment variable.
 *
 * When a value is null, the consumer (e.g. {@see LlmHttpRetryPolicy})
 * applies its own default. This config object only resolves explicit
 * values; defaults are not embedded here.
 *
 * @see \Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http\LlmHttpRetryPolicy Default values
 *
 * @param int|null $timeout     Per-request timeout in seconds
 * @param int|null $maxDuration Total request duration budget in seconds
 * @param int|null $maxRetries  Max retry attempts (0 = no retries)
 * @param int|null $baseDelayMs Base retry backoff delay in milliseconds
 * @param int|null $maxDelayMs  Maximum delay for any single retry in ms
 */
final readonly class AiHttpConfig
{
    public function __construct(
        public ?int $timeout = null,
        public ?int $maxDuration = null,
        public ?int $maxRetries = null,
        public ?int $baseDelayMs = null,
        public ?int $maxDelayMs = null,
    ) {
    }

    /**
     * Parse the `ai.http` subsection from merged Hatfield settings.
     *
     * Each value accepts:
     *   - `null` → property becomes null (consumer applies default)
     *   - `int` → property is set to that int
     *   - `env:VARNAME` → resolved via getenv(); unset/empty → null; else cast to int
     *   - plain numeric string (e.g. `'45'`) → cast to int
     *
     * @param array<string, mixed> $data The ai.http array from settings YAML
     *
     * @return self All properties null when the corresponding key is absent
     *
     * @throws \InvalidArgumentException on non-numeric, non-env, non-int values
     */
    public static function fromArray(array $data): self
    {
        return new self(
            timeout: self::resolveIntValue($data['timeout'] ?? null, 'timeout'),
            maxDuration: self::resolveIntValue($data['max_duration'] ?? null, 'max_duration'),
            maxRetries: self::resolveIntValue($data['max_retries'] ?? null, 'max_retries'),
            baseDelayMs: self::resolveIntValue($data['base_delay_ms'] ?? null, 'base_delay_ms'),
            maxDelayMs: self::resolveIntValue($data['max_delay_ms'] ?? null, 'max_delay_ms'),
        );
    }

    /**
     * Resolve a single config value to ?int.
     *
     * @param mixed  $value The raw config value (null, int, or string)
     * @param string $key   YAML key name for error messages
     *
     * @return int|null Resolved integer, or null when unspecified
     *
     * @throws \InvalidArgumentException on invalid types or unresolvable values
     */
    private static function resolveIntValue(mixed $value, string $key): ?int
    {
        if (null === $value) {
            return null;
        }

        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value)) {
            // env:VARNAME — resolve from environment variable
            if (str_starts_with($value, 'env:')) {
                $varName = substr($value, 4);
                if ('' === $varName) {
                    throw new \InvalidArgumentException(\sprintf('The env: syntax in ai.http.%s must specify a variable name, got bare "env:".', $key));
                }

                $envValue = getenv($varName);
                if (false === $envValue || '' === $envValue) {
                    return null; // unset or empty → null
                }

                if (!is_numeric($envValue)) {
                    throw new \InvalidArgumentException(\sprintf('Environment variable "%s" (used in ai.http.%s) must be a numeric value, got "%s".', $varName, $key, $envValue));
                }

                return (int) $envValue;
            }

            // Plain numeric string (e.g. '45')
            if (is_numeric($value)) {
                return (int) $value;
            }

            // Non-numeric, non-env string
            throw new \InvalidArgumentException(\sprintf('Invalid value for ai.http.%s: expected a numeric value or env:VARNAME reference, got "%s".', $key, $value));
        }

        // boolean, array, object, float, etc.
        throw new \InvalidArgumentException(\sprintf('Invalid type for ai.http.%s: expected int, string, or null, got %s.', $key, get_debug_type($value)));
    }
}
