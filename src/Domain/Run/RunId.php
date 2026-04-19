<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

use Random\RandomException;

/**
 * A value object representing a unique identifier for a run within the agent core domain. It encapsulates a string value to ensure type safety and immutability for run identification. This class supports string conversion and programmatic generation of new identifiers.
 */
final readonly class RunId
{
    /**
     * Initializes the run identifier with a given string value.
     */
    public function __construct(public string $value)
    {
    }

    /**
     * Returns the string representation of the run identifier.
     */
    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Creates and returns a new RunId instance with a generated unique value.
     */
    public static function generate(): self
    {
        try {
            $bytes = random_bytes(16);
            $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
            $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

            $hex = bin2hex($bytes);

            return new self(\sprintf(
                '%s-%s-%s-%s-%s',
                substr($hex, 0, 8),
                substr($hex, 8, 4),
                substr($hex, 12, 4),
                substr($hex, 16, 4),
                substr($hex, 20, 12),
            ));
        } catch (RandomException) {
            $fallback = str_replace('.', '', uniqid('', true));

            return new self(\sprintf(
                '%s-%s-%s-%s-%s',
                substr($fallback, 0, 8),
                substr($fallback, 8, 4),
                '4'.substr($fallback, 13, 3),
                'a'.substr($fallback, 16, 3),
                substr($fallback, 19, 12),
            ));
        }
    }
}
