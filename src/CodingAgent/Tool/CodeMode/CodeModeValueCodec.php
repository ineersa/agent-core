<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

/** Closed IPC data; this file is also loaded by the isolated child bootstrap. */
final class CodeModeValueCodec
{
    // Same root-zero, key-and-leaf-inclusive budget as ToolResultText. This
    // standalone bootstrap file deliberately has no AgentCore dependency.
    public const int MAX_DATA_DEPTH = 64;

    public const string MALFORMED_MESSAGE = 'Tool call failed: its result contained malformed UTF-8.';

    public static function assertEncodable(mixed $value, string $context): mixed
    {
        // Inspect shape before any encoding. In particular, never run an
        // unsupported object's jsonSerialize(), property hooks or __toString().
        return self::toData($value, $context, 0);
    }

    private static function toData(mixed $value, string $context, int $depth): mixed
    {
        if ($depth > self::MAX_DATA_DEPTH) {
            throw new \RuntimeException('Tool call failed: its result could not be safely inspected.');
        }
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        } elseif ($value instanceof \UnitEnum) {
            $value = $value->name;
        }
        if (\is_string($value)) {
            if (!mb_check_encoding($value, 'UTF-8')) {
                throw new \RuntimeException(self::MALFORMED_MESSAGE);
            }

            return $value;
        }
        if (null === $value || \is_bool($value) || \is_int($value)) {
            return $value;
        }
        if (\is_float($value) && is_finite($value)) {
            return $value;
        }
        if (!\is_array($value)) {
            throw new \RuntimeException($context.' contains an unsupported IPC value.');
        }
        $data = [];
        foreach ($value as $key => $item) {
            $dataKey = self::toData($key, $context, $depth + 1);
            $data[$dataKey] = self::toData($item, $context, $depth + 1);
        }

        return $data;
    }
}
