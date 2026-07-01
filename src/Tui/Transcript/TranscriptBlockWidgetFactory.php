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
use Symfony\Component\Yaml\Yaml;

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
 */
final readonly class TranscriptBlockWidgetFactory
{
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
    }

    public function displayConfig(): TranscriptDisplayConfig
    {
        return $this->displayConfig;
    }

    public function displayState(): TranscriptDisplayState
    {
        return $this->displayState;
    }

    public function isTranscriptWidgetSuppressed(TranscriptBlock $block): bool
    {
        return $this->shouldSuppressTranscriptWidget($block);
    }

    /**
     * Build a single widget for one transcript block.
     */
    public function buildWidget(TranscriptBlock $block, TuiTheme $theme): AbstractWidget
    {
        // Structured subagent result blocks stay on the dedicated renderer before generic ToolResult cards.
        if ($this->subagentRenderer->supports($block)) {
            return new TextWidget($this->subagentRenderer->buildContent($block, $theme));
        }

        // ask_human HITL: Question block is authoritative; suppress duplicate tool cards (single-block render path).
        if ($this->shouldSuppressTranscriptWidget($block)) {
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

        // RENDER-04: ToolCall → compact card (glyph header, YAML-like args, arg preview).
        if (TranscriptBlockKindEnum::ToolCall === $block->kind) {
            return $this->buildToolCallWidget($block, $theme);
        }

        // RENDER-04: normal ToolResult → compact card (header, body preview unless error/cancel/timeout).
        if (TranscriptBlockKindEnum::ToolResult === $block->kind) {
            return $this->buildToolResultWidget($block, $theme);
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
     * ask_human often leaves an empty assistant markdown placeholder immediately before the Question block.
     */
    public function shouldSuppressEmptyAssistantPlaceholder(TranscriptBlock $block, ?TranscriptBlock $nextBlock): bool
    {
        if (TranscriptBlockKindEnum::AssistantMessage !== $block->kind) {
            return false;
        }

        if ('' !== $block->text) {
            return false;
        }

        return null !== $nextBlock && TranscriptBlockKindEnum::Question === $nextBlock->kind;
    }

    /**
     * ask_human HITL: Question block is the authoritative transcript record; hide duplicate tool cards.
     *
     * Projection typically emits ToolCall/ToolResult before the Question block in the same poll batch;
     * a one-tick gap with only suppressed cards is acceptable and preferable to flashing raw payloads.
     */
    private function shouldSuppressTranscriptWidget(TranscriptBlock $block): bool
    {
        if (TranscriptBlockKindEnum::ToolCall === $block->kind && $this->isAskHumanToolName($block->meta['tool_name'] ?? null)) {
            return true;
        }

        if (TranscriptBlockKindEnum::ToolResult === $block->kind
            && $this->isAskHumanToolName($block->meta['tool_name'] ?? null)
            && !$this->toolResultIsFullRender($block)) {
            return true;
        }

        return false;
    }

    private function isAskHumanToolName(mixed $toolName): bool
    {
        return \is_string($toolName) && 'ask_human' === $toolName;
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

    private function buildToolCallWidget(TranscriptBlock $block, TuiTheme $theme): AbstractWidget
    {
        $header = $this->toolCallHeaderLabel($block);
        $suffix = $block->streaming ? TranscriptGlyphs::STREAMING_SUFFIX : '';
        $headerLine = \sprintf('%s %s%s', TranscriptGlyphs::GLYPH_TOOL, $header, $suffix);
        $arguments = $block->meta['arguments'] ?? null;
        if (!\is_array($arguments)) {
            $arguments = [];
        }

        if ($this->isEditToolCall($block, $arguments)) {
            return $this->buildEditToolCallWidget($block, $theme, $headerLine, $arguments);
        }

        if ($this->isWriteToolCall($block, $arguments)) {
            return $this->buildWriteToolCallWidget($block, $theme, $headerLine, $arguments);
        }

        if ($this->isViewImageToolCall($block)) {
            return $this->buildViewImageToolCallWidget($block, $theme, $headerLine, $arguments);
        }

        $lines = [$headerLine];
        if ([] !== $arguments) {
            $argLines = $this->toolArgumentColoredFormatter->formatColoredLines($arguments, $theme);
            $preview = $this->applyLinePreview($argLines, fullRender: false, lineLimit: $this->displayConfig->toolResultPreviewLines);
            foreach ($preview['lines'] as $argLine) {
                $lines[] = '    '.$argLine;
            }
            if (null !== $preview['ellipsis']) {
                $lines[] = '    '.$preview['ellipsis'];
            }
        }

        $coloredHeader = $theme->color(ThemeColorEnum::ToolTitle, $lines[0]);
        $body = \array_slice($lines, 1);

        return new TextWidget(implode("\n", array_merge([$coloredHeader], $body)));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function buildEditToolCallWidget(TranscriptBlock $block, TuiTheme $theme, string $headerLine, array $arguments): ContainerWidget
    {
        $container = new ContainerWidget();
        $container->add(new TextWidget($theme->color(ThemeColorEnum::ToolTitle, $headerLine)));

        $path = $arguments['path'] ?? null;
        if (\is_string($path) && '' !== $path) {
            $container->add(new TextWidget($theme->color(ThemeColorEnum::ToolTitle, '    path: '.$path)));
        }

        $patch = $arguments['patch'] ?? '';
        if (\is_string($patch) && '' !== $patch) {
            $patchBody = $this->editDiffRenderer->buildPatchBodyWidget($patch, $theme, $this->displayConfig, $this->displayState);
            if (null !== $patchBody) {
                $container->add($patchBody);
            }
        }

        return $container;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function buildWriteToolCallWidget(TranscriptBlock $block, TuiTheme $theme, string $headerLine, array $arguments): ContainerWidget
    {
        $container = new ContainerWidget();
        $container->add(new TextWidget($theme->color(ThemeColorEnum::ToolTitle, $headerLine)));

        $path = $arguments['path'] ?? '';
        if (!\is_string($path)) {
            $path = '';
        }
        if ('' !== $path) {
            $container->add(new TextWidget($theme->color(ThemeColorEnum::ToolTitle, '    path: '.$path)));
        }

        $content = $arguments['content'] ?? '';
        if (!\is_string($content)) {
            $content = '';
        }

        foreach ($this->writeContentRenderer->buildContentBodyWidgets($content, $path, $theme, $this->displayConfig, $this->displayState) as $widget) {
            $container->add($widget);
        }

        return $container;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function isEditToolCall(TranscriptBlock $block, array $arguments): bool
    {
        $toolName = $block->meta['tool_name'] ?? null;
        if ('edit' !== $toolName) {
            return false;
        }

        $patch = $arguments['patch'] ?? null;

        return \is_string($patch) && '' !== $patch;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function isWriteToolCall(TranscriptBlock $block, array $arguments): bool
    {
        $toolName = $block->meta['tool_name'] ?? null;
        if ('write' !== $toolName) {
            return false;
        }

        return \array_key_exists('content', $arguments) && \is_string($arguments['content']);
    }

    private function buildToolResultWidget(TranscriptBlock $block, TuiTheme $theme): TextWidget
    {
        if ($this->isViewImageToolName($block->meta['tool_name'] ?? null)) {
            return $this->buildViewImageToolResultWidget($block, $theme);
        }

        $header = $this->toolResultHeaderLabel($block);
        $lines = [\sprintf('%s %s', TranscriptGlyphs::GLYPH_TOOL, $header)];

        $body = $this->toolResultBodyText($block);
        if ('' !== $body) {
            $bodyLines = explode("\n", $body);
            $preview = $this->applyToolResultPreview($bodyLines, $block);
            foreach ($preview['lines'] as $bodyLine) {
                $lines[] = '    '.$bodyLine;
            }
            if (null !== $preview['ellipsis']) {
                $lines[] = '    '.$preview['ellipsis'];
            }
        }

        $suffix = $block->streaming ? TranscriptGlyphs::STREAMING_SUFFIX : '';
        if ('' !== $suffix) {
            $lines[0] .= $suffix;
        }

        $color = $this->toolResultIsFullRender($block) && $this->metaIsTruthy($block->meta['is_error'] ?? false)
            ? ThemeColorEnum::Error
            : ThemeColorEnum::ToolOutput;

        return new TextWidget($theme->color($color, implode("\n", $lines)));
    }

    /**
     * @param list<string> $bodyLines
     *
     * @return array{lines: list<string>, ellipsis: ?string}
     */
    private function applyToolResultPreview(array $bodyLines, TranscriptBlock $block): array
    {
        return $this->applyLinePreview($bodyLines, $this->toolResultIsFullRender($block));
    }

    /**
     * @param list<string> $lines
     *
     * @return array{lines: list<string>, ellipsis: ?string}
     */
    private function applyLinePreview(array $lines, bool $fullRender, ?int $lineLimit = null): array
    {
        $limit = $lineLimit ?? $this->displayConfig->toolResultPreviewLines;

        return $this->linePreviewService->apply($lines, $limit, $fullRender, $this->displayState);
    }

    private function compactSuccessfulEditWriteResultBody(TranscriptBlock $block, string $result): string
    {
        if ($this->toolResultIsFullRender($block)) {
            return $result;
        }

        $toolName = $block->meta['tool_name'] ?? null;
        if (!\is_string($toolName) || 'edit' !== $toolName) {
            // write (and other) successful tool results are already compact status lines.
            return $result;
        }

        $marker = 'Updated file context:';
        $pos = strpos($result, $marker);
        if (false !== $pos) {
            return rtrim(substr($result, 0, $pos));
        }

        return $result;
    }

    /**
     * Error, cancelled, and timed_out tool results bypass preview so diagnostics are not hidden.
     *
     * Projection currently sets is_error for cancelled/timed_out as well; color still keys off is_error when full.
     */
    private function toolResultIsFullRender(TranscriptBlock $block): bool
    {
        return $this->metaIsTruthy($block->meta['is_error'] ?? false)
            || $this->metaIsTruthy($block->meta['cancelled'] ?? false)
            || $this->metaIsTruthy($block->meta['timed_out'] ?? false);
    }

    private function metaIsTruthy(mixed $value): bool
    {
        return true === $value || 1 === $value || '1' === $value;
    }

    private function toolCallHeaderLabel(TranscriptBlock $block): string
    {
        $toolName = $block->meta['tool_name'] ?? null;
        if (\is_string($toolName) && '' !== $toolName) {
            return $toolName;
        }

        if ('' !== $block->text) {
            return $block->text;
        }

        return 'Tool call';
    }

    private function toolResultHeaderLabel(TranscriptBlock $block): string
    {
        $toolName = $block->meta['tool_name'] ?? null;
        if (\is_string($toolName) && '' !== $toolName) {
            return $toolName;
        }

        if ('' !== $block->text && !$this->looksLikeMultilineBody($block->text)) {
            return $block->text;
        }

        return 'Tool result';
    }

    private function looksLikeMultilineBody(string $text): bool
    {
        return str_contains($text, "\n");
    }

    private function toolResultBodyText(TranscriptBlock $block): string
    {
        $result = $block->meta['result'] ?? null;
        if (\is_string($result) && '' !== $result) {
            return $this->compactSuccessfulEditWriteResultBody($block, $result);
        }
        if (\is_scalar($result) && '' !== (string) $result) {
            return (string) $result;
        }
        if (\is_array($result) || \is_object($result)) {
            return trim(Yaml::dump($result, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        }

        $text = $block->text;
        $toolName = $block->meta['tool_name'] ?? null;
        if (\is_string($toolName) && '' !== $toolName && $text === $toolName) {
            return '';
        }
        if ('Tool result' === $text) {
            return '';
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

    private function isViewImageToolCall(TranscriptBlock $block): bool
    {
        return $this->isViewImageToolName($block->meta['tool_name'] ?? null);
    }

    private function isViewImageToolName(mixed $toolName): bool
    {
        return \is_string($toolName) && 'view_image' === $toolName;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function buildViewImageToolCallWidget(TranscriptBlock $block, TuiTheme $theme, string $headerLine, array $arguments): TextWidget
    {
        // $headerLine already includes the streaming suffix from buildToolCallWidget().
        $lines = [$headerLine];
        foreach ($this->viewImageFormatter->formatToolCallLines($arguments) as $bodyLine) {
            $lines[] = '    '.$bodyLine;
        }

        return new TextWidget($theme->color(ThemeColorEnum::ToolTitle, implode("\n", $lines)));
    }

    private function buildViewImageToolResultWidget(TranscriptBlock $block, TuiTheme $theme): TextWidget
    {
        $header = \sprintf('%s %s', TranscriptGlyphs::GLYPH_TOOL, $this->toolResultHeaderLabel($block));
        $lines = [$header];
        $result = $block->meta['result'] ?? null;
        $bodyLines = $this->viewImageFormatter->formatToolResultLines($result);
        if ([] === $bodyLines && \is_string($result) && '' !== $result) {
            if ($this->toolResultIsFullRender($block)) {
                $bodyLines = [$result];
            } else {
                $bodyLines = ['(image metadata)'];
            }
        }
        foreach ($bodyLines as $bodyLine) {
            $lines[] = '    '.$bodyLine;
        }

        $color = $this->toolResultIsFullRender($block) && $this->metaIsTruthy($block->meta['is_error'] ?? false)
            ? ThemeColorEnum::Error
            : ThemeColorEnum::ToolOutput;

        return new TextWidget($theme->color($color, implode("\n", $lines)));
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
