<?php

declare(strict_types=1);

namespace Ineersa\Tui\Export;

use Ineersa\Tui\Command\TranscriptMessage;

final class SessionEventsExportService
{
    public function exportEventsFile(
        string $eventsPath,
        string $outputPath,
        string $headerSessionId,
        string $sessionName = '',
        string $sessionCwd = '',
        string $createdAt = '',
    ): string {
        if (!is_file($eventsPath) || !is_readable($eventsPath)) {
            throw new \RuntimeException(\sprintf('Session %s has no events to export.', $headerSessionId));
        }
        $eventsContent = file_get_contents($eventsPath);
        if (false === $eventsContent || '' === trim($eventsContent)) {
            throw new \RuntimeException(\sprintf('Session %s has no events to export.', $headerSessionId));
        }
        if (str_ends_with($outputPath, '.jsonl')) {
            $result = $this->exportJsonl($eventsPath, $outputPath);
            if ('error' === $result->role) {
                throw new \RuntimeException($result->text);
            }

            return $result->text;
        }
        $result = $this->exportHtml($headerSessionId, $eventsContent, $outputPath, $sessionName, $sessionCwd, $createdAt);
        if ('error' === $result->role || str_contains($result->text, 'no events')) {
            throw new \RuntimeException($result->text);
        }

        return $result->text;
    }

    public static function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    public function exportJsonl(string $sourcePath, string $outputPath): TranscriptMessage
    {
        $dir = \dirname($outputPath);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
                return new TranscriptMessage(
                    \sprintf('Cannot create directory: %s', $dir),
                    'error',
                );
            }
        }

        if (false === @copy($sourcePath, $outputPath)) {
            return new TranscriptMessage(
                \sprintf('Failed to write export to: %s', $outputPath),
                'error',
            );
        }

        return new TranscriptMessage(
            \sprintf('Session exported to: %s', $outputPath),
        );
    }

    /**
     * Generate a standalone HTML export from the canonical events JSONL.
     */
    public function exportHtml(
        string $sessionId,
        string $eventsContent,
        string $outputPath,
        string $sessionName = '',
        string $sessionCwd = '',
        string $createdAt = '',
    ): TranscriptMessage {
        $events = $this->parseEvents($eventsContent);
        if ([] === $events) {
            return new TranscriptMessage(
                \sprintf('Session %s has no events to export.', $sessionId),
                'system',
                'muted',
            );
        }

        $displayName = '' !== $sessionName ? $sessionName : 'Session '.$sessionId;
        $title = self::escapeHtml($displayName);
        $html = $this->buildHtml($title, $sessionId, $sessionCwd, $createdAt, $events);

        $dir = \dirname($outputPath);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
                return new TranscriptMessage(
                    \sprintf('Cannot create directory: %s', $dir),
                    'error',
                );
            }
        }

        if (false === @file_put_contents($outputPath, $html)) {
            return new TranscriptMessage(
                \sprintf('Failed to write export to: %s', $outputPath),
                'error',
            );
        }

        return new TranscriptMessage(
            \sprintf('Session exported to: %s', $outputPath),
        );
    }

    /**
     * Safely extract a string value from an array with a default.
     *
     * @param array<string, mixed> $data
     */
    public static function strFromArray(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) ? $value : $default;
    }

    /**
     * Safely extract an int value from an array with a default.
     *
     * @param array<string, mixed> $data
     */
    public static function intFromArray(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? null;

        return \is_int($value) ? $value : $default;
    }

    /**
     * Safely extract a string value from a nested array path.
     *
     * @param array<string, mixed> $data
     * @param list<string>         $keys
     */
    public static function strFromNested(array $data, array $keys, string $default = ''): string
    {
        $current = $data;
        foreach ($keys as $key) {
            if (!\is_array($current) || !\array_key_exists($key, $current)) {
                return $default;
            }
            $current = $current[$key];
        }

        return \is_string($current) ? $current : $default;
    }

    // ── HTML generation ────────────────────────────────────────────────────

    /**
     * Build the full standalone HTML document.
     *
     * @param list<array<string, mixed>> $events
     */
    private function buildHtml(
        string $title,
        string $sessionId,
        string $cwd,
        string $createdAt,
        array $events,
    ): string {
        $bodyHtml = $this->renderEvents($events);

        $escapedTitle = $title;
        $escapedSessionId = self::escapeHtml($sessionId);
        $escapedCwd = self::escapeHtml($cwd);
        $escapedCreatedAt = self::escapeHtml($createdAt);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$escapedTitle} — Hatfield Session Export</title>
<style>
{$this->exportCss()}
</style>
</head>
<body>
<header class="session-header">
  <h1>{$escapedTitle}</h1>
  <div class="session-meta">
    <span>Session: {$escapedSessionId}</span>
    {$this->metaIf($escapedCwd, 'CWD', $escapedCwd)}
    {$this->metaIf($escapedCreatedAt, 'Created', $escapedCreatedAt)}
  </div>
</header>
<main class="transcript">
{$bodyHtml}
</main>
<footer class="export-footer">
  Exported from Hatfield agent-core
</footer>
</body>
</html>
HTML;
    }

    private function metaIf(string $value, string $label, string $escapedValue): string
    {
        if ('' === $value) {
            return '';
        }

        return " | {$label}: {$escapedValue}";
    }

    /**
     * Render all events into HTML blocks, grouped by turn.
     *
     * @param list<array<string, mixed>> $events
     */
    private function renderEvents(array $events): string
    {
        $html = '';
        $currentTurn = -1;

        // Cross-reference maps built from the full event stream.
        // toolNames: tool_call_id → tool_name (from tool_execution_start).
        // toolArgs:   tool_call_id → arguments (from llm_step_completed.assistant_message.tool_calls).
        $toolNames = [];
        $toolArgs = [];

        // First pass — build the cross-reference maps.
        foreach ($events as $event) {
            $type = self::strFromArray($event, 'type');
            $payload = \is_array($event['payload'] ?? null) ? $event['payload'] : [];

            if ('tool_execution_start' === $type) {
                $tcId = self::strFromArray($payload, 'tool_call_id');
                $tcName = self::strFromArray($payload, 'tool_name');
                if ('' !== $tcId && '' !== $tcName) {
                    $toolNames[$tcId] = $tcName;
                }
            }

            // Extract tool_call arguments from assistant_message blocks.
            // In real events.jsonl, tool calls live at
            //   llm_step_completed.payload.assistant_message.tool_calls[].{id,name,arguments}
            if ('llm_step_completed' === $type) {
                $assistantMessage = $payload['assistant_message'] ?? null;
                if (\is_array($assistantMessage)) {
                    $toolCalls = $assistantMessage['tool_calls'] ?? null;
                    if (\is_array($toolCalls)) {
                        foreach ($toolCalls as $tc) {
                            if (!\is_array($tc)) {
                                continue;
                            }
                            $tcId = self::strFromArray($tc, 'id');
                            if ('' !== $tcId) {
                                $tcName = self::strFromArray($tc, 'name');
                                $tcArguments = $tc['arguments'] ?? null;
                                if ('' !== $tcName) {
                                    $toolNames[$tcId] = $tcName;
                                }
                                // Store raw arguments (may be string or array).
                                $toolArgs[$tcId] = $tcArguments;
                            }
                        }
                    }
                }
            }
        }

        // Second pass — render events with the cross-reference maps.
        foreach ($events as $event) {
            $type = self::strFromArray($event, 'type');
            $turnNo = self::intFromArray($event, 'turn_no');

            // Start a new turn group when the turn number changes.
            if ($turnNo !== $currentTurn) {
                if ($currentTurn >= 0) {
                    $html .= "</div>\n"; // Close previous turn.
                }
                $currentTurn = $turnNo;
                $html .= '<div class="turn">'."\n";
                $html .= '  <div class="turn-label">Turn '.$turnNo.'</div>'."\n";
            }

            $html .= $this->renderEvent($event, $toolNames, $toolArgs);
        }

        if ($currentTurn >= 0) {
            $html .= "</div>\n";
        }

        return $html;
    }

    /**
     * Render a single event into its HTML representation.
     *
     * Every event produces an event card with metadata and the full event
     * JSON in an escaped <pre> block (mandatory per task spec).  Known
     * event types additionally receive a human-friendly readable summary.
     *
     * @param array<string, mixed>  $event
     * @param array<string, string> $toolNames tool_call_id → tool_name map
     * @param array<string, mixed>  $toolArgs  tool_call_id → arguments map
     */
    private function renderEvent(array $event, array $toolNames = [], array $toolArgs = []): string
    {
        $type = self::strFromArray($event, 'type');
        $seq = self::intFromArray($event, 'seq');
        $ts = self::strFromArray($event, 'ts');
        $payload = \is_array($event['payload'] ?? null) ? $event['payload'] : [];

        // Friendly readable summary (may be empty for unknown / turn-advanced events).
        $readable = match ($type) {
            'run_started' => $this->renderRunStarted($payload),
            'llm_step_completed' => $this->renderAssistantMessage($payload),
            'llm_step_failed' => $this->renderAssistantFailed($payload),
            'llm_step_aborted' => $this->renderTurnCancelled($payload),
            'tool_execution_start' => $this->renderToolStart($payload, $toolArgs),
            'tool_execution_end' => $this->renderToolEnd($payload, $toolNames),
            'agent_end' => $this->renderAgentEnd($payload),
            'agent_command_applied' => $this->renderAgentCommandApplied($payload),
            'model_notification' => $this->renderModelNotification($payload),
            default => $this->renderGenericEvent($payload),
        };

        // Full event JSON (escaped) — mandatory per task spec.
        $rawJson = json_encode($event, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        if (!\is_string($rawJson)) {
            $rawJson = json_encode($event, \JSON_UNESCAPED_SLASHES);
            if (!\is_string($rawJson)) {
                $rawJson = '{}';
            }
        }
        $escapedJson = self::escapeHtml($rawJson);

        $html = '  <div class="event event-'.self::escapeHtml($type).'">'."\n";

        // Metadata header.
        $html .= '    <div class="event-meta">';
        $html .= '<span class="event-type">'.self::escapeHtml($type).'</span>';
        $html .= ' <span class="event-seq">seq '.$seq.'</span>';
        if ('' !== $ts) {
            $html .= ' <span class="event-ts">'.self::escapeHtml($ts).'</span>';
        }
        $html .= "</div>\n";

        // Friendly readable content.
        if ('' !== $readable) {
            $html .= $readable;
        }

        // Full event JSON in collapsible details block.
        $html .= '    <details class="event-raw">'."\n";
        $html .= '      <summary>Raw event</summary>'."\n";
        $html .= '      <pre class="event-json">'.$escapedJson."</pre>\n";
        $html .= "    </details>\n";

        $html .= "  </div>\n";

        return $html;
    }

    /**
     * Render the run_started event: extract user/system/instruction messages.
     *
     * Real events.jsonl stores messages at payload.payload.messages;
     * some test fixtures use payload.user_messages.  We try both paths.
     *
     * @param array<string, mixed> $payload
     */
    private function renderRunStarted(array $payload): string
    {
        // Primary path: payload.payload.messages (real events.jsonl).
        $nestedPayload = $payload['payload'] ?? null;
        if (\is_array($nestedPayload)) {
            $messages = $nestedPayload['messages'] ?? null;
            if (\is_array($messages)) {
                return $this->renderMessages($messages);
            }
        }

        // Fallback: payload.user_messages (test fixtures and older format).
        $userMessages = $payload['user_messages'] ?? null;
        if (\is_array($userMessages)) {
            return $this->renderMessages($userMessages);
        }

        return '';
    }

    /**
     * Render the llm_step_completed event: assistant text, thinking, usage, tool calls.
     *
     * Real events.jsonl has the assistant_message payload nested:
     *   payload.assistant_message.{content,details.thinking,tool_calls,role}
     *   payload.usage.{input_tokens,output_tokens,total_tokens}
     *   payload.text (top-level canonical text)
     *   payload.stop_reason
     *
     * @param array<string, mixed> $payload
     */
    private function renderAssistantMessage(array $payload): string
    {
        $text = self::escapeHtml(self::strFromArray($payload, 'text'));
        $stopReason = self::escapeHtml(self::strFromArray($payload, 'stop_reason'));

        // Thinking is stored at payload.assistant_message.details.thinking
        // in real events.jsonl.  Also check the simpler payload.details.thinking
        // path for test fixtures.
        $assistantMessage = $payload['assistant_message'] ?? null;
        $thinking = '';
        if (\is_array($assistantMessage)) {
            $thinking = self::escapeHtml(self::strFromNested($assistantMessage, ['details', 'thinking']));
        }
        if ('' === $thinking) {
            $thinking = self::escapeHtml(self::strFromNested($payload, ['details', 'thinking']));
        }

        $html = '  <div class="message message-assistant">'."\n";
        $html .= '    <div class="message-role">assistant</div>'."\n";

        // Thinking block.
        if ('' !== $thinking) {
            $html .= '    <details class="thinking-block" open>'."\n";
            $html .= '      <summary>Thinking</summary>'."\n";
            $html .= '      <div class="thinking-content">'.$thinking.'</div>'."\n";
            $html .= "    </details>\n";
        }

        // Assistant text.
        if ('' !== $text) {
            $html .= '    <div class="message-content">'.$text.'</div>'."\n";
        }

        // Usage / token stats.
        $usage = $payload['usage'] ?? null;
        if (\is_array($usage)) {
            $html .= $this->renderUsage($usage);
        }

        // Tool calls from assistant message.
        if (\is_array($assistantMessage)) {
            $toolCalls = $assistantMessage['tool_calls'] ?? null;
            if (\is_array($toolCalls) && [] !== $toolCalls) {
                $html .= $this->renderToolCalls($toolCalls);
            }
        }

        // Stop reason / metadata.
        if ('' !== $stopReason && 'end_turn' !== $stopReason) {
            $html .= '    <div class="message-meta">stop: '.$stopReason.'</div>'."\n";
        }

        $html .= "  </div>\n";

        return $html;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderAssistantFailed(array $payload): string
    {
        $text = self::escapeHtml(self::strFromArray($payload, 'text'));

        return '  <div class="message message-error">'."\n"
            .'    <div class="message-role">error</div>'."\n"
            .'    <div class="message-content">'.$text.'</div>'."\n"
            ."  </div>\n";
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderTurnCancelled(array $payload): string
    {
        $reason = self::escapeHtml(self::strFromArray($payload, 'reason', 'aborted'));

        return '  <div class="message message-system">Turn cancelled: '.$reason."</div>\n";
    }

    /**
     * Render a tool_execution_start event with optional arguments from the
     * cross-reference map built during renderEvents.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $toolArgs tool_call_id → arguments map
     */
    private function renderToolStart(array $payload, array $toolArgs = []): string
    {
        $toolName = self::escapeHtml(self::strFromArray($payload, 'tool_name', 'unknown'));
        $toolCallId = self::strFromArray($payload, 'tool_call_id');
        $escapedTcId = self::escapeHtml($toolCallId);

        $html = '  <div class="tool-call">'."\n";

        // Show tool name and ID as the summary.
        $summary = '<span class="tool-name">'.$toolName.'</span>';
        if ('' !== $escapedTcId) {
            $summary .= ' <span class="tool-call-id">'.$escapedTcId.'</span>';
        }

        // Do we have arguments to show?
        $hasArgs = '' !== $toolCallId && \array_key_exists($toolCallId, $toolArgs);
        if (!$hasArgs) {
            $html .= '    <div class="tool-call-header">'.$summary."</div>\n";
            $html .= "  </div>\n";

            return $html;
        }

        $html .= '    <details open>'."\n";
        $html .= '      <summary>'.$summary."</summary>\n";
        $html .= '      <div class="tool-args">'.self::renderPrettyJson($toolArgs[$toolCallId])."</div>\n";
        $html .= "    </details>\n";
        $html .= "  </div>\n";

        return $html;
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $toolNames tool_call_id → tool_name map
     */
    private function renderToolEnd(array $payload, array $toolNames = []): string
    {
        $toolCallId = self::strFromArray($payload, 'tool_call_id');
        $toolName = $toolNames[$toolCallId] ?? '';
        $isError = (bool) ($payload['is_error'] ?? false);
        $result = self::strFromArray($payload, 'result');
        $durationMs = \is_int($payload['duration_ms'] ?? null) ? $payload['duration_ms'] : null;

        $html = '  <div class="'.($isError ? 'tool-result tool-error' : 'tool-result').'">'."\n";
        $html .= '    <details>'."\n";
        $html .= '      <summary>Result';

        if ('' !== $toolName) {
            $html .= ': <span class="tool-name">'.self::escapeHtml($toolName).'</span>';
        }
        if (null !== $durationMs) {
            $html .= ' <span class="tool-duration">('.$durationMs.'ms)</span>';
        }
        if ($isError) {
            $html .= ' <span class="tool-error-label">(failed)</span>';
        }

        $html .= "</summary>\n";

        if ('' !== $result) {
            $escapedResult = self::escapeHtml($result);
            $html .= '      <pre class="tool-output">'.$escapedResult."</pre>\n";
        }

        $html .= "    </details>\n";
        $html .= "  </div>\n";

        return $html;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderAgentEnd(array $payload): string
    {
        $reason = self::strFromArray($payload, 'reason');
        $error = self::strFromArray($payload, 'error');

        if ('failed' === $reason && '' !== $error) {
            return '  <div class="message message-error">Run failed: '.self::escapeHtml($error)."</div>\n";
        }
        if ('cancelled' === $reason) {
            return '  <div class="message message-system">Run cancelled.</div>'."\n";
        }

        return '  <div class="message message-system">Run completed.</div>'."\n";
    }

    /**
     * Render agent_command_applied — user messages for subsequent turns.
     *
     * In real events.jsonl the payload carries:
     *   kind: steer | follow_up | cancel | human_response
     *   text: the message text (canonical)
     *
     * @param array<string, mixed> $payload
     */
    private function renderAgentCommandApplied(array $payload): string
    {
        $kind = self::strFromArray($payload, 'kind');
        $text = self::strFromArray($payload, 'text');

        if ('' === $text) {
            return '';
        }

        $label = match ($kind) {
            'steer', 'follow_up', 'append_message' => 'user',
            'human_response' => 'human response',
            'cancel' => 'cancelled',
            default => 'command',
        };

        return '  <div class="message message-'.$label.'">'."\n"
            .'    <div class="message-role">'.$label.'</div>'."\n"
            .'    <div class="message-content">'.self::escapeHtml($text).'</div>'."\n"
            ."  </div>\n";
    }

    /**
     * Render a model_notification event (e.g. tool-call delivery notifications).
     *
     * Real events carry:
     *   kind, text, tool_name, tool_call_id, severity, source
     *
     * @param array<string, mixed> $payload
     */
    private function renderModelNotification(array $payload): string
    {
        $text = self::strFromArray($payload, 'text');
        $kind = self::strFromArray($payload, 'kind');

        if ('' === $text && '' === $kind) {
            return '';
        }

        $label = 'notification';
        if ('' !== $kind) {
            $label .= ' ('.$kind.')';
        }

        $html = '  <div class="message message-system">'."\n";
        $html .= '    <div class="message-role">'.self::escapeHtml($label).'</div>'."\n";
        if ('' !== $text) {
            $html .= '    <div class="message-content">'.self::escapeHtml($text).'</div>'."\n";
        }
        $html .= "  </div>\n";

        return $html;
    }

    /**
     * Generic fallback renderer for unhandled event types.
     *
     * Attempts to surface commonly-named payload fields that are likely
     * to contain human-interesting content without specific knowledge
     * of the event schema.
     *
     * @param array<string, mixed> $payload
     */
    private function renderGenericEvent(array $payload): string
    {
        $html = '';

        // Messages (user/system/developer).
        foreach (['messages', 'user_messages'] as $key) {
            $msgs = $payload[$key] ?? null;
            if (\is_array($msgs) && [] !== $msgs) {
                $html .= $this->renderMessages($msgs);
            }
        }

        // Common text/content fields.
        foreach (['text', 'message', 'content', 'prompt'] as $key) {
            $value = self::strFromArray($payload, $key);
            if ('' !== $value) {
                $html .= '  <div class="message message-system">'."\n";
                $html .= '    <div class="message-role">'.$key.'</div>'."\n";
                $html .= '    <div class="message-content">'.self::escapeHtml($value).'</div>'."\n";
                $html .= "  </div>\n";
            }
        }

        return $html;
    }

    // ── Reusable rendering helpers ─────────────────────────────────────────

    /**
     * Render an array of messages (each with role + content) as message blocks.
     *
     * Content may be a plain string or a list of typed content blocks
     * (e.g. [{"type":"text","text":"..."}]).
     *
     * @param array<int, array<string, mixed>> $messages
     */
    private function renderMessages(array $messages): string
    {
        $html = '';

        foreach ($messages as $msg) {
            if (!\is_array($msg)) {
                continue;
            }

            $role = self::strFromArray($msg, 'role', 'unknown');
            $content = $msg['content'] ?? '';

            // Content may be an array of typed blocks (real events.jsonl format).
            if (\is_array($content)) {
                $content = $this->extractTextFromContentBlocks($content);
            }

            if (!\is_string($content)) {
                $content = '';
            }

            if ('' === $content) {
                continue;
            }

            $html .= '  <div class="message message-'.self::escapeHtml($role).'">'."\n";
            $html .= '    <div class="message-role">'.self::escapeHtml($role).'</div>'."\n";

            // Long system/context instructions get details/summary treatment.
            $contentLen = mb_strlen($content);
            if ($contentLen > 500 && \in_array($role, ['system', 'developer', 'user-context'], true)) {
                $html .= '    <details class="instruction-block" open>'."\n";
                $label = match ($role) {
                    'system' => 'System instructions',
                    'developer' => 'Developer instructions',
                    default => 'Context', // user-context (only remaining option)
                };
                $html .= '      <summary>'.self::escapeHtml($label).' ('.number_format($contentLen).' chars)</summary>'."\n";
                $html .= '      <div class="message-content">'.self::escapeHtml($content).'</div>'."\n";
                $html .= "    </details>\n";
            } else {
                $html .= '    <div class="message-content">'.self::escapeHtml($content).'</div>'."\n";
            }

            $html .= "  </div>\n";
        }

        return $html;
    }

    /**
     * Extract plain text from typed content blocks.
     *
     * Real events.jsonl stores message content as:
     *   [{"type":"text","text":"..."}]
     *
     * @param array<int, array<string, mixed>> $blocks
     */
    private function extractTextFromContentBlocks(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $block) {
            if (\is_array($block) && 'text' === ($block['type'] ?? null) && isset($block['text'])) {
                $parts[] = (string) $block['text'];
            }
        }

        return implode('', $parts);
    }

    /**
     * Render a usage / token stats section.
     *
     * @param array<string, mixed> $usage
     */
    private function renderUsage(array $usage): string
    {
        $inputTokens = self::intFromArray($usage, 'input_tokens');
        $outputTokens = self::intFromArray($usage, 'output_tokens');
        $totalTokens = self::intFromArray($usage, 'total_tokens');

        if (0 === $inputTokens && 0 === $outputTokens && 0 === $totalTokens) {
            return '';
        }

        $html = '    <div class="usage-stats">'."\n";
        $html .= '      <span class="usage-label">Tokens:</span>';
        if ($inputTokens > 0) {
            $html .= ' <span class="usage-item">in: '.number_format($inputTokens).'</span>';
        }
        if ($outputTokens > 0) {
            $html .= ' <span class="usage-item">out: '.number_format($outputTokens).'</span>';
        }
        if ($totalTokens > 0) {
            $html .= ' <span class="usage-item">total: '.number_format($totalTokens).'</span>';
        }
        $html .= "\n    </div>\n";

        return $html;
    }

    /**
     * Render tool_calls from an assistant message.
     *
     * Each tool call carries: id, name, arguments (JSON string or array).
     *
     * @param array<int, array<string, mixed>> $toolCalls
     */
    private function renderToolCalls(array $toolCalls): string
    {
        $html = '';

        foreach ($toolCalls as $tc) {
            if (!\is_array($tc)) {
                continue;
            }

            $tcName = self::escapeHtml(self::strFromArray($tc, 'name', 'unknown'));
            $tcId = self::escapeHtml(self::strFromArray($tc, 'id'));
            $tcArgs = $tc['arguments'] ?? null;

            $html .= '    <div class="tool-call-inline">'."\n";
            $html .= '      <details>'."\n";
            $html .= '        <summary>';
            $html .= '<span class="tool-name">📎 '.$tcName.'</span>';
            if ('' !== $tcId) {
                $html .= ' <span class="tool-call-id">'.$tcId.'</span>';
            }
            $html .= "</summary>\n";

            if (null !== $tcArgs) {
                $html .= '        <div class="tool-args">'.self::renderPrettyJson($tcArgs)."</div>\n";
            }

            $html .= "      </details>\n";
            $html .= "    </div>\n";
        }

        return $html;
    }

    /**
     * Render any value as escaped pretty-printed JSON inside a <pre> block.
     *
     * Accepts arrays, objects, strings, numbers — produces human-readable
     * escaped JSON output.
     */
    private static function renderPrettyJson(mixed $value): string
    {
        // If the value is a JSON string, try to decode and re-encode for
        // pretty-printing (e.g. tool call arguments).
        if (\is_string($value)) {
            $decoded = json_decode($value, true);
            if (null !== $decoded) {
                $value = $decoded;
            }
        }

        if (\is_array($value) || \is_object($value)) {
            $json = json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        } else {
            $json = json_encode([$value], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        }

        if (!\is_string($json)) {
            $json = '{}';
        }

        return '<pre class="pretty-json">'.self::escapeHtml($json).'</pre>'."\n";
    }

    // ── CSS ────────────────────────────────────────────────────────────────

    private function exportCss(): string
    {
        return <<<'CSS'
/* Hatfield Session Export — standalone styles */
:root {
    --bg: #1a1a2e;
    --surface: #16213e;
    --surface-alt: #0f3460;
    --text: #e0e0e0;
    --text-muted: #a0a0a0;
    --accent: #e94560;
    --accent-dim: #c23152;
    --border: #2a2a4a;
    --user-bg: #1a3a5c;
    --assistant-bg: #16213e;
    --tool-bg: #1a2a3a;
    --error-bg: #3a1a1a;
    --code-bg: #0d1117;
    --font: system-ui, -apple-system, sans-serif;
    --mono: 'SF Mono', 'Fira Code', 'Cascadia Code', monospace;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    background: var(--bg);
    color: var(--text);
    font-family: var(--font);
    line-height: 1.6;
    max-width: 900px;
    margin: 0 auto;
    padding: 2rem 1rem;
}
.session-header {
    border-bottom: 2px solid var(--border);
    padding-bottom: 1rem;
    margin-bottom: 2rem;
}
.session-header h1 {
    color: var(--accent);
    font-size: 1.5rem;
    margin-bottom: 0.5rem;
}
.session-meta {
    color: var(--text-muted);
    font-size: 0.85rem;
}
.transcript { }
.turn {
    margin-bottom: 1.5rem;
    border: 1px solid var(--border);
    border-radius: 8px;
    overflow: hidden;
}
.turn-label {
    background: var(--surface-alt);
    color: var(--text-muted);
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 0.35rem 1rem;
}
.message {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--border);
}
.message:last-child { border-bottom: none; }
.message-role {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    margin-bottom: 0.35rem;
}
.message-user { background: var(--user-bg); }
.message-assistant { background: var(--assistant-bg); }
.message-system { background: transparent; color: var(--text-muted); font-style: italic; }
.message-error { background: var(--error-bg); }
.message-content {
    white-space: pre-wrap;
    word-break: break-word;
}
.message-meta {
    font-size: 0.7rem;
    color: var(--text-muted);
    margin-top: 0.5rem;
}
.thinking-block {
    margin-bottom: 0.5rem;
}
.thinking-block summary {
    color: var(--text-muted);
    font-size: 0.8rem;
    cursor: pointer;
}
.thinking-content {
    margin-top: 0.35rem;
    padding: 0.5rem 0.75rem;
    background: var(--code-bg);
    border-radius: 4px;
    font-family: var(--mono);
    font-size: 0.82rem;
    white-space: pre-wrap;
    color: var(--text-muted);
}
.tool-call, .tool-result {
    padding: 0.5rem 1rem;
    background: var(--tool-bg);
    border-bottom: 1px solid var(--border);
}
.tool-call summary, .tool-result summary {
    cursor: pointer;
    font-size: 0.85rem;
}
.tool-name {
    color: var(--accent);
    font-family: var(--mono);
    font-size: 0.82rem;
}
.tool-call-id {
    color: var(--text-muted);
    font-size: 0.7rem;
}
.tool-duration {
    color: var(--text-muted);
    font-size: 0.75rem;
}
.tool-error-label {
    color: #ff6b6b;
    font-size: 0.75rem;
}
.tool-output {
    margin-top: 0.5rem;
    padding: 0.75rem;
    background: var(--code-bg);
    border-radius: 4px;
    font-family: var(--mono);
    font-size: 0.8rem;
    white-space: pre-wrap;
    overflow-x: auto;
    max-height: 400px;
    overflow-y: auto;
}
.tool-error .tool-output {
    color: #ff6b6b;
}
/* Event card */
.event {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 0;
    margin-bottom: 0.5rem;
    overflow: hidden;
}
.event:last-child { margin-bottom: 0; }
.event-meta {
    background: var(--surface-alt);
    padding: 0.25rem 0.75rem;
    font-size: 0.72rem;
    color: var(--text-muted);
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.event-type {
    font-weight: 600;
    color: var(--accent);
    font-family: var(--mono);
}
.event-seq {
    color: var(--text-muted);
}
.event-ts {
    color: var(--text-muted);
    margin-left: auto;
}
/* Readable summary inside event card — inherit existing message/tool styles */
.event .message,
.event .tool-call,
.event .tool-result {
    border: none;
    border-bottom: 1px solid var(--border);
    background: transparent;
}
.event .message:last-child,
.event .tool-call:last-child,
.event .tool-result:last-child {
    border-bottom: none;
}
.event-raw summary {
    font-size: 0.72rem;
    color: var(--text-muted);
    cursor: pointer;
    padding: 0.25rem 0.75rem;
}
.event-raw summary:hover {
    color: var(--accent-dim);
}
.event-json {
    margin: 0 0.75rem 0.5rem;
    padding: 0.75rem;
    background: var(--code-bg);
    border-radius: 4px;
    font-family: var(--mono);
    font-size: 0.75rem;
    white-space: pre-wrap;
    overflow-x: auto;
    max-height: 500px;
    overflow-y: auto;
    color: var(--text-muted);
    line-height: 1.4;
}
.export-footer {
    margin-top: 3rem;
    padding-top: 1rem;
    border-top: 1px solid var(--border);
    color: var(--text-muted);
    font-size: 0.75rem;
    text-align: center;
}
/* Tool call header (no args) */
.tool-call-header {
    padding: 0.25rem 0;
    font-size: 0.85rem;
}
/* Tool call / tool result inside assistant message */
.tool-call-inline {
    padding: 0.35rem 0;
    margin: 0.25rem 0;
}
.tool-call-inline summary {
    cursor: pointer;
    font-size: 0.82rem;
}
/* Tool arguments / pretty JSON */
.tool-args {
    margin-top: 0.35rem;
    padding: 0 0.5rem 0.5rem;
}
.pretty-json {
    padding: 0.5rem 0.75rem;
    background: var(--code-bg);
    border-radius: 4px;
    font-family: var(--mono);
    font-size: 0.78rem;
    white-space: pre-wrap;
    overflow-x: auto;
    max-height: 400px;
    overflow-y: auto;
    color: var(--text-muted);
    line-height: 1.4;
    margin: 0;
}
/* Usage / token stats */
.usage-stats {
    padding: 0.35rem 0;
    font-size: 0.75rem;
    color: var(--text-muted);
}
.usage-label {
    font-weight: 600;
}
.usage-item {
    margin-left: 0.5rem;
    font-family: var(--mono);
}
/* Instruction block for long system/developer messages */
.instruction-block summary {
    cursor: pointer;
    font-size: 0.8rem;
    color: var(--accent-dim);
    font-weight: 600;
}
.instruction-block .message-content {
    max-height: 500px;
    overflow-y: auto;
    margin-top: 0.35rem;
}
/* Additional message role styles */
.message-system,
.message-developer,
.message-user-context { background: var(--surface); }
.message-user-context .message-role { color: var(--accent-dim); }
.message-human { background: var(--user-bg); }
.message-cancelled { background: transparent; color: var(--text-muted); font-style: italic; }
.message-command { background: var(--surface); }
/* Event card message override: preserve background for readability */
.event .message-system,
.event .message-developer,
.event .message-user-context,
.event .message-human,
.event .message-cancelled,
.event .message-command {
    background: transparent;
    border-bottom: 1px solid var(--border);
}
details[open] > summary { margin-bottom: 0.25rem; }
CSS;
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Parse the raw JSONL content into an array of event arrays, sorted by seq.
     *
     * @return list<array<string, mixed>>
     */
    private function parseEvents(string $content): array
    {
        $events = [];

        foreach (explode("\n", $content) as $line) {
            $trimmed = trim($line);
            if ('' === $trimmed) {
                continue;
            }

            try {
                $event = json_decode($trimmed, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                // Skip unparseable lines.
                continue;
            }

            if (!\is_array($event)) {
                continue;
            }

            $events[] = $event;
        }

        // Sort by seq (canonical order).
        usort($events, static function (array $a, array $b): int {
            $seqA = self::intFromArray($a, 'seq');
            $seqB = self::intFromArray($b, 'seq');

            return $seqA <=> $seqB;
        });

        return $events;
    }

    /*
     * Compute the session directory path from the resolved sessions base path.
     */
}
