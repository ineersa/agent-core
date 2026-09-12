<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

/**
 * Reject values that JSON framing would silently lose or refuse to encode.
 *
 * Throws RuntimeException so the child bootstrap can reuse this file without
 * loading AgentCore. The host wraps those exceptions as ToolCallException.
 *
 * @internal
 */
final class CodeModeValueCodec
{
    /**
     * @throws \RuntimeException when the value cannot round-trip through JSON framing
     */
    public static function assertEncodable(mixed $value, string $context): mixed
    {
        self::assertEncodableRecursive($value, $context, []);

        try {
            json_encode($value, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                \sprintf('%s cannot be encoded for code_mode IPC: %s', $context, $exception->getMessage()),
                0,
                $exception,
            );
        }

        return $value;
    }

    /**
     * @param list<object> $stack
     */
    private static function assertEncodableRecursive(mixed $value, string $context, array $stack): void
    {
        if (\is_resource($value)) {
            throw new \RuntimeException(\sprintf('%s contains a resource, which cannot be returned through code_mode.', $context));
        }

        if ($value instanceof \Closure) {
            throw new \RuntimeException(\sprintf('%s contains a Closure, which cannot be returned through code_mode.', $context));
        }

        if (\is_float($value) && !is_finite($value)) {
            throw new \RuntimeException(\sprintf('%s contains a non-finite float, which cannot be returned through code_mode.', $context));
        }

        if (\is_object($value)) {
            foreach ($stack as $seen) {
                if ($seen === $value) {
                    throw new \RuntimeException(\sprintf('%s contains a cyclic object graph, which cannot be returned through code_mode.', $context));
                }
            }

            if ($value instanceof \JsonSerializable) {
                self::assertEncodableRecursive($value->jsonSerialize(), $context, [...$stack, $value]);

                return;
            }

            if ($value instanceof \UnitEnum) {
                return;
            }

            if ($value instanceof \DateTimeInterface) {
                // DateTime encodes as a property map under json_encode; keep that shape.
                return;
            }

            try {
                $vars = get_object_vars($value);
            } catch (\Error $exception) {
                throw new \RuntimeException(
                    \sprintf('%s contains an unsupported object of type %s.', $context, $value::class),
                    0,
                    $exception,
                );
            }

            self::assertEncodableRecursive($vars, $context, [...$stack, $value]);

            return;
        }

        if (\is_array($value)) {
            foreach ($value as $item) {
                self::assertEncodableRecursive($item, $context, $stack);
            }
        }
    }
}
