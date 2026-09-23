<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

/**
 * Deterministic structural comparison for Codex continuation eligibility.
 */
final class CodexWebSocketContinuationComparator
{
    /** @var array<string, string> */
    private const array KNOWN_ITEM_TYPES = [
        'message' => 'message',
        'function_call' => 'function_call',
        'function_call_output' => 'function_call_output',
        'reasoning' => 'reasoning',
        'configuration_update' => 'configuration_update',
        'custom_tool_call' => 'custom_tool_call',
        'custom_tool_call_output' => 'custom_tool_call_output',
        'web_search_call' => 'web_search_call',
        'file_search_call' => 'file_search_call',
        'computer_call' => 'computer_call',
        'computer_call_output' => 'computer_call_output',
        'image_generation_call' => 'image_generation_call',
        'code_interpreter_call' => 'code_interpreter_call',
        'local_shell_call' => 'local_shell_call',
        'mcp_call' => 'mcp_call',
        'mcp_list_tools' => 'mcp_list_tools',
        'mcp_approval_request' => 'mcp_approval_request',
    ];

    /** @var array<string, string> */
    private const array KNOWN_ITEM_ROLES = [
        'user' => 'role:user',
        'assistant' => 'role:assistant',
        'system' => 'role:system',
        'developer' => 'role:developer',
        'tool' => 'role:tool',
    ];

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
     * Locate the first structural mismatch between two input prefixes.
     *
     * Returns only kinds and an index — never item contents.
     *
     * @param list<mixed> $left
     * @param list<mixed> $right
     *
     * @return array{first_mismatch_index: ?int, left_item_kind: ?string, right_item_kind: ?string, prefix_normalized_equal: bool}
     */
    public static function describePrefixMismatch(array $left, array $right): array
    {
        $leftComparable = array_map(self::comparableInput(...), $left);
        $rightComparable = array_map(self::comparableInput(...), $right);
        if (self::encode($leftComparable) === self::encode($rightComparable)) {
            return [
                'first_mismatch_index' => null,
                'left_item_kind' => null,
                'right_item_kind' => null,
                'prefix_normalized_equal' => true,
            ];
        }

        $limit = min(\count($leftComparable), \count($rightComparable));
        for ($i = 0; $i < $limit; ++$i) {
            if (self::encode($leftComparable[$i]) !== self::encode($rightComparable[$i])) {
                return [
                    'first_mismatch_index' => $i,
                    'left_item_kind' => self::itemKind($left[$i] ?? null),
                    'right_item_kind' => self::itemKind($right[$i] ?? null),
                    'prefix_normalized_equal' => false,
                ];
            }
        }

        return [
            'first_mismatch_index' => $limit,
            'left_item_kind' => \count($left) > $limit ? self::itemKind($left[$limit] ?? null) : null,
            'right_item_kind' => \count($right) > $limit ? self::itemKind($right[$limit] ?? null) : null,
            'prefix_normalized_equal' => false,
        ];
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

    private static function itemKind(mixed $item): ?string
    {
        if (!\is_array($item)) {
            return null;
        }

        $type = $item['type'] ?? null;
        if (\is_string($type) && '' !== $type) {
            return self::KNOWN_ITEM_TYPES[$type] ?? 'other';
        }

        $role = $item['role'] ?? null;
        if (\is_string($role) && '' !== $role) {
            return self::KNOWN_ITEM_ROLES[$role] ?? 'other';
        }

        return 'other';
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
