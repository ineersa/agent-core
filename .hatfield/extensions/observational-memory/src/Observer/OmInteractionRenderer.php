<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Observer;

use Ineersa\Hatfield\ExtensionApi\Session\SessionEventDTO;

/**
 * Deterministic package-local renderer for one complete interaction range.
 *
 * Does not depend on CodingAgent compaction internals.
 */
final class OmInteractionRenderer
{
    public const string PROFILE_NORMAL = 'normal';

    public const string PROFILE_AGGRESSIVE = 'aggressive';

    /**
     * @param list<SessionEventDTO> $events
     *
     * @return array{
     *   text: string,
     *   source_refs: list<array{run_id: string, seq: int}>,
     *   source_digest: string,
     *   boundary_key: string,
     *   source_start_seq: int,
     *   source_end_seq: int,
     *   token_estimate: int,
     *   profile: string
     * }
     */
    public function render(
        string $runId,
        array $events,
        int $terminalEndSeq,
        string $terminalStatus,
        string $rendererVersion,
        int $toolResultMaxChars,
        int $inputBudgetTokens,
    ): array {
        if ([] === $events) {
            throw new \RuntimeException('OM renderer received empty event range.');
        }

        $sourceStartSeq = min(array_map(static fn (SessionEventDTO $e): int => $e->seq, $events));
        $sourceEndSeq = max(array_map(static fn (SessionEventDTO $e): int => $e->seq, $events));
        if ($sourceEndSeq > $terminalEndSeq) {
            $sourceEndSeq = $terminalEndSeq;
            $events = array_values(array_filter(
                $events,
                static fn (SessionEventDTO $e): bool => $e->seq <= $terminalEndSeq,
            ));
        }

        $sourceRefs = [];
        foreach ($events as $event) {
            $sourceRefs[] = ['run_id' => $event->runId, 'seq' => $event->seq];
        }

        $sourceDigest = $this->sourceDigest($events);
        $boundaryKey = $this->boundaryKey(
            $runId,
            $sourceStartSeq,
            $sourceEndSeq,
            $terminalEndSeq,
            $terminalStatus,
            $rendererVersion,
        );

        $normal = $this->renderProfile($events, $toolResultMaxChars, self::PROFILE_NORMAL);
        $estimate = OmTokenEstimator::estimate($normal);
        if ($estimate <= $inputBudgetTokens) {
            return [
                'text' => $normal,
                'source_refs' => $sourceRefs,
                'source_digest' => $sourceDigest,
                'boundary_key' => $boundaryKey,
                'source_start_seq' => $sourceStartSeq,
                'source_end_seq' => $sourceEndSeq,
                'token_estimate' => $estimate,
                'profile' => self::PROFILE_NORMAL,
            ];
        }

        $aggressiveMax = max(256, (int) floor($toolResultMaxChars / 2));
        $aggressive = $this->renderProfile($events, $aggressiveMax, self::PROFILE_AGGRESSIVE);
        $aggressiveEstimate = OmTokenEstimator::estimate($aggressive);
        if ($aggressiveEstimate > $inputBudgetTokens) {
            throw new \RuntimeException(\sprintf('OM interaction exceeds observer budget after aggressive render (estimate=%d budget=%d).', $aggressiveEstimate, $inputBudgetTokens));
        }

        return [
            'text' => $aggressive,
            'source_refs' => $sourceRefs,
            'source_digest' => $sourceDigest,
            'boundary_key' => $boundaryKey,
            'source_start_seq' => $sourceStartSeq,
            'source_end_seq' => $sourceEndSeq,
            'token_estimate' => $aggressiveEstimate,
            'profile' => self::PROFILE_AGGRESSIVE,
        ];
    }

    /**
     * @param list<SessionEventDTO> $events
     */
    private function renderProfile(array $events, int $toolResultMaxChars, string $profile): string
    {
        $lines = [];
        $lines[] = '# Interaction';
        $lines[] = 'profile: '.$profile;
        $lines[] = '';

        foreach ($events as $event) {
            $lines[] = \sprintf('## event seq=%d type=%s turn=%d', $event->seq, $event->type, $event->turnNo);
            $payload = $event->payload;

            switch ($event->type) {
                case 'run_started':
                    $messages = $payload['payload']['messages'] ?? $payload['messages'] ?? null;
                    if (\is_array($messages)) {
                        foreach ($messages as $message) {
                            if (!\is_array($message)) {
                                continue;
                            }
                            $role = (string) ($message['role'] ?? 'user');
                            $lines[] = '['.$role.']';
                            $lines[] = $this->messageText($message);
                            $lines[] = '';
                        }
                    }
                    break;

                case 'agent_command_applied':
                    $text = (string) ($payload['text'] ?? '');
                    if ('' === $text && isset($payload['message']) && \is_array($payload['message'])) {
                        $text = $this->messageText($payload['message']);
                    }
                    $kind = (string) ($payload['kind'] ?? 'command');
                    $lines[] = '[user kind='.$kind.']';
                    $lines[] = $text;
                    $lines[] = '';
                    break;

                case 'llm_step_completed':
                    $assistant = $payload['assistant_message'] ?? null;
                    if (\is_array($assistant)) {
                        $lines[] = '[assistant]';
                        $lines[] = $this->messageText($assistant);
                        $toolCalls = $assistant['tool_calls'] ?? null;
                        if (\is_array($toolCalls)) {
                            foreach ($toolCalls as $toolCall) {
                                if (!\is_array($toolCall)) {
                                    continue;
                                }
                                $id = (string) ($toolCall['id'] ?? $toolCall['tool_call_id'] ?? '');
                                $name = (string) ($toolCall['name'] ?? $toolCall['function']['name'] ?? 'tool');
                                $args = $toolCall['arguments'] ?? $toolCall['function']['arguments'] ?? [];
                                $lines[] = \sprintf('[tool_call id=%s name=%s]', $id, $name);
                                $lines[] = 'arguments: '.$this->jsonCompact($args);
                            }
                        }
                        $lines[] = '';
                    } elseif (isset($payload['text']) && \is_string($payload['text'])) {
                        $lines[] = '[assistant]';
                        $lines[] = $payload['text'];
                        $lines[] = '';
                    }
                    break;

                case 'tool_execution_start':
                    $lines[] = \sprintf(
                        '[tool_start id=%s name=%s]',
                        (string) ($payload['tool_call_id'] ?? ''),
                        (string) ($payload['tool_name'] ?? ''),
                    );
                    break;

                case 'tool_execution_end':
                case 'tool_call_result_received':
                    $result = $payload['result'] ?? $payload['output'] ?? '';
                    $bounded = $this->boundToolResult(\is_string($result) ? $result : $this->jsonCompact($result), $toolResultMaxChars);
                    $lines[] = \sprintf(
                        '[tool_result id=%s status=%s]',
                        (string) ($payload['tool_call_id'] ?? ''),
                        (bool) ($payload['is_error'] ?? false) ? 'error' : 'ok',
                    );
                    $lines[] = $bounded['preview'];
                    $lines[] = 'sha256: '.$bounded['sha256'];
                    $lines[] = 'original_chars: '.(string) $bounded['original_chars'];
                    $lines[] = '';
                    break;

                case 'message_end':
                    $message = $payload['message'] ?? null;
                    if (\is_array($message) && 'tool' === ($message['role'] ?? null)) {
                        $text = $this->messageText($message);
                        $bounded = $this->boundToolResult($text, $toolResultMaxChars);
                        $lines[] = '[tool_message]';
                        $lines[] = $bounded['preview'];
                        $lines[] = 'sha256: '.$bounded['sha256'];
                        $lines[] = 'original_chars: '.(string) $bounded['original_chars'];
                        $lines[] = '';
                    }
                    break;

                case 'llm_step_failed':
                    $lines[] = '[outcome status=failed]';
                    $lines[] = 'retryable: '.((bool) ($payload['retryable'] ?? false) ? 'true' : 'false');
                    $lines[] = '';
                    break;

                case 'agent_end':
                    $lines[] = '[outcome status='.(string) ($payload['reason'] ?? 'completed').']';
                    $lines[] = '';
                    break;

                default:
                    // Keep metadata for non-content events without dumping large payloads.
                    $lines[] = 'payload_keys: '.$this->jsonCompact(array_keys($payload));
                    break;
            }
        }

        return rtrim(implode("\n", $lines))."\n";
    }

    /**
     * @return array{preview: string, sha256: string, original_chars: int}
     */
    private function boundToolResult(string $text, int $maxChars): array
    {
        $originalChars = mb_strlen($text, 'UTF-8');
        $sha = hash('sha256', $text);
        if ($originalChars <= $maxChars) {
            return [
                'preview' => $text,
                'sha256' => $sha,
                'original_chars' => $originalChars,
            ];
        }

        $half = max(64, (int) floor(($maxChars - 32) / 2));
        $prefix = mb_substr($text, 0, $half, 'UTF-8');
        $suffix = mb_substr($text, -$half, null, 'UTF-8');
        $preview = $prefix."\n...[truncated]...\n".$suffix;

        return [
            'preview' => $preview,
            'sha256' => $sha,
            'original_chars' => $originalChars,
        ];
    }

    /**
     * @param array<string, mixed> $message
     */
    private function messageText(array $message): string
    {
        if (isset($message['content']) && \is_string($message['content'])) {
            return $message['content'];
        }

        if (isset($message['content']) && \is_array($message['content'])) {
            $parts = [];
            foreach ($message['content'] as $part) {
                if (\is_array($part) && isset($part['text']) && \is_string($part['text'])) {
                    $parts[] = $part['text'];
                } elseif (\is_string($part)) {
                    $parts[] = $part;
                }
            }

            return implode("\n", $parts);
        }

        if (isset($message['text']) && \is_string($message['text'])) {
            return $message['text'];
        }

        return $this->jsonCompact($message);
    }

    /**
     * @param list<SessionEventDTO> $events
     */
    private function sourceDigest(array $events): string
    {
        $canonical = [];
        foreach ($events as $event) {
            $canonical[] = [
                'run_id' => $event->runId,
                'seq' => $event->seq,
                'turn_no' => $event->turnNo,
                'type' => $event->type,
                'payload' => $this->canonicalize($event->payload),
            ];
        }

        return hash('sha256', $this->jsonCompact($canonical));
    }

    private function boundaryKey(
        string $runId,
        int $sourceStartSeq,
        int $sourceEndSeq,
        int $terminalEndSeq,
        string $terminalStatus,
        string $rendererVersion,
    ): string {
        return hash('sha256', $this->jsonCompact([
            'version' => 1,
            'run_id' => $runId,
            'source_start_seq' => $sourceStartSeq,
            'source_end_seq' => $sourceEndSeq,
            'terminal_end_seq' => $terminalEndSeq,
            'terminal_status' => $terminalStatus,
            'renderer_version' => $rendererVersion,
        ]));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        if ($isList) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = $this->canonicalize($v);
        }

        return $out;
    }

    private function jsonCompact(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
    }
}
