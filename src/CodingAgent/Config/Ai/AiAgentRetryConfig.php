<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\Ai;

/** Agent-level retry count for typed mid-stream provider failures. */
final readonly class AiAgentRetryConfig
{
    public function __construct(public ?int $maxAttempts = null)
    {
        if (null !== $maxAttempts && $maxAttempts < 0) {
            throw new \InvalidArgumentException('ai.agent_retry.max_attempts must be non-negative.');
        }
    }

    public function resolveMaxAttempts(): int
    {
        return $this->maxAttempts ?? 2;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(self::resolveIntValue($data['max_attempts'] ?? null));
    }

    private static function resolveIntValue(mixed $value): ?int
    {
        if (null === $value || \is_int($value)) {
            return $value;
        }

        if (!\is_string($value)) {
            throw new \InvalidArgumentException(\sprintf('Invalid type for ai.agent_retry.max_attempts: expected int, string, or null, got %s.', get_debug_type($value)));
        }

        if (str_starts_with($value, 'env:')) {
            $variable = substr($value, 4);
            if ('' === $variable) {
                throw new \InvalidArgumentException('The env: syntax in ai.agent_retry.max_attempts must specify a variable name.');
            }

            $value = getenv($variable);
            if (false === $value || '' === $value) {
                return null;
            }
        }

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(\sprintf('Invalid value for ai.agent_retry.max_attempts: expected a numeric value, got "%s".', $value));
        }

        return (int) $value;
    }
}
