<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Tool\DiagnosticMessageSanitizer;

/** Error-only, host-owned capture of the rejected pair, not full history. */
final class CodexContinuationDiagnosticFormatter
{
    private const int MAX_ITEM_BYTES = 16_384;

    /**
     * @param array<string, mixed> $diagnostics
     *
     * @return array<string, mixed>
     */
    public static function format(array $diagnostics): array
    {
        $index = $diagnostics['first_mismatch_index'] ?? null;
        $field = $diagnostics['mismatch_field_path'] ?? null;
        if (\is_int($index)) {
            $suffix = \is_string($field) && '.' !== $field ? (str_starts_with($field, '[') ? $field : '.'.$field) : '';
            $offset = $diagnostics['mismatch_response_item_offset'] ?? null;
            $diagnostics['current_path'] = 'input['.$index.']'.$suffix;
            $diagnostics['expected_path'] = \is_int($offset)
                ? 'output['.$offset.']'.$suffix
                : 'input['.$index.']'.$suffix;
        }

        foreach (['current_item', 'expected_item'] as $key) {
            if (!\array_key_exists($key, $diagnostics)) {
                continue;
            }
            $original = json_encode($diagnostics[$key], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            $json = json_encode(self::redact($diagnostics[$key]), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            $diagnostics[$key] = [
                'json' => mb_strcut($json, 0, self::MAX_ITEM_BYTES, 'UTF-8'),
                'bytes' => \strlen($json),
                'truncated' => \strlen($json) > self::MAX_ITEM_BYTES,
                'redacted' => $original !== $json,
            ];
        }

        return $diagnostics;
    }

    private static function redact(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            return (object) self::redact(get_object_vars($value));
        }
        if (\is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = \is_string($key) && preg_match('/authorization|api[-_ ]?key|token|secret|password|encrypted_content|prompt_cache_key|^(access|refresh)$/i', $key)
                    ? '<redacted>'
                    : self::redact($child);
            }

            return $value;
        }

        return \is_string($value) ? DiagnosticMessageSanitizer::redact($value) : $value;
    }
}
