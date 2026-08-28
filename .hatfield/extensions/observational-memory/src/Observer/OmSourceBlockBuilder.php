<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Observer;

use Ineersa\Hatfield\ExtensionApi\Session\SessionEventDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Support\OmIdentity;

/**
 * Deterministic source-addressed blocks for Observer chunks.
 *
 * Tool call + matching results stay atomic. Tool results are digested with
 * prefix/suffix + content hash (not silently truncated without identity).
 */
final class OmSourceBlockBuilder
{
    private const int TOOL_DIGEST_PREFIX_CHARS = 400;

    private const int TOOL_DIGEST_SUFFIX_CHARS = 200;

    /**
     * @param list<SessionEventDTO> $events
     *
     * @return list<array{
     *   run_id: string,
     *   seq: int,
     *   kind: string,
     *   rendered_text: string,
     *   source_refs: list<array{run_id: string, seq: int}>
     * }>
     */
    public function build(array $events): array
    {
        if ([] === $events) {
            return [];
        }

        usort($events, static fn (SessionEventDTO $a, SessionEventDTO $b): int => $a->seq <=> $b->seq);

        $pendingToolCalls = [];
        $blocks = [];

        foreach ($events as $event) {
            $timestamp = $this->eventTimestamp($event);

            switch ($event->type) {
                case 'run_started':
                    $messages = $event->payload['payload']['messages'] ?? $event->payload['messages'] ?? null;
                    if (\is_array($messages)) {
                        foreach ($messages as $message) {
                            if (!\is_array($message)) {
                                continue;
                            }
                            $role = (string) ($message['role'] ?? 'user');
                            $text = $this->messageText($message);
                            $blocks[] = $this->singleBlock(
                                $event,
                                'message',
                                $this->formatRoleLine($role, $timestamp, $text),
                            );
                        }
                    }
                    break;

                case 'agent_command_applied':
                    $text = (string) ($event->payload['text'] ?? '');
                    if ('' === $text && isset($event->payload['message']) && \is_array($event->payload['message'])) {
                        $text = $this->messageText($event->payload['message']);
                    }
                    $blocks[] = $this->singleBlock(
                        $event,
                        'user',
                        $this->formatRoleLine('User', $timestamp, $text),
                    );
                    break;

                case 'llm_step_completed':
                    $assistant = $event->payload['assistant_message'] ?? null;
                    $parts = [];
                    if (\is_array($assistant)) {
                        $parts[] = $this->formatRoleLine('Assistant', $timestamp, $this->messageText($assistant));
                        $toolCalls = $assistant['tool_calls'] ?? null;
                        if (\is_array($toolCalls)) {
                            foreach ($toolCalls as $toolCall) {
                                if (!\is_array($toolCall)) {
                                    continue;
                                }
                                $id = (string) ($toolCall['id'] ?? $toolCall['tool_call_id'] ?? '');
                                $name = (string) ($toolCall['name'] ?? $toolCall['function']['name'] ?? 'tool');
                                $args = $toolCall['arguments'] ?? $toolCall['function']['arguments'] ?? [];
                                $parts[] = \sprintf('[Tool call %s name=%s args=%s]', $id, $name, $this->jsonCompact($args));
                                if ('' !== $id) {
                                    $pendingToolCalls[$id] = [
                                        'event' => $event,
                                        'name' => $name,
                                        'parts' => $parts,
                                        'timestamp' => $timestamp,
                                    ];
                                    $parts = [];
                                }
                            }
                        }
                    } elseif (isset($event->payload['text']) && \is_string($event->payload['text'])) {
                        $parts[] = $this->formatRoleLine('Assistant', $timestamp, $event->payload['text']);
                    }

                    if ([] !== $parts) {
                        $blocks[] = $this->singleBlock($event, 'assistant', implode("\n", $parts));
                    }
                    break;

                case 'tool_execution_end':
                    $typedResult = $event->payload['tool_result'] ?? null;
                    if (!\is_array($typedResult)) {
                        throw new \UnexpectedValueException('ToolExecutionEnd requires an array tool_result payload.');
                    }
                    $toolCallId = (string) ($typedResult['tool_call_id'] ?? '');
                    $result = \is_array($typedResult['result'] ?? null) ? $typedResult['result'] : [];
                    $name = (string) ($result['tool_name'] ?? 'tool');
                    $resultText = $this->messageText($result);
                    $isError = true === ($typedResult['is_error'] ?? false);
                    $digested = $this->truncateToolResultWithDigest($resultText);
                    $resultLine = \sprintf(
                        '[Tool result for %s @ %s]%s:',
                        $name,
                        $timestamp,
                        $isError ? ' ERROR' : '',
                    )."\n".$digested;

                    if ('' !== $toolCallId && isset($pendingToolCalls[$toolCallId])) {
                        $pending = $pendingToolCalls[$toolCallId];
                        unset($pendingToolCalls[$toolCallId]);
                        /** @var SessionEventDTO $callEvent */
                        $callEvent = $pending['event'];
                        $rendered = implode("\n", $pending['parts'])."\n".$resultLine;
                        $blocks[] = [
                            'run_id' => $callEvent->runId,
                            'seq' => $callEvent->seq,
                            'kind' => 'tool_group',
                            'rendered_text' => $this->withSourceLabel($callEvent, $rendered),
                            'source_refs' => OmIdentity::normalizeSourceRefs([
                                ['run_id' => $callEvent->runId, 'seq' => $callEvent->seq],
                                ['run_id' => $event->runId, 'seq' => $event->seq],
                            ]),
                        ];
                    } else {
                        $blocks[] = $this->singleBlock($event, 'tool_result', $resultLine);
                    }
                    break;

                case 'llm_step_failed':
                    $blocks[] = $this->singleBlock(
                        $event,
                        'outcome',
                        '[Outcome @ '.$timestamp.']: llm_step_failed retryable='.((bool) ($event->payload['retryable'] ?? false) ? 'true' : 'false'),
                    );
                    break;

                case 'agent_end':
                    $blocks[] = $this->singleBlock(
                        $event,
                        'outcome',
                        '[Outcome @ '.$timestamp.']: agent_end reason='.(string) ($event->payload['reason'] ?? 'completed'),
                    );
                    break;

                default:
                    // Skip non-content control events from source blocks.
                    break;
            }
        }

        // Flush unmatched tool calls as assistant blocks.
        foreach ($pendingToolCalls as $pending) {
            /** @var SessionEventDTO $callEvent */
            $callEvent = $pending['event'];
            $blocks[] = $this->singleBlock($callEvent, 'tool_call', implode("\n", $pending['parts']));
        }

        return $blocks;
    }

    /**
     * @return array{
     *   run_id: string,
     *   seq: int,
     *   kind: string,
     *   rendered_text: string,
     *   source_refs: list<array{run_id: string, seq: int}>
     * }
     */
    private function singleBlock(SessionEventDTO $event, string $kind, string $body): array
    {
        return [
            'run_id' => $event->runId,
            'seq' => $event->seq,
            'kind' => $kind,
            'rendered_text' => $this->withSourceLabel($event, $body),
            'source_refs' => [['run_id' => $event->runId, 'seq' => $event->seq]],
        ];
    }

    private function withSourceLabel(SessionEventDTO $event, string $body): string
    {
        return \sprintf("[Source entry id: %s:%d]\n%s", $event->runId, $event->seq, rtrim($body));
    }

    private function formatRoleLine(string $role, string $timestamp, string $text): string
    {
        $label = match (strtolower($role)) {
            'user' => 'User',
            'assistant' => 'Assistant',
            'tool' => 'Tool',
            default => ucfirst($role),
        };

        return \sprintf('[%s @ %s]: %s', $label, $timestamp, $text);
    }

    private function eventTimestamp(SessionEventDTO $event): string
    {
        if ('' !== $event->createdAt) {
            return OmIdentity::backfillTimestamp($event->createdAt);
        }

        return (new \DateTimeImmutable('now'))->format('Y-m-d H:i');
    }

    private function truncateToolResultWithDigest(string $text): string
    {
        $chars = mb_strlen($text, 'UTF-8');
        $sha = hash('sha256', $text);
        if ($chars <= self::TOOL_DIGEST_PREFIX_CHARS + self::TOOL_DIGEST_SUFFIX_CHARS + 64) {
            return $text;
        }

        $prefix = mb_substr($text, 0, self::TOOL_DIGEST_PREFIX_CHARS, 'UTF-8');
        $suffix = mb_substr($text, -self::TOOL_DIGEST_SUFFIX_CHARS, null, 'UTF-8');

        return $prefix."\n...[tool output digest sha256=".$sha.' chars='.$chars."]...\n".$suffix;
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

    private function jsonCompact(mixed $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
