<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

/**
 * Deterministic structural comparison for Codex continuation eligibility.
 */
final class CodexWebSocketContinuationComparator
{
    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function requestBodiesMatchExceptInput(array $a, array $b): bool
    {
        return self::encode(self::bodyWithoutContinuationFields($a))
            === self::encode(self::bodyWithoutContinuationFields($b));
    }

    /**
     * @param list<mixed> $a
     * @param list<mixed> $b
     */
    public static function responseInputsEqual(array $a, array $b): bool
    {
        return self::encode(array_map(self::comparableInput(...), $a))
            === self::encode(array_map(self::comparableInput(...), $b));
    }

    /**
     * Compare provider output with the history emitted by CodexContract,
     * excluding fields its normalizers omit. Never change actual request items.
     */
    private static function comparableInput(mixed $item): mixed
    {
        if (!\is_array($item)) {
            return $item;
        }

        if ('message' === ($item['type'] ?? null) && 'assistant' === ($item['role'] ?? null)) {
            unset($item['id'], $item['status'], $item['phase']);
            if (\is_array($item['content'] ?? null)) {
                foreach ($item['content'] as &$part) {
                    if (\is_array($part) && 'output_text' === ($part['type'] ?? null)) {
                        unset($part['annotations'], $part['logprobs']);
                    }
                }
                unset($part);
            }
        } elseif ('function_call' === ($item['type'] ?? null)) {
            unset($item['status']);
            // ToolCall conversion parses arguments and the contract encodes
            // them again. Whitespace/escaping are not argument changes.
            if (\is_string($item['arguments'] ?? null) && json_validate($item['arguments'])) {
                $item['arguments'] = json_decode($item['arguments'], flags: \JSON_THROW_ON_ERROR);
            }
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private static function bodyWithoutContinuationFields(array $body): array
    {
        unset($body['input'], $body['previous_response_id'], $body['prompt_cache_key']);

        return $body;
    }

    private static function encode(mixed $value): string
    {
        return json_encode(self::sortObjectKeys($value), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    private static function sortObjectKeys(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            ksort($properties);

            return (object) array_map(self::sortObjectKeys(...), $properties);
        }
        if (!\is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sortObjectKeys(...), $value);
    }
}
