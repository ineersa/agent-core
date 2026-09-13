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
    private const int MAX_DEPTH = 512;

    /**
     * @throws \RuntimeException when the value cannot round-trip through JSON framing
     */
    public static function assertEncodable(mixed $value, string $context): mixed
    {
        // json_encode detects cyclic arrays before our recursive walk can hang.
        // Do not substitute invalid UTF-8; silent corruption violates lossless IPC.
        try {
            json_encode($value, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                \sprintf('%s cannot be encoded for code_mode IPC: %s', $context, $exception->getMessage()),
                0,
                $exception,
            );
        }

        self::assertCompatibleShape($value, $context, 0, []);

        return $value;
    }

    /**
     * @param array<int, array<mixed>|object> $stack
     */
    private static function assertCompatibleShape(mixed $value, string $context, int $depth, array $stack): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \RuntimeException(\sprintf('%s exceeds the maximum nesting depth of %d for code_mode IPC.', $context, self::MAX_DEPTH));
        }

        if (null === $value || \is_bool($value) || \is_int($value) || \is_string($value)) {
            return;
        }

        if (\is_float($value)) {
            if (!is_finite($value)) {
                throw new \RuntimeException(\sprintf('%s contains a non-finite float, which cannot be returned through code_mode.', $context));
            }

            return;
        }

        if (\is_resource($value)) {
            throw new \RuntimeException(\sprintf('%s contains a resource, which cannot be returned through code_mode.', $context));
        }

        if ($value instanceof \Closure) {
            throw new \RuntimeException(\sprintf('%s contains a Closure, which cannot be returned through code_mode.', $context));
        }

        if (\is_object($value)) {
            foreach ($stack as $seen) {
                if ($seen === $value) {
                    throw new \RuntimeException(\sprintf('%s contains a cyclic object graph, which cannot be returned through code_mode.', $context));
                }
            }

            if ($value instanceof \UnitEnum) {
                return;
            }

            // Reject arbitrary objects rather than exposing public properties and
            // silently dropping private state via get_object_vars()/json_encode.
            // JsonSerializable is also rejected so we never invoke jsonSerialize()
            // twice (once here and once inside json_encode).
            throw new \RuntimeException(
                \sprintf('%s contains an unsupported object of type %s.', $context, $value::class),
            );
        }

        if (\is_array($value)) {
            foreach ($stack as $seen) {
                if ($seen === $value) {
                    throw new \RuntimeException(\sprintf('%s contains a cyclic array graph, which cannot be returned through code_mode.', $context));
                }
            }

            $nextStack = [...$stack, $value];
            foreach ($value as $item) {
                self::assertCompatibleShape($item, $context, $depth + 1, $nextStack);
            }

            return;
        }

        throw new \RuntimeException(\sprintf('%s contains an unsupported value of type %s.', $context, get_debug_type($value)));
    }
}
