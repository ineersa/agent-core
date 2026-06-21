<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Runtime\TuiSessionState;

/**
 * Handles the /export (aliases: /exp) slash command.
 *
 * Exports the current session to a standalone HTML file (default) or
 * a JSONL copy of the canonical events.jsonl when the output path
 * ends in .jsonl.
 *
 * Path argument parsing follows pi-mono conventions:
 *  - No args → default HTML filename in cwd.
 *  - Quoted paths (single/double) preserve internal spaces.
 *  - Unquoted paths stop at the first whitespace char.
 *  - Malformed quotes produce a friendly error message.
 *
 * HTML export reads the canonical events.jsonl and renders a
 * standalone, browser-viewable transcript with inline CSS.
 * All untrusted content is escaped.
 *
 * @internal Registered by ExportCommandRegistrar
 */
final class ExportCommandHandler implements SlashCommandHandler
{
    public function __construct(
        private readonly TuiSessionState $state,
        private readonly HatfieldSessionStore $sessionStore,
    ) {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        try {
            return $this->doHandle($command);
        } catch (\Throwable $e) {
            return new TranscriptMessage(
                \sprintf('Failed to export session: %s', $e->getMessage()),
                'error',
            );
        }
    }

    /**
     * Escape a string for safe HTML inclusion.
     *
     * Converts &, <, >, ", ' to entity references.
     */
    public static function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    private function doHandle(SlashCommand $command): CommandResult
    {
        $sessionId = $this->state->sessionId;
        if ('' === $sessionId) {
            return new TranscriptMessage(
                'No active session — start a conversation first.',
                'system',
                'muted',
            );
        }

        $sessionDir = $this->resolveSessionDir($sessionId);
        $eventsPath = $sessionDir.'/events.jsonl';

        if (!is_file($eventsPath) || !is_readable($eventsPath)) {
            return new TranscriptMessage(
                \sprintf('Session %s has no events to export.', $sessionId),
                'system',
                'muted',
            );
        }

        $eventsContent = file_get_contents($eventsPath);
        if (false === $eventsContent || '' === trim($eventsContent)) {
            return new TranscriptMessage(
                \sprintf('Session %s has no events to export.', $sessionId),
                'system',
                'muted',
            );
        }

        // Parse args to determine output path.
        $parseResult = $this->parsePathArg($command->args);
        if (null === $parseResult) {
            // Default: HTML in cwd.
            $outputPath = getcwd().'/hatfield-session-'.$sessionId.'.html';
        } elseif (false === $parseResult) {
            // Malformed quoted path.
            return new TranscriptMessage(
                'Malformed path — if using quotes, the path must have matching opening and closing quotes.',
                'error',
            );
        } else {
            $outputPath = $parseResult;
        }

        // Resolve relative paths against cwd.
        if (!str_starts_with($outputPath, '/')) {
            $outputPath = getcwd().'/'.$outputPath;
        }

        // JSONL export: copy canonical events.
        if (str_ends_with($outputPath, '.jsonl')) {
            return $this->exportJsonl($eventsPath, $outputPath);
        }

        // HTML export (default).
        return $this->exportHtml($sessionId, $eventsContent, $outputPath);
    }

    // ── Path parsing ──────────────────────────────────────────────────────

    /**
     * Parse the args string from a slash command into an optional output path.
     *
     * Rules (pi-mono-compatible, with explicit error for malformed quotes):
     *  - Empty args → null (use default).
     *  - Single- or double-quoted → entire quoted content is the path.
     *  - Unquoted → first whitespace-delimited token.
     *  - Malformed quotes (opening quote with no closing) → false.
     *
     * Returns null when no path was supplied; the caller uses a default.
     * Returns false when the quoted path argument is malformed.
     */
    private function parsePathArg(string $args): string|false|null
    {
        $trimmed = trim($args);
        if ('' === $trimmed) {
            return null;
        }

        $firstChar = $trimmed[0];
        if ("'" === $firstChar || '"' === $firstChar) {
            $closingPos = strpos($trimmed, $firstChar, 1);
            if (false === $closingPos) {
                // Malformed quote — report error per task spec.
                return false;
            }

            $path = substr($trimmed, 1, $closingPos - 1);
            if ('' === $path) {
                return null;
            }

            return $path;
        }

        // Unquoted: take until first whitespace.
        $spacePos = strpos($trimmed, ' ');
        if (false === $spacePos) {
            return $trimmed;
        }

        return substr($trimmed, 0, $spacePos);
    }

    // ── Export methods ─────────────────────────────────────────────────────

    /**
     * Copy the canonical events.jsonl to the requested path.
     */
    private function exportJsonl(string $sourcePath, string $outputPath): CommandResult
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
    private function exportHtml(string $sessionId, string $eventsContent, string $outputPath): CommandResult
    {
        $events = $this->parseEvents($eventsContent);
        if ([] === $events) {
            return new TranscriptMessage(
                \sprintf('Session %s has no events to export.', $sessionId),
                'system',
                'muted',
            );
        }

        // Read session metadata for the header.
        /** @var array<string, mixed> $metadata */
        $metadata = $this->sessionStore->loadMetadata($sessionId) ?? [];
        $sessionName = self::strFromArray($metadata, 'name', 'Session '.$sessionId);
        $sessionCwd = self::strFromArray($metadata, 'cwd');
        $createdAt = self::strFromArray($metadata, 'created_at');

        $title = self::escapeHtml($sessionName);
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

        // Track tool names across tool_execution_start/end pairs.
        $toolNames = [];

        foreach ($events as $event) {
            $type = self::strFromArray($event, 'type');
            $payload = \is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $turnNo = self::intFromArray($event, 'turn_no');

            // Track tool names for cross-referencing.
            if ('tool_execution_start' === $type) {
                $tcId = self::strFromArray($payload, 'tool_call_id');
                $tcName = self::strFromArray($payload, 'tool_name');
                if ('' !== $tcId && '' !== $tcName) {
                    $toolNames[$tcId] = $tcName;
                }
            }

            // Start a new turn group when the turn number changes.
            if ($turnNo !== $currentTurn) {
                if ($currentTurn >= 0) {
                    $html .= "</div>\n"; // Close previous turn.
                }
                $currentTurn = $turnNo;
                $html .= '<div class="turn">'."\n";
                $html .= '  <div class="turn-label">Turn '.$turnNo.'</div>'."\n";
            }

            $html .= $this->renderEvent($event, $toolNames);
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
     */
    private function renderEvent(array $event, array $toolNames = []): string
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
            'tool_execution_start' => $this->renderToolStart($payload),
            'tool_execution_end' => $this->renderToolEnd($payload, $toolNames),
            'agent_end' => $this->renderAgentEnd($payload),
            default => '',
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
     * Render the run_started event: extract user messages.
     *
     * @param array<string, mixed> $payload
     */
    private function renderRunStarted(array $payload): string
    {
        $html = '';
        $userMessages = $payload['user_messages'] ?? [];

        if (\is_array($userMessages)) {
            foreach ($userMessages as $msg) {
                if (!\is_array($msg)) {
                    continue;
                }
                /** @var array<string, mixed> $msg */
                $role = self::escapeHtml(self::strFromArray($msg, 'role', 'user'));
                $content = self::escapeHtml(self::strFromArray($msg, 'content'));
                $html .= '  <div class="message message-'.$role.'">'."\n";
                $html .= '    <div class="message-role">'.$role.'</div>'."\n";
                $html .= '    <div class="message-content">'.$content.'</div>'."\n";
                $html .= "  </div>\n";
            }
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderAssistantMessage(array $payload): string
    {
        $text = self::escapeHtml(self::strFromArray($payload, 'text'));
        $stopReason = self::escapeHtml(self::strFromArray($payload, 'stop_reason'));
        $thinking = self::escapeHtml(self::strFromNested($payload, ['details', 'thinking']));

        $html = '  <div class="message message-assistant">'."\n";
        $html .= '    <div class="message-role">assistant</div>'."\n";

        if ('' !== $thinking) {
            $html .= '    <details class="thinking-block">'."\n";
            $html .= '      <summary>Thinking</summary>'."\n";
            $html .= '      <div class="thinking-content">'.$thinking.'</div>'."\n";
            $html .= "    </details>\n";
        }

        if ('' !== $text) {
            $html .= '    <div class="message-content">'.$text.'</div>'."\n";
        }

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
     * @param array<string, mixed> $payload
     */
    private function renderToolStart(array $payload): string
    {
        $toolName = self::escapeHtml(self::strFromArray($payload, 'tool_name', 'unknown'));
        $toolCallId = self::escapeHtml(self::strFromArray($payload, 'tool_call_id'));

        $html = '  <div class="tool-call">'."\n";
        $html .= '    <details>'."\n";
        $html .= '      <summary><span class="tool-name">'.$toolName.'</span>';

        if ('' !== $toolCallId) {
            $html .= ' <span class="tool-call-id">'.$toolCallId.'</span>';
        }

        $html .= "</summary>\n";
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

    /**
     * Safely extract a string value from an array with a default.
     *
     * @param array<string, mixed> $data
     */
    private static function strFromArray(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) ? $value : $default;
    }

    /**
     * Safely extract an int value from an array with a default.
     *
     * @param array<string, mixed> $data
     */
    private static function intFromArray(array $data, string $key, int $default = 0): int
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
    private static function strFromNested(array $data, array $keys, string $default = ''): string
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

    /**
     * Compute the session directory path from the resolved sessions base path.
     */
    private function resolveSessionDir(string $sessionId): string
    {
        // Use resolveSessionsBasePath() — the public API of HatfieldSessionStore.
        // getSessionDir() is private, so we compute it ourselves.
        return $this->sessionStore->resolveSessionsBasePath().'/'.$sessionId;
    }
}
