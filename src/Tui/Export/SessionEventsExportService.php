<?php

declare(strict_types=1);

namespace Ineersa\Tui\Export;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Session\Export\EffectiveModelContextProjector;
use Ineersa\CodingAgent\Session\Export\EffectiveModelContextSnapshot;
use Ineersa\Tui\Command\TranscriptMessage;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Exports canonical session events as JSONL (byte copy) or HTML (effective model context).
 */
final class SessionEventsExportService
{
    public function __construct(
        private readonly EffectiveModelContextProjector $contextProjector,
        private readonly ?ToolboxInterface $toolbox = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

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
     * Generate a standalone HTML export of the effective current model context.
     */
    public function exportHtml(
        string $sessionId,
        string $eventsContent,
        string $outputPath,
        string $sessionName = '',
        string $sessionCwd = '',
        string $createdAt = '',
    ): TranscriptMessage {
        try {
            $snapshot = $this->contextProjector->project($eventsContent, $sessionId);
        } catch (\RuntimeException $exception) {
            $this->logger?->warning('Session HTML export failed to project model context.', [
                'component' => 'session_export',
                'event_type' => 'session_export.context_projection_failed',
                'session_id' => $sessionId,
                'error_class' => $exception::class,
                'error_message' => $exception->getMessage(),
            ]);

            return new TranscriptMessage($exception->getMessage(), 'error');
        }

        if ([] === $snapshot->messages && null === $snapshot->compaction) {
            return new TranscriptMessage(
                \sprintf('Session %s has no events to export.', $sessionId),
                'system',
                'muted',
            );
        }

        $displayName = '' !== $sessionName ? $sessionName : 'Session '.$sessionId;
        $title = self::escapeHtml($displayName);
        $html = $this->buildHtml($title, $sessionId, $sessionCwd, $createdAt, $snapshot);

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
     * @param array<string, mixed> $data
     */
    public static function strFromArray(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function intFromArray(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? null;

        return \is_int($value) ? $value : $default;
    }

    private function buildHtml(
        string $title,
        string $sessionId,
        string $cwd,
        string $createdAt,
        EffectiveModelContextSnapshot $snapshot,
    ): string {
        $bodyHtml = $this->renderContext($snapshot);

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
  <p class="session-subtitle">Effective model context (current prompt snapshot)</p>
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

    private function renderContext(EffectiveModelContextSnapshot $snapshot): string
    {
        $html = '';
        $toolDefinitionsHtml = $this->renderActiveToolDefinitions();
        $toolsPending = '' !== $toolDefinitionsHtml;

        if (null !== $snapshot->compaction) {
            $html .= $this->renderCompactionBanner($snapshot->compaction);
        }

        $html .= $this->renderAvailableToolsSnapshot(
            $snapshot->availableTools,
            $snapshot->availableToolsSchemaTokensEstimate,
        );

        foreach ($snapshot->messages as $message) {
            $html .= $this->renderMessage($message);

            if ($toolsPending && 'system' === $message->role) {
                $html .= $toolDefinitionsHtml;
                $toolsPending = false;
            }
        }

        if ($toolsPending) {
            $html = $toolDefinitionsHtml.$html;
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $compaction
     */
    private function renderCompactionBanner(array $compaction): string
    {
        $summaryText = self::strFromArray($compaction, 'summary_text');
        $trigger = self::strFromArray($compaction, 'trigger', 'unknown');
        $messagesCompacted = self::intFromArray($compaction, 'messages_compacted');
        $messagesRetained = self::intFromArray($compaction, 'messages_retained');
        $tokensBefore = self::intFromArray($compaction, 'estimated_tokens_before');
        $tokensAfter = self::intFromArray($compaction, 'estimated_tokens_after');
        $seq = self::intFromArray($compaction, 'seq');
        $hookMetadata = \is_array($compaction['hook_metadata'] ?? null) ? $compaction['hook_metadata'] : null;

        $metaParts = [];
        if ('' !== $trigger) {
            $metaParts[] = 'trigger: '.$trigger;
        }
        if ($seq > 0) {
            $metaParts[] = 'seq '.$seq;
        }
        if ($messagesCompacted > 0) {
            $metaParts[] = number_format($messagesCompacted).' messages compacted';
        }
        if ($messagesRetained > 0) {
            $metaParts[] = number_format($messagesRetained).' retained';
        }
        if ($tokensBefore > 0 || $tokensAfter > 0) {
            $metaParts[] = number_format($tokensBefore).' → '.number_format($tokensAfter).' tokens';
        }

        $html = '  <section class="compaction-banner">'."\n";
        $html .= '    <div class="compaction-label">Compaction checkpoint</div>'."\n";
        if ([] !== $metaParts) {
            $html .= '    <div class="compaction-meta">'.self::escapeHtml(implode(' · ', $metaParts))."</div>\n";
        }

        if (null !== $hookMetadata && [] !== $hookMetadata) {
            $source = self::strFromArray($hookMetadata, 'om_source');
            $projection = self::strFromArray($hookMetadata, 'om_projection');
            $html .= '    <div class="compaction-om">'."\n";
            $html .= '      <div class="compaction-om-label">Observational memory</div>'."\n";
            if ('' !== $source || '' !== $projection) {
                $bits = array_filter([$source, $projection], static fn (string $value): bool => '' !== $value);
                $html .= '      <div class="compaction-om-meta">'.self::escapeHtml(implode(' · ', $bits))."</div>\n";
            }
            $html .= '      <pre class="pretty-json">'.self::escapeHtml((string) json_encode(
                $hookMetadata,
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            ))."</pre>\n";
            $html .= "    </div>\n";
        }

        if ('' !== $summaryText) {
            $html .= '    <details class="compaction-summary" open>'."\n";
            $html .= '      <summary>'.self::escapeHtml(\sprintf(
                'OM / compaction summary (%s chars)',
                number_format(mb_strlen($summaryText)),
            ))."</summary>\n";
            $html .= '      <div class="message-content">'.self::escapeHtml($summaryText)."</div>\n";
            $html .= "    </details>\n";
        } else {
            $html .= '    <div class="compaction-missing">Compaction checkpoint present, but summary_text is missing.</div>'."\n";
        }

        $html .= "  </section>\n";

        return $html;
    }

    /**
     * @param list<string>|null $tools
     */
    private function renderAvailableToolsSnapshot(?array $tools, ?int $estimate): string
    {
        if (null === $tools || [] === $tools) {
            return '';
        }

        $items = [];
        foreach ($tools as $entry) {
            if ('' === $entry) {
                continue;
            }
            $items[] = self::escapeHtml($entry);
        }
        if ([] === $items) {
            return '';
        }

        $estimateInt = $estimate ?? 0;
        $summary = \sprintf(
            'Available tools (%d · ~%s schema tokens)',
            \count($items),
            number_format($estimateInt),
        );

        $html = '  <details class="available-tools" open>'."\n";
        $html .= '    <summary>'.self::escapeHtml($summary)."</summary>\n";
        $html .= "    <ul class=\"available-tools-list\">\n";
        foreach ($items as $item) {
            $html .= '      <li>'.$item."</li>\n";
        }
        $html .= "    </ul>\n";
        $html .= "  </details>\n";

        return $html;
    }

    private function renderMessage(AgentMessage $message): string
    {
        $role = $message->role;
        $isCompactSummary = true === ($message->metadata['compact_summary'] ?? false);
        $text = $this->extractTextFromContentBlocks($message->content);
        $cssRole = self::escapeHtml($role);
        if ($isCompactSummary) {
            $cssRole .= ' message-compact-summary';
        }

        $html = '  <div class="message message-'.$cssRole.'">'."\n";
        $html .= '    <div class="message-role">'.self::escapeHtml($isCompactSummary ? 'compaction summary' : $role)."</div>\n";

        if ($isCompactSummary) {
            $html .= '    <div class="compact-summary-badge">OM-backed compaction summary in model context</div>'."\n";
        }

        if ('' !== $text) {
            $contentLen = mb_strlen($text);
            if ($contentLen > 500 && \in_array($role, ['system', 'developer', 'user-context'], true)) {
                $label = match ($role) {
                    'system' => 'System instructions',
                    'developer' => 'Developer instructions',
                    default => 'Context',
                };
                $html .= '    <details class="instruction-block" open>'."\n";
                $html .= '      <summary>'.self::escapeHtml($label).' ('.number_format($contentLen).' chars)</summary>'."\n";
                $html .= '      <div class="message-content">'.self::escapeHtml($text)."</div>\n";
                $html .= "    </details>\n";
            } elseif ($isCompactSummary) {
                $html .= '    <details class="compaction-in-context" open>'."\n";
                $html .= '      <summary>'.self::escapeHtml(\sprintf('Summary in context (%s chars)', number_format($contentLen)))."</summary>\n";
                $html .= '      <div class="message-content">'.self::escapeHtml($text)."</div>\n";
                $html .= "    </details>\n";
            } else {
                $html .= '    <div class="message-content">'.self::escapeHtml($text)."</div>\n";
            }
        }

        $thinking = '';
        if (\is_array($message->details) && \is_string($message->details['thinking'] ?? null)) {
            $thinking = $message->details['thinking'];
        }
        if ('' !== $thinking) {
            $html .= '    <details class="thinking-block" open>'."\n";
            $html .= '      <summary>Thinking</summary>'."\n";
            $html .= '      <div class="thinking-content">'.self::escapeHtml($thinking)."</div>\n";
            $html .= "    </details>\n";
        }

        $toolCalls = $message->metadata['tool_calls'] ?? null;
        if (\is_array($toolCalls) && [] !== $toolCalls) {
            $html .= $this->renderToolCalls($toolCalls);
        }

        if ('tool' === $role) {
            $meta = [];
            if (null !== $message->toolName && '' !== $message->toolName) {
                $meta[] = 'tool: '.$message->toolName;
            }
            if (null !== $message->toolCallId && '' !== $message->toolCallId) {
                $meta[] = 'id: '.$message->toolCallId;
            }
            if ($message->isError) {
                $meta[] = 'error';
            }
            if ([] !== $meta) {
                $html .= '    <div class="message-meta">'.self::escapeHtml(implode(' · ', $meta))."</div>\n";
            }
        }

        $html .= "  </div>\n";

        return $html;
    }

    private function renderActiveToolDefinitions(): string
    {
        if (null === $this->toolbox) {
            return '';
        }

        /** @var list<Tool> $tools */
        $tools = $this->toolbox->getTools();
        if ([] === $tools) {
            return '';
        }

        $count = \count($tools);
        $html = '  <details class="tool-definitions" open>'."\n";
        $html .= '    <summary>'.self::escapeHtml(\sprintf('Tool definitions (%d)', $count))."</summary>\n";

        foreach ($tools as $tool) {
            $name = self::escapeHtml($tool->getName());
            $description = self::escapeHtml($tool->getDescription());
            $parameters = $tool->getParameters() ?? new \stdClass();
            $json = json_encode($parameters, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            if (!\is_string($json)) {
                $json = '{}';
            }

            $html .= '    <div class="tool-definition">'."\n";
            $html .= '      <div class="tool-definition-name">'.$name."</div>\n";
            $html .= '      <div class="tool-definition-description">'.$description."</div>\n";
            $html .= '      <pre class="pretty-json">'.self::escapeHtml($json)."</pre>\n";
            $html .= "    </div>\n";
        }

        $html .= "  </details>\n";

        return $html;
    }

    /**
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
     * @param array<int, mixed> $toolCalls
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
                $html .= '        <div class="tool-args">'.$this->renderPrettyJson($tcArgs)."</div>\n";
            }

            $html .= "      </details>\n";
            $html .= "    </div>\n";
        }

        return $html;
    }

    private function renderPrettyJson(mixed $value): string
    {
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

    private function exportCss(): string
    {
        return <<<'CSS'
/* Hatfield Session Export — effective model context */
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
    --compaction-bg: #2a2140;
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
.session-meta, .session-subtitle {
    color: var(--text-muted);
    font-size: 0.85rem;
}
.session-subtitle { margin-top: 0.5rem; }
.message {
    padding: 0.75rem 1rem;
    border: 1px solid var(--border);
    border-radius: 8px;
    margin-bottom: 0.75rem;
}
.message-role {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    margin-bottom: 0.35rem;
}
.message-user { background: var(--user-bg); }
.message-assistant { background: var(--assistant-bg); }
.message-tool { background: var(--tool-bg); }
.message-system,
.message-developer,
.message-user-context { background: var(--surface); }
.message-user-context .message-role { color: var(--accent-dim); }
.message-compact-summary { background: var(--compaction-bg); border-color: var(--accent-dim); }
.compact-summary-badge {
    display: inline-block;
    margin-bottom: 0.5rem;
    padding: 0.15rem 0.5rem;
    border-radius: 999px;
    background: var(--surface-alt);
    color: var(--accent);
    font-size: 0.72rem;
    font-weight: 600;
}
.message-content {
    white-space: pre-wrap;
    word-break: break-word;
}
.message-meta {
    font-size: 0.7rem;
    color: var(--text-muted);
    margin-top: 0.5rem;
}
.thinking-block { margin: 0.5rem 0; }
.thinking-block summary,
.instruction-block summary,
.compaction-summary summary,
.compaction-in-context summary {
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
.compaction-banner {
    margin-bottom: 1rem;
    padding: 0.85rem 1rem;
    border: 1px solid var(--accent-dim);
    border-radius: 8px;
    background: var(--compaction-bg);
}
.compaction-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--accent);
    font-weight: 700;
}
.compaction-meta, .compaction-om-meta, .compaction-missing {
    margin-top: 0.35rem;
    color: var(--text-muted);
    font-size: 0.82rem;
}
.compaction-om { margin-top: 0.75rem; }
.compaction-om-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--accent-dim);
    margin-bottom: 0.25rem;
}
.available-tools, .tool-definitions {
    margin: 0 0 0.75rem;
    padding: 0.5rem 0.75rem;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 0.85rem;
}
.available-tools summary, .tool-definitions summary {
    cursor: pointer;
    color: var(--text-muted);
    font-weight: 600;
}
.available-tools-list {
    margin: 0.4rem 0 0 1.1rem;
    color: var(--text);
}
.tool-definition {
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px solid var(--border);
}
.tool-definition:first-of-type {
    margin-top: 0.5rem;
    padding-top: 0;
    border-top: none;
}
.tool-definition-name {
    font-family: var(--mono);
    font-weight: 600;
    color: var(--accent);
}
.tool-definition-description {
    margin: 0.35rem 0 0.5rem;
    color: var(--text);
    white-space: pre-wrap;
}
.tool-call-inline {
    padding: 0.35rem 0;
    margin: 0.25rem 0;
}
.tool-call-inline summary { cursor: pointer; font-size: 0.82rem; }
.tool-name {
    color: var(--accent);
    font-family: var(--mono);
    font-size: 0.82rem;
}
.tool-call-id { color: var(--text-muted); font-size: 0.72rem; }
.tool-args { margin-top: 0.35rem; padding: 0 0.5rem 0.5rem; }
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
    margin: 0.35rem 0 0;
}
.instruction-block .message-content {
    max-height: 500px;
    overflow-y: auto;
    margin-top: 0.35rem;
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
}
