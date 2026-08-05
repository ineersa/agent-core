<?php

declare(strict_types=1);

namespace Ineersa\Platform\Diagnostics;

/**
 * Privacy-safe structural fingerprints for one logical PlatformInterface::invoke() attempt.
 *
 * Carried through Symfony AI options and recorded only at final model-client seams.
 * Persists digests/lengths/metadata only — never raw prompts, tools output, headers, or secrets.
 *
 * Canonical ordering: associative keys are sorted recursively; list order is preserved so
 * tool/input order changes surface as first-difference rather than silent reordering.
 */
final class PromptCacheRequestDiagnosticsRecorder
{
    public const string OPTION_KEY = '_prompt_cache_diagnostics';

    /**
     * @var list<array<string, mixed>>
     */
    private array $records = [];

    /**
     * @param array<string, mixed> $logicalBody Exact/near-final provider request body (full context for Codex)
     * @param array{
     *     mode?: string,
     *     wire_input_count?: int|null,
     *     prompt_cache_key_present?: bool,
     *     previous_response_id_present?: bool,
     *     model?: string|null
     * } $wireMeta
     */
    public function record(
        array $logicalBody,
        string $provider,
        string $transport,
        string $hmacKeySource,
        array $wireMeta = [],
    ): void {
        $keySource = '' !== $hmacKeySource ? $hmacKeySource : 'unknown';
        $components = $this->buildComponents($logicalBody, $keySource);
        $canonicalRequest = $this->canonicalJson($logicalBody);

        $this->records[] = [
            'provider' => $provider,
            'transport' => $transport,
            'model' => \is_string($wireMeta['model'] ?? null) ? $wireMeta['model'] : null,
            'mode' => \is_string($wireMeta['mode'] ?? null) ? $wireMeta['mode'] : 'full_context',
            'prompt_cache_key_present' => (bool) ($wireMeta['prompt_cache_key_present'] ?? \array_key_exists('prompt_cache_key', $logicalBody)),
            'previous_response_id_present' => (bool) ($wireMeta['previous_response_id_present'] ?? \array_key_exists('previous_response_id', $logicalBody)),
            'wire_input_count' => \array_key_exists('wire_input_count', $wireMeta)
                ? (null === $wireMeta['wire_input_count'] ? null : (int) $wireMeta['wire_input_count'])
                : $this->countListField($logicalBody, 'input') ?? $this->countListField($logicalBody, 'messages'),
            'cache_family_fp' => $this->hmac($keySource, $keySource),
            'request_hmac' => $this->hmac($canonicalRequest, $keySource),
            'request_bytes' => \strlen($canonicalRequest),
            'components' => $components,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function records(): array
    {
        return $this->records;
    }

    public function isEmpty(): bool
    {
        return [] === $this->records;
    }

    /**
     * Longest common ordered component prefix and first changed/inserted/removed component.
     *
     * Local structural inference only — does not claim provider cache invalidation.
     *
     * @param list<array<string, mixed>> $previousComponents
     * @param list<array<string, mixed>> $currentComponents
     *
     * @return array{
     *     common_prefix_len: int,
     *     first_diff: array{
     *         index: int,
     *         kind: 'changed'|'inserted'|'removed',
     *         previous: ?array<string, mixed>,
     *         current: ?array<string, mixed>
     *     }|null
     * }
     */
    public static function compareComponents(array $previousComponents, array $currentComponents): array
    {
        $max = max(\count($previousComponents), \count($currentComponents));
        $common = 0;
        for ($i = 0; $i < $max; ++$i) {
            $prev = $previousComponents[$i] ?? null;
            $curr = $currentComponents[$i] ?? null;
            if (null === $prev && null === $curr) {
                break;
            }
            if (null === $prev) {
                return [
                    'common_prefix_len' => $common,
                    'first_diff' => [
                        'index' => $i,
                        'kind' => 'inserted',
                        'previous' => null,
                        'current' => $curr,
                    ],
                ];
            }
            if (null === $curr) {
                return [
                    'common_prefix_len' => $common,
                    'first_diff' => [
                        'index' => $i,
                        'kind' => 'removed',
                        'previous' => $prev,
                        'current' => null,
                    ],
                ];
            }
            if (($prev['hmac'] ?? null) !== ($curr['hmac'] ?? null)
                || ($prev['section'] ?? null) !== ($curr['section'] ?? null)
                || ($prev['type'] ?? null) !== ($curr['type'] ?? null)
                || ($prev['role'] ?? null) !== ($curr['role'] ?? null)
                || ($prev['name'] ?? null) !== ($curr['name'] ?? null)
            ) {
                return [
                    'common_prefix_len' => $common,
                    'first_diff' => [
                        'index' => $i,
                        'kind' => 'changed',
                        'previous' => $prev,
                        'current' => $curr,
                    ],
                ];
            }
            ++$common;
        }

        return ['common_prefix_len' => $common, 'first_diff' => null];
    }

    /**
     * @param array<string, mixed> $logicalBody
     *
     * @return list<array<string, mixed>>
     */
    private function buildComponents(array $logicalBody, string $keySource): array
    {
        $components = [];

        if (isset($logicalBody['instructions'])) {
            $canonical = $this->canonicalJson($logicalBody['instructions']);
            $components[] = [
                'section' => 'instructions',
                'type' => 'instructions',
                'role' => null,
                'name' => null,
                'hmac' => $this->hmac($canonical, $keySource),
                'bytes' => \strlen($canonical),
            ];
        }

        $messages = $logicalBody['messages'] ?? null;
        if (\is_array($messages)) {
            $leadingInstructionRoles = ['system', 'developer'];
            $instructionChunks = [];
            $inputStarted = false;
            foreach (array_values($messages) as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $role = \is_string($item['role'] ?? null) ? $item['role'] : null;
                if (!$inputStarted && null !== $role && \in_array($role, $leadingInstructionRoles, true)) {
                    $instructionChunks[] = $item;
                    continue;
                }
                if ([] !== $instructionChunks) {
                    $canonical = $this->canonicalJson($instructionChunks);
                    $components[] = [
                        'section' => 'instructions',
                        'type' => 'messages',
                        'role' => null,
                        'name' => null,
                        'hmac' => $this->hmac($canonical, $keySource),
                        'bytes' => \strlen($canonical),
                    ];
                    $instructionChunks = [];
                }
                $inputStarted = true;
                $components[] = $this->componentFromItem('messages', $item, $keySource);
            }
            if ([] !== $instructionChunks) {
                $canonical = $this->canonicalJson($instructionChunks);
                $components[] = [
                    'section' => 'instructions',
                    'type' => 'messages',
                    'role' => null,
                    'name' => null,
                    'hmac' => $this->hmac($canonical, $keySource),
                    'bytes' => \strlen($canonical),
                ];
            }
        }

        $tools = $logicalBody['tools'] ?? null;
        if (\is_array($tools)) {
            foreach (array_values($tools) as $tool) {
                if (!\is_array($tool)) {
                    continue;
                }
                $name = null;
                if (\is_string($tool['name'] ?? null)) {
                    $name = $tool['name'];
                } elseif (\is_array($tool['function'] ?? null) && \is_string($tool['function']['name'] ?? null)) {
                    $name = $tool['function']['name'];
                }
                $canonical = $this->canonicalJson($tool);
                $components[] = [
                    'section' => 'tools',
                    'type' => \is_string($tool['type'] ?? null) ? $tool['type'] : 'tool',
                    'role' => null,
                    'name' => $name,
                    'hmac' => $this->hmac($canonical, $keySource),
                    'bytes' => \strlen($canonical),
                ];
            }
        }

        $input = $logicalBody['input'] ?? null;
        if (\is_array($input)) {
            foreach (array_values($input) as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $components[] = $this->componentFromItem('input', $item, $keySource);
            }
        }

        return $components;
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function componentFromItem(string $section, array $item, string $keySource): array
    {
        $name = null;
        if (\is_string($item['name'] ?? null)) {
            $name = $item['name'];
        } elseif (\is_string($item['tool_name'] ?? null)) {
            $name = $item['tool_name'];
        } elseif (\is_string($item['call_id'] ?? null)) {
            $name = $item['call_id'];
        }

        $canonical = $this->canonicalJson($item);

        return [
            'section' => $section,
            'type' => \is_string($item['type'] ?? null) ? $item['type'] : null,
            'role' => \is_string($item['role'] ?? null) ? $item['role'] : null,
            'name' => $name,
            'hmac' => $this->hmac($canonical, $keySource),
            'bytes' => \strlen($canonical),
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function countListField(array $body, string $key): ?int
    {
        $value = $body[$key] ?? null;

        return \is_array($value) ? \count($value) : null;
    }

    private function hmac(string $canonical, string $keySource): string
    {
        return hash_hmac('sha256', $canonical, $keySource);
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->canonicalize($value), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $out = [];
            foreach ($value as $item) {
                $out[] = $this->canonicalize($item);
            }

            return $out;
        }

        ksort($value);
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = $this->canonicalize($item);
        }

        return $out;
    }
}
