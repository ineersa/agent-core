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

/**
 * Centralizes block-kind-specific rendering for the transcript widget tree.
 *
 * Responsibilities include glyphs, theme colors, fallback display text, system severity,
 * markdown/thinking paths, and compact tool cards.
 *
 * User / assistant / visible thinking → {@see MarkdownWidget}.
 * Hidden thinking → compact placeholder from {@see TranscriptDisplayConfig} only,
 * not {@see TranscriptBlock::$collapsed}.
 * {@see TranscriptBlockKindEnum::ToolCall} and normal {@see TranscriptBlockKindEnum::ToolResult}
 * → compact multi-line cards (YAML-like args with preview; edit/write payload previews; preview-truncated result body).
 * Structured subagent tool results are delegated to {@see SubagentResultRenderer} before generic
 * ToolResult cards. All other kinds → {@see TextWidget} flat line.
 *
 * Tool-exchange pairing/suppression and shared tool-result presentation facts live in
 * {@see TranscriptToolPresentationPolicy}; tool-call/result/exchange rendering is
 * delegated to {@see TranscriptToolRenderer}. This factory renders the remaining kinds.
 */
final readonly class TranscriptBlockWidgetFactory
{
    private readonly TranscriptToolPresentationPolicy $toolPresentationPolicy;

    private readonly TranscriptToolRenderer $toolRenderer;

    public function __construct(
        private readonly SubagentResultRenderer $subagentRenderer = new SubagentResultRenderer(),
        private readonly TranscriptDisplayConfig $displayConfig = new TranscriptDisplayConfig(),
        private readonly TranscriptDisplayState $displayState = new TranscriptDisplayState(),
        private readonly EditToolCallDiffRenderer $editDiffRenderer = new EditToolCallDiffRenderer(),
        private readonly WriteToolCallContentRenderer $writeContentRenderer = new WriteToolCallContentRenderer(),
        private readonly TranscriptLinePreviewService $linePreviewService = new TranscriptLinePreviewService(),
        private readonly ToolArgumentColoredFormatter $toolArgumentColoredFormatter = new ToolArgumentColoredFormatter(),
        private readonly ViewImageTranscriptFormatter $viewImageFormatter = new ViewImageTranscriptFormatter(),
    ) {
        $this->toolPresentationPolicy = new TranscriptToolPresentationPolicy($this->subagentRenderer);
        $this->toolRenderer = new TranscriptToolRenderer(
            $this->displayConfig,
            $this->displayState,
            $this->editDiffRenderer,
            $this->writeContentRenderer,
            $this->linePreviewService,
            $this->toolArgumentColoredFormatter,
            $this->viewImageFormatter,
            $this->toolPresentationPolicy,
        );
    }

    public function displayConfig(): TranscriptDisplayConfig
    {
        return $this->displayConfig;
    }

    public function displayState(): TranscriptDisplayState
    {
        return $this->displayState;
    }

    public function subagentRenderer(): SubagentResultRenderer
    {
        return $this->subagentRenderer;
    }

    public function isTranscriptWidgetSuppressed(TranscriptBlock $block): bool
    {
        return $this->toolPresentationPolicy->isTranscriptWidgetSuppressed($block);
    }

    /**
     * Build a single widget for one transcript block.
     */
    public function buildWidget(TranscriptBlock $block, TuiTheme $theme): AbstractWidget
    {
        // Structured subagent result blocks stay on the dedicated renderer before generic ToolResult cards.
        if ($this->subagentRenderer->supports($block)) {
            return $this->subagentRenderer->buildWidget($block, $theme);
        }

        // ask_human HITL: Question block is authoritative; suppress duplicate tool cards (single-block render path).
        if ($this->isTranscriptWidgetSuppressed($block)) {
            return new TextWidget('');
        }

        // Hidden thinking: compact placeholder; uses TranscriptDisplayConfig only, NOT TranscriptBlock::collapsed.
        if ($this->isThinkingBlock($block) && !$this->displayConfig->thinkingVisible) {
            $line = \sprintf('%s Thinking', TranscriptGlyphs::GLYPH_ASSISTANT_THINKING);

            return new TextWidget($theme->color(ThemeColorEnum::ThinkingText, $line));
        }

        // UserMessage, AssistantMessage, visible thinking → MarkdownWidget.
        if ($this->isMarkdownBlock($block)) {
            return $this->buildMarkdownWidget($block, $theme);
        }

        // Question blocks: markdown prompt/answer transcript record (HITL), not generic TextWidget.
        if (TranscriptBlockKindEnum::Question === $block->kind) {
            return $this->buildQuestionWidget($block, $theme);
        }

        // Local slash-command Markdown (e.g. /settings-show) reuses MarkdownWidget.
        // Avoid prefixFor()/colorFor() which intentionally do not handle System.
        if (TranscriptBlockKindEnum::System === $block->kind && 'markdown' === ($block->meta['style'] ?? null)) {
            $mdWidget = new MarkdownWidget($block->text);
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

        // RENDER-04: ToolCall → compact card (glyph header, YAML-like args, arg preview).
        if (TranscriptBlockKindEnum::ToolCall === $block->kind) {
            return $this->toolRenderer->buildToolCallWidget($block, $theme);
        }

        // RENDER-04: normal ToolResult → compact card (header, body preview unless error/cancel/timeout).
        if (TranscriptBlockKindEnum::ToolResult === $block->kind) {
            return $this->toolRenderer->buildToolResultWidget($block, $theme);
        }

        if (TranscriptBlockKindEnum::System === $block->kind) {
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
     * Visual transcript collapse: render ToolCall + matching ToolResult as one compact card.
     *
     * Canonical projection still stores separate blocks; list assembly in
     * {@see TranscriptMountedWidget}
     * pairs by tool_call_id and skips the standalone ToolResult row when consumed here.
     */
    public function buildToolExchangeWidget(TranscriptBlock $callBlock, TranscriptBlock $resultBlock, TuiTheme $theme): AbstractWidget
    {
        if ($this->subagentRenderer->supports($resultBlock)) {
            return $this->buildWidget($callBlock, $theme);
        }

        return $this->toolRenderer->buildToolExchangeWidget($callBlock, $resultBlock, $theme);
    }

    /**
     * @param array<string, list<TranscriptBlock>> $toolResultsByCallId
     * @param array<string, true>                  $consumedToolResultIds
     * @param array<string, true>                  $consumedToolCallIds
     */
    public function findCombinableToolResultForCall(
        TranscriptBlock $callBlock,
        array $toolResultsByCallId,
        array $consumedToolResultIds,
        array $consumedToolCallIds,
    ): ?TranscriptBlock {
        return $this->toolPresentationPolicy->findCombinableToolResultForCall(
            $callBlock,
            $toolResultsByCallId,
            $consumedToolResultIds,
            $consumedToolCallIds,
        );
    }

    /**
     * @param array<string, true> $consumedToolCallIds
     */
    public function shouldSkipStandaloneToolResultInList(
        TranscriptBlock $block,
        array $consumedToolCallIds,
    ): bool {
        return $this->toolPresentationPolicy->shouldSkipStandaloneToolResultInList($block, $consumedToolCallIds);
    }

    /**
     * @param array<string, true> $consumedToolResultIds
     * @param array<string, true> $consumedToolCallIds
     */
    public function markToolResultConsumedForExchange(
        TranscriptBlock $resultBlock,
        array &$consumedToolResultIds,
        array &$consumedToolCallIds,
    ): void {
        $this->toolPresentationPolicy->markToolResultConsumedForExchange(
            $resultBlock,
            $consumedToolResultIds,
            $consumedToolCallIds,
        );
    }

    /**
     * ask_human often leaves an empty assistant markdown placeholder immediately before the Question block.
     */
    public function shouldSuppressEmptyAssistantPlaceholder(TranscriptBlock $block, ?TranscriptBlock $nextBlock): bool
    {
        return $this->toolPresentationPolicy->shouldSuppressEmptyAssistantPlaceholder($block, $nextBlock);
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
            $container->add($this->buildQuestionMarkdownWidget($prompt, $theme, ThemeColorEnum::Accent));
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
            default => \sprintf('%s Human input required', TranscriptGlyphs::GLYPH_QUESTION),
        };
    }

    /**
     * Prompt body without duplicating the glyph prefix inside markdown (CommonMark + glyph contract).
     */
    private function buildQuestionMarkdownWidget(string $prompt, TuiTheme $theme, ThemeColorEnum $color): MarkdownWidget
    {
        $mdWidget = new MarkdownWidget($prompt);
        $colorSpec = $theme->getPalette()->get($color);
        $style = '' !== $colorSpec
            ? new Style(color: $colorSpec, padding: Padding::from([0, 0, 0, 2]))
            : new Style(padding: Padding::from([0, 0, 0, 2]));
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
        $mdWidget = new MarkdownWidget($text);
        $colorSpec = $theme->getPalette()->get($color);
        $style = '' !== $colorSpec
            ? new Style(color: $colorSpec, padding: Padding::from([0, 0, 0, 2]))
            : new Style(padding: Padding::from([0, 0, 0, 2]));

        if ($this->isThinkingBlock($block)) {
            $style = $this->applyThinkingStyle($style);
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
