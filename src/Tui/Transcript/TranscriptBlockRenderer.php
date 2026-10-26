<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;
use Symfony\Component\Yaml\Yaml;

/**
 * Non-tool block rendering for the transcript widget tree.
 *
 * Owns hidden/visible thinking and markdown paths, question transcript records,
 * system markdown / hotkey table / severity lifecycle lines, and the generic
 * flat TextWidget fallback (glyphs, theme colors, fallback display text).
 *
 * User / assistant / visible thinking → {@see MarkdownWidget}.
 * Hidden thinking → compact placeholder from {@see TranscriptDisplayConfig} only,
 * not {@see TranscriptBlock::$collapsed}.
 * Question blocks → compact markdown prompt/answer transcript record (HITL).
 * System blocks → severity/lifecycle prefix + color, optional markdown or
 * hotkey-table style. All other kinds → {@see TextWidget} flat line.
 *
 * ToolCall/ToolResult cards and tool exchanges are rendered by
 * {@see TranscriptToolRenderer}; pairing/suppression facts live in
 * {@see TranscriptToolPresentationPolicy}. This renderer never receives
 * subagent-supported, suppressed, or tool blocks (factory orchestrates).
 */
final readonly class TranscriptBlockRenderer
{
    public function __construct(
        private readonly TranscriptDisplayConfig $displayConfig,
        private readonly MarkdownRenderSupport $markdown = new MarkdownRenderSupport(),
        private readonly TranscriptDisplayState $displayState = new TranscriptDisplayState(),
    ) {
    }

    /**
     * Build a single widget for one non-tool/non-subagent transcript block.
     */
    public function buildWidget(TranscriptBlock $block, TuiTheme $theme): AbstractWidget
    {
        // Hidden thinking: compact placeholder; uses TranscriptDisplayConfig only, NOT TranscriptBlock::collapsed.
        if ($this->isThinkingBlock($block) && !$this->displayConfig->thinkingVisible) {
            $line = \sprintf('%s Thinking', TranscriptGlyphs::GLYPH_ASSISTANT_THINKING);

            return new TextWidget($theme->color(ThemeColorEnum::ThinkingText, $line));
        }

        // UserMessage, AssistantMessage, visible thinking → MarkdownWidget.
        if ($this->isMarkdownBlock($block)) {
            $body = $this->buildMarkdownWidget($block, $theme);

            return TranscriptBlockKindEnum::UserMessage === $block->kind && !$this->displayState->previewableBlocksExpanded
                ? new TranscriptBodyPreviewWidget($body, $theme)
                : $body;
        }

        // Question blocks: markdown prompt/answer transcript record (HITL), not generic TextWidget.
        if (TranscriptBlockKindEnum::Question === $block->kind) {
            return $this->buildQuestionWidget($block, $theme);
        }

        // Local slash-command Markdown (e.g. /settings-show) reuses MarkdownWidget.
        // Avoid prefixFor()/colorFor() which intentionally do not handle System.
        if (TranscriptBlockKindEnum::System === $block->kind && 'markdown' === ($block->meta['style'] ?? null)) {
            $mdWidget = $this->markdown->create($block->text);
            $colorSpec = $theme->getPalette()->get(ThemeColorEnum::SystemMessage);
            $style = '' !== $colorSpec
                ? new Style(color: $colorSpec, padding: Padding::from([0, 0, 0, 2]))
                : new Style(padding: Padding::from([0, 0, 0, 2]));
            $mdWidget->setStyle($style);

            return $mdWidget;
        }

        // Structured /hotkeys table — semantic widget, not pre-rendered ANSI text.
        if (TranscriptBlockKindEnum::System === $block->kind && 'hotkey-table' === ($block->meta['style'] ?? null)) {
            return $this->buildHotkeyTableWidget($block);
        }

        if (TranscriptBlockKindEnum::System === $block->kind) {
            $producer = $block->meta['producer_metadata'] ?? [];
            if (\is_array($producer) && (\is_array($producer['agent_communication'] ?? null) || \is_array($producer['background_completion'] ?? null))) {
                return $this->buildNotificationWidget($block, $theme, $producer);
            }

            return $this->buildSystemWidget($block, $theme);
        }

        // All remaining kinds → existing TextWidget path.
        $prefix = $this->prefixFor($block);
        $color = $this->colorFor($block);
        $displayText = $this->displayTextFor($block);
        $suffix = $block->streaming ? TranscriptGlyphs::STREAMING_SUFFIX : '';
        $line = \sprintf('%s %s%s', $prefix, $displayText, $suffix);

        return new TextWidget($theme->color($color, $line));
    }

    /**
     * Question transcript: compact glyph header, markdown prompt body, optional answer/status sections.
     *
     * Uses meta['prompt'] and meta['answer'] when present so answered blocks do not treat
     * the projection's appended " → answer" suffix as prompt markdown.
     */
    private function buildQuestionWidget(TranscriptBlock $block, TuiTheme $theme): AbstractWidget
    {
        $status = \is_string($block->meta['status'] ?? null) ? $block->meta['status'] : 'pending';
        $prompt = \is_string($block->meta['prompt'] ?? null) && '' !== $block->meta['prompt']
            ? $block->meta['prompt']
            : $this->questionPromptTextFromBlock($block);
        $answer = \is_string($block->meta['answer'] ?? null) ? $block->meta['answer'] : '';

        $container = new ContainerWidget();
        $header = $this->questionHeaderLine($status);
        $container->add(new TextWidget($theme->color(ThemeColorEnum::Accent, $header)));

        if ('' !== $prompt) {
            $container->add($this->buildInsetMarkdownWidget($prompt, $theme, ThemeColorEnum::Accent));
        }

        if ('answered' === $status && '' !== $answer) {
            $answerLine = '  → '.$answer;
            $container->add(new TextWidget($theme->color(ThemeColorEnum::UserMessage, $answerLine)));
        } elseif ('rejected' === $status) {
            $container->add(new TextWidget($theme->color(ThemeColorEnum::Error, '  (rejected)')));
        } elseif ('pending' === $status) {
            $container->add(new TextWidget($theme->muted('  … awaiting answer')));
        }

        return $container;
    }

    private function questionHeaderLine(string $status): string
    {
        return match ($status) {
            'answered' => \sprintf('%s Human input answered', TranscriptGlyphs::GLYPH_QUESTION),
            'rejected' => \sprintf('%s Human input rejected', TranscriptGlyphs::GLYPH_QUESTION),
            'cancelled' => \sprintf('%s Human input cancelled', TranscriptGlyphs::GLYPH_QUESTION),
            default => \sprintf('%s Human input required', TranscriptGlyphs::GLYPH_QUESTION),
        };
    }

    /**
     * Inset body without duplicating the glyph prefix inside markdown (CommonMark + glyph contract).
     * An omitted theme color leaves the native terminal foreground unchanged.
     */
    private function buildInsetMarkdownWidget(string $prompt, TuiTheme $theme, ThemeColorEnum $color, bool $dimItalic = false): MarkdownWidget
    {
        $mdWidget = $this->markdown->create($prompt);
        $colorSpec = $theme->getPalette()->get($color);
        $style = '' !== $colorSpec
            ? new Style(color: $colorSpec, padding: Padding::from([0, 0, 0, 2]))
            : new Style(padding: Padding::from([0, 0, 0, 2]));
        if ($dimItalic) {
            $style = $style->withDim(true)->withItalic(true);
        }
        $mdWidget->setStyle($style);

        return $mdWidget;
    }

    private function questionPromptTextFromBlock(TranscriptBlock $block): string
    {
        $text = $block->text;
        if ('' === $text) {
            return '';
        }

        // Answered projection appends " → {answer}" to block text; strip for prompt-only markdown.
        if (1 === preg_match('/^(.*) → /u', $text, $matches)) {
            return rtrim($matches[1]);
        }

        if (str_ends_with($text, ' (rejected)')) {
            return substr($text, 0, -\strlen(' (rejected)'));
        }

        return $text;
    }

    /** @param array<string, mixed> $producer */
    private function buildNotificationWidget(TranscriptBlock $block, TuiTheme $theme, array $producer): AbstractWidget
    {
        $agent = $producer['agent_communication'] ?? null;
        $body = $block->text;
        $technicalPrefix = '';
        $container = new ContainerWidget();
        if (\is_array($agent)) {
            $guidance = 'agent_steer' === ($agent['tool_name'] ?? null);
            $source = (string) ($agent['source_run_id'] ?? '');
            $title = 'Agent-authored · '.($guidance ? 'parent guidance' : 'child notice');
            // Only authenticated provenance authorizes replacing the host preamble.
            // A human quoting this text must remain an ordinary user message.
            $relationship = $guidance ? 'parent task owner (guidance)' : 'child (informational notice)';
            $preamble = \sprintf("Agent message from %s run %s, activity %s. This is agent-authored text, not a human request or runtime control.\n\n", $relationship, $source, $agent['lifecycle_id'] ?? '');
            if (str_starts_with($body, $preamble)) {
                $technicalPrefix = $preamble;
                $body = substr($body, \strlen($preamble));
            }
            $container->add(new TextWidget($theme->color(ThemeColorEnum::Accent, TranscriptGlyphs::GLYPH_SYSTEM_INFO.' '.$title).$theme->muted(' · '.StringUtils::stripControlBytes(substr($source, 0, 8)))));
        } else {
            $completion = $producer['background_completion'];
            $source = (string) ($completion['run_id'] ?? '');
            // Completion provenance does not assert success; keep the actual result visible.
            $container->add(new TextWidget($theme->color(ThemeColorEnum::ToolTitle, TranscriptGlyphs::GLYPH_TOOL.' code_mode · background result').$theme->muted(' · '.StringUtils::stripControlBytes(substr($source, 0, 8)))));
            $preamble = \sprintf("Background code_mode result (run_id: %s, tool_call_id: %s, invocation: %s).\n\n", $source, $completion['tool_call_id'] ?? '', $completion['invocation_id'] ?? '');
            if (str_starts_with($body, $preamble)) {
                $technicalPrefix = $preamble;
                $body = substr($body, \strlen($preamble));
            }
        }
        $markdown = $this->buildInsetMarkdownWidget($body, $theme, ThemeColorEnum::Muted, dimItalic: true);
        $container->add($this->displayState->previewableBlocksExpanded ? $markdown : new TranscriptBodyPreviewWidget($markdown, $theme, detailsHidden: true));
        if ($this->displayState->previewableBlocksExpanded) {
            $details = new TextWidget($theme->color(ThemeColorEnum::Dim, StringUtils::stripControlBytes($technicalPrefix.Yaml::dump($producer, 4, 2))));
            $details->setStyle(new Style(dim: true, italic: true, padding: Padding::from([0, 0, 0, 2])));
            $container->add($details);
        }

        return $container;
    }

    private function buildSystemWidget(TranscriptBlock $block, TuiTheme $theme): TextWidget
    {
        $prefix = $this->systemPrefixFor($block);
        $displayText = $this->displayTextFor($block);
        $suffix = $this->systemStreamingSuffix($block);
        $line = \sprintf('%s %s%s', $prefix, $displayText, $suffix);
        $color = $this->systemColorFor($block);

        return new TextWidget($theme->color($color, $line));
    }

    private function buildHotkeyTableWidget(TranscriptBlock $block): HotkeyTableWidget
    {
        $groups = $block->meta['hotkey_groups'] ?? [];
        if (!\is_array($groups)) {
            $groups = [];
        }

        /** @var array<string, list<array{keys: list<string>, action: string, description: string}>> $normalized */
        $normalized = [];
        foreach ($groups as $context => $bindings) {
            if (!\is_string($context) || !\is_array($bindings)) {
                continue;
            }
            $rows = [];
            foreach ($bindings as $binding) {
                if (!\is_array($binding)) {
                    continue;
                }
                $keys = $binding['keys'] ?? [];
                if (!\is_array($keys)) {
                    $keys = [];
                }
                $rows[] = [
                    'keys' => array_values(array_filter($keys, static fn (mixed $k): bool => \is_string($k))),
                    'action' => \is_string($binding['action'] ?? null) ? $binding['action'] : '',
                    'description' => \is_string($binding['description'] ?? null) ? $binding['description'] : '',
                ];
            }
            $normalized[$context] = $rows;
        }

        $emptyMessage = $block->meta['empty_message'] ?? '';
        if (!\is_string($emptyMessage)) {
            $emptyMessage = '';
        }

        return new HotkeyTableWidget($normalized, $emptyMessage);
    }

    private function systemStreamingSuffix(TranscriptBlock $block): string
    {
        return $block->streaming ? TranscriptGlyphs::STREAMING_SUFFIX : '';
    }

    private function systemPrefixFor(TranscriptBlock $block): string
    {
        $lifecycle = $block->meta['lifecycle'] ?? null;
        if ('compaction_started' === $lifecycle) {
            return TranscriptGlyphs::GLYPH_COMPACTION_STARTED;
        }
        if ('compaction_completed' === $lifecycle) {
            return TranscriptGlyphs::GLYPH_COMPACTION_COMPLETED;
        }

        return $this->severityPrefix($block);
    }

    private function systemColorFor(TranscriptBlock $block): ThemeColorEnum
    {
        if ('muted' === ($block->meta['style'] ?? null) || 'muted' === ($block->meta['severity'] ?? null)) {
            return ThemeColorEnum::Muted;
        }

        $lifecycle = $block->meta['lifecycle'] ?? null;
        if (\in_array($lifecycle, ['compaction_started', 'compaction_completed'], true)) {
            return ThemeColorEnum::Working;
        }

        return $this->severityColor($block);
    }

    // Glyph prefixes — TranscriptGlyphs constants are the public glyph contract for tests/assertions.
    private function prefixFor(TranscriptBlock $block): string
    {
        return match ($block->kind) {
            TranscriptBlockKindEnum::UserMessage => TranscriptGlyphs::GLYPH_USER_MESSAGE,
            TranscriptBlockKindEnum::AssistantMessage => TranscriptGlyphs::GLYPH_ASSISTANT_MESSAGE,
            TranscriptBlockKindEnum::AssistantThinking => TranscriptGlyphs::GLYPH_ASSISTANT_THINKING,
            TranscriptBlockKindEnum::ToolCall,
            TranscriptBlockKindEnum::ToolResult => TranscriptGlyphs::GLYPH_TOOL,
            TranscriptBlockKindEnum::Progress => TranscriptGlyphs::GLYPH_PROGRESS,
            TranscriptBlockKindEnum::Question => TranscriptGlyphs::GLYPH_QUESTION,
            TranscriptBlockKindEnum::Approval => TranscriptGlyphs::GLYPH_APPROVAL,
            TranscriptBlockKindEnum::Cancelled => TranscriptGlyphs::GLYPH_CANCELLED,
            TranscriptBlockKindEnum::Error => TranscriptGlyphs::GLYPH_ERROR,
            default => throw new \LogicException(\sprintf('Flat prefix path does not handle kind %s', $block->kind->value)),
        };
    }

    // Theme colors per block kind (flat TextWidget path and markdown base color).
    private function colorFor(TranscriptBlock $block): ThemeColorEnum
    {
        return match ($block->kind) {
            TranscriptBlockKindEnum::UserMessage => ThemeColorEnum::UserMessage,
            TranscriptBlockKindEnum::AssistantMessage => ThemeColorEnum::AssistantMessage,
            TranscriptBlockKindEnum::AssistantThinking => ThemeColorEnum::ThinkingText,
            TranscriptBlockKindEnum::ToolCall => ThemeColorEnum::Tool,
            TranscriptBlockKindEnum::ToolResult => ThemeColorEnum::ToolOutput,
            TranscriptBlockKindEnum::Progress,
            TranscriptBlockKindEnum::Cancelled => ThemeColorEnum::Muted,
            TranscriptBlockKindEnum::Question => ThemeColorEnum::Accent,
            TranscriptBlockKindEnum::Approval => ThemeColorEnum::Warning,
            TranscriptBlockKindEnum::Error => ThemeColorEnum::Error,
            default => throw new \LogicException(\sprintf('Flat color path does not handle kind %s', $block->kind->value)),
        };
    }

    // Display text when block->text is empty (meta fallbacks and kind placeholders).
    private function displayTextFor(TranscriptBlock $block): string
    {
        if ('' !== $block->text) {
            return $block->text;
        }

        return match ($block->kind) {
            TranscriptBlockKindEnum::ToolCall => $this->labelOr($block, 'tool_name', 'Tool call'),
            TranscriptBlockKindEnum::ToolResult => $this->labelOr($block, 'tool_name', 'Tool result'),
            TranscriptBlockKindEnum::AssistantMessage => '[assistant]',
            TranscriptBlockKindEnum::AssistantThinking => '[thinking]',
            TranscriptBlockKindEnum::Question => '[question]',
            TranscriptBlockKindEnum::Approval => '[approval]',
            TranscriptBlockKindEnum::Cancelled => '[cancelled]',
            TranscriptBlockKindEnum::Error => '[error]',
            TranscriptBlockKindEnum::Progress => '[progress]',
            default => '',
        };
    }

    // System block severity → glyph prefix.
    private function severityPrefix(TranscriptBlock $block): string
    {
        $severity = \is_string($block->meta['severity'] ?? null)
            ? $block->meta['severity']
            : null;

        return match ($severity) {
            'info' => TranscriptGlyphs::GLYPH_SYSTEM_INFO,
            'warning' => TranscriptGlyphs::GLYPH_SYSTEM_WARNING,
            'error' => TranscriptGlyphs::GLYPH_SYSTEM_ERROR,
            default => TranscriptGlyphs::GLYPH_SYSTEM_DEFAULT,
        };
    }

    // System block severity → theme color.
    private function severityColor(TranscriptBlock $block): ThemeColorEnum
    {
        $severity = \is_string($block->meta['severity'] ?? null)
            ? $block->meta['severity']
            : null;

        if ('muted' === ($block->meta['style'] ?? null)) {
            return ThemeColorEnum::Muted;
        }

        return match ($severity) {
            'warning' => ThemeColorEnum::Warning,
            'error' => ThemeColorEnum::Error,
            'muted' => ThemeColorEnum::Muted,
            default => ThemeColorEnum::SystemMessage,
        };
    }

    /**
     * Markdown block: glyph prepended into markdown source, streaming suffix preserved.
     *
     * Left padding on the widget replaces the flat renderer's two leading spaces because
     * CommonMark strips leading whitespace from paragraph text.
     */
    private function buildMarkdownWidget(TranscriptBlock $block, TuiTheme $theme): MarkdownWidget
    {
        $prefix = trim($this->prefixFor($block));
        $color = $this->colorFor($block);
        $displayText = $this->displayTextFor($block);
        $suffix = $block->streaming ? TranscriptGlyphs::STREAMING_SUFFIX : '';
        $text = \sprintf('%s %s%s', $prefix, $displayText, $suffix);
        $mdWidget = $this->markdown->create($text);
        $colorSpec = $theme->getPalette()->get($color);
        $style = '' !== $colorSpec
            ? new Style(color: $colorSpec, padding: Padding::from([0, 0, 0, 2]))
            : new Style(padding: Padding::from([0, 0, 0, 2]));

        if ($this->isThinkingBlock($block)) {
            $style = $this->applyThinkingStyle($style);
        } elseif (TranscriptBlockKindEnum::AssistantMessage === $block->kind && 'commentary' === ($block->meta['phase'] ?? null)) {
            $style = $style->withItalic(true);
        }

        $mdWidget->setStyle($style);

        return $mdWidget;
    }

    /**
     * Maps thinking.style config: dim_italic, dim, italic. Invalid values leave base style unchanged.
     */
    private function applyThinkingStyle(Style $style): Style
    {
        return match ($this->displayConfig->thinkingStyle) {
            'dim_italic' => $style->withDim(true)->withItalic(true),
            'dim' => $style->withDim(true),
            'italic' => $style->withItalic(true),
            default => $style,
        };
    }

    private function isThinkingBlock(TranscriptBlock $block): bool
    {
        return TranscriptBlockKindEnum::AssistantThinking === $block->kind;
    }

    private function isMarkdownBlock(TranscriptBlock $block): bool
    {
        return \in_array($block->kind, [
            TranscriptBlockKindEnum::UserMessage,
            TranscriptBlockKindEnum::AssistantMessage,
            TranscriptBlockKindEnum::AssistantThinking,
        ], true);
    }

    private function labelOr(TranscriptBlock $block, string $metaKey, string $default): string
    {
        $value = $block->meta[$metaKey] ?? '';

        return \is_string($value) && '' !== $value ? $value : $default;
    }
}
