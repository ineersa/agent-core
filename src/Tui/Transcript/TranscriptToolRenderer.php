<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Compact tool-call/result/exchange card rendering for the transcript widget tree.
 *
 * Owns ToolCall/ToolResult single-block cards and the ToolCall+ToolResult exchange
 * cards (generic, view_image, skill read, edit, write), including argument/header
 * formatting, preview truncation, and result body/color selection. Result-body
 * presentation facts live in {@see TranscriptToolResultFacts}; pairing/suppression
 * stays in {@see TranscriptToolPresentationPolicy}. Structured subagent tool results are
 * handled by the factory's exchange entry before delegation, see
 * {@see TranscriptBlockWidgetFactory::buildToolExchangeWidget()}.
 */
final readonly class TranscriptToolRenderer
{
    public function __construct(
        private readonly TranscriptDisplayConfig $displayConfig,
        private readonly TranscriptDisplayState $displayState,
        private readonly EditToolCallDiffRenderer $editDiffRenderer,
        private readonly WriteToolCallContentRenderer $writeContentRenderer,
        private readonly TranscriptLinePreviewService $linePreviewService,
        private readonly ToolArgumentColoredFormatter $toolArgumentColoredFormatter,
        private readonly ViewImageTranscriptFormatter $viewImageFormatter,
        private readonly TranscriptToolResultFacts $toolResultFacts,
    ) {
    }

    public function buildToolCallWidget(TranscriptBlock $block, TuiTheme $theme): AbstractWidget
    {
        $arguments = $block->meta['arguments'] ?? null;
        if (!\is_array($arguments)) {
            $arguments = [];
        }

        if ($this->isSkillReadToolCall($block)) {
            return $this->buildSkillReadToolCallWidget($block, $theme, $arguments);
        }

        $header = $this->toolCallHeaderLabel($block);
        $suffix = $block->streaming ? TranscriptGlyphs::STREAMING_SUFFIX : '';
        $headerLine = \sprintf('%s %s%s', TranscriptGlyphs::GLYPH_TOOL, $header, $suffix);

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
                $lines[] = '    '.TranscriptPreviewEllipsis::style($theme, $preview['ellipsis']);
            }
        }

        $coloredHeader = $theme->color(ThemeColorEnum::ToolTitle, $lines[0]);
        $body = \array_slice($lines, 1);

        return new TextWidget(implode("\n", array_merge([$coloredHeader], $body)));
    }

    public function buildToolResultWidget(TranscriptBlock $block, TuiTheme $theme): TextWidget
    {
        if ($this->isViewImageToolName($block->meta['tool_name'] ?? null)) {
            return $this->buildViewImageToolResultWidget($block, $theme);
        }

        $header = $this->toolResultHeaderLabel($block);
        $headerLine = \sprintf('%s %s', TranscriptGlyphs::GLYPH_TOOL, $header);
        $lines = [];

        $body = $this->toolResultFacts->toolResultBodyText($block);
        if ('' !== $body) {
            $bodyLines = explode("\n", $body);
            $preview = $this->applyToolResultPreview($bodyLines, $block);
            foreach ($preview['lines'] as $bodyLine) {
                $lines[] = '    '.$bodyLine;
            }
            if (null !== $preview['ellipsis']) {
                $lines[] = '    '.TranscriptPreviewEllipsis::style($theme, $preview['ellipsis']);
            }
        }

        $suffix = $block->streaming ? TranscriptGlyphs::STREAMING_SUFFIX : '';
        if ('' !== $suffix) {
            $headerLine .= $suffix;
        }

        $color = $this->toolResultFacts->toolResultIsFullRender($block) && $this->toolResultFacts->metaIsTruthy($block->meta['is_error'] ?? false)
            ? ThemeColorEnum::Error
            : ThemeColorEnum::ToolOutput;

        $coloredHeader = $theme->color($color, $headerLine);
        $coloredBody = [];
        foreach ($lines as $line) {
            // Ellipsis already carries muted+italic ANSI; do not recolor it.
            if (str_contains($line, '…')) {
                $coloredBody[] = $line;
                continue;
            }
            $coloredBody[] = $theme->color($color, $line);
        }

        return new TextWidget(implode("\n", array_merge([$coloredHeader], $coloredBody)));
    }

    public function buildToolExchangeWidget(TranscriptBlock $callBlock, TranscriptBlock $resultBlock, TuiTheme $theme): AbstractWidget
    {
        if ($this->isViewImageToolName($callBlock->meta['tool_name'] ?? null)) {
            return $this->buildViewImageToolExchangeWidget($callBlock, $resultBlock, $theme);
        }

        $arguments = $callBlock->meta['arguments'] ?? null;
        if (!\is_array($arguments)) {
            $arguments = [];
        }

        if ($this->isSkillReadToolCall($callBlock)) {
            return $this->buildSkillReadToolExchangeWidget($callBlock, $resultBlock, $theme, $arguments);
        }

        if ($this->isEditToolCall($callBlock, $arguments)) {
            return $this->buildEditToolExchangeWidget($callBlock, $resultBlock, $theme, $arguments);
        }

        if ($this->isWriteToolCall($callBlock, $arguments)) {
            return $this->buildWriteToolExchangeWidget($callBlock, $resultBlock, $theme, $arguments);
        }

        return $this->buildGenericToolExchangeWidget($callBlock, $resultBlock, $theme, $arguments);
    }

    private function isSkillReadToolCall(TranscriptBlock $block): bool
    {
        if ('read' !== ($block->meta['tool_name'] ?? null)) {
            return false;
        }

        $skillName = $block->meta['skill_name'] ?? null;

        return \is_string($skillName) && '' !== $skillName;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function buildSkillReadToolCallWidget(TranscriptBlock $block, TuiTheme $theme, array $arguments): TextWidget
    {
        $suffix = $block->streaming ? TranscriptGlyphs::STREAMING_SUFFIX : '';
        $headerLine = $this->skillReadHeaderLabel($block, $arguments).$suffix;

        return new TextWidget($theme->color(ThemeColorEnum::Skill, $headerLine));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function buildSkillReadToolExchangeWidget(
        TranscriptBlock $callBlock,
        TranscriptBlock $resultBlock,
        TuiTheme $theme,
        array $arguments,
    ): TextWidget {
        $headerLine = $this->skillReadHeaderLabel($callBlock, $arguments);
        $fullRender = $this->toolResultFacts->toolResultIsFullRender($resultBlock);
        $expanded = $this->displayState->previewableBlocksExpanded;

        // Collapsed successful skill reads hide args/result; keep only the compact header + expand hint.
        // Errors/cancel/timeout always show the diagnostic body (even when previews are collapsed).
        if (!$fullRender && !$expanded) {
            $hint = $theme->color(ThemeColorEnum::Dim, ' (Ctrl+O to expand)');

            return new TextWidget($theme->color(ThemeColorEnum::Skill, $headerLine).$hint);
        }

        $lines = [$theme->color(ThemeColorEnum::Skill, $headerLine)];
        foreach ($this->toolExchangeResultBodyLines($resultBlock) as $bodyLine) {
            $lines[] = $this->styledIndentedBodyLine($theme, $this->toolExchangeBodyColor($resultBlock), $bodyLine);
        }

        return new TextWidget(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function skillReadHeaderLabel(TranscriptBlock $block, array $arguments): string
    {
        $skillName = (string) ($block->meta['skill_name'] ?? '');
        $range = $this->formatReadLineRange($arguments);

        // Two-space flat-text inset matching the transcript's 2-column left padding
        // (not widget Style padding — skill cards are plain TextWidget lines).
        return \sprintf('  [skill] %s%s', $skillName, $range);
    }

    /**
     * Pi formatReadLineRange compatibility:
     * no args → no suffix; limit-only → :1-end; offset-only → :start; both → :start-end.
     *
     * @param array<string, mixed> $arguments
     */
    private function formatReadLineRange(array $arguments): string
    {
        $offset = isset($arguments['offset']) && is_numeric($arguments['offset'])
            ? (int) $arguments['offset']
            : null;
        $limit = isset($arguments['limit']) && is_numeric($arguments['limit'])
            ? (int) $arguments['limit']
            : null;

        if (null === $offset && null === $limit) {
            return '';
        }

        $start = $offset ?? 1;
        if (null !== $limit) {
            return \sprintf(':%d-%d', $start, $start + $limit - 1);
        }

        return \sprintf(':%d', $start);
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
            $container->add(new TextWidget($this->coloredArgumentPathLine($theme, $path)));
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
            $container->add(new TextWidget($this->coloredArgumentPathLine($theme, $path)));
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

    /**
     * @param list<string> $bodyLines
     *
     * @return array{lines: list<string>, ellipsis: ?string}
     */
    private function applyToolResultPreview(array $bodyLines, TranscriptBlock $block): array
    {
        return $this->applyLinePreview($bodyLines, $this->toolResultFacts->toolResultIsFullRender($block));
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
    private function buildGenericToolExchangeWidget(
        TranscriptBlock $callBlock,
        TranscriptBlock $resultBlock,
        TuiTheme $theme,
        array $arguments,
    ): TextWidget {
        $header = $this->toolCallHeaderLabel($callBlock);
        $suffix = $callBlock->streaming ? TranscriptGlyphs::STREAMING_SUFFIX : '';
        $headerLine = \sprintf('%s %s%s', TranscriptGlyphs::GLYPH_TOOL, $header, $suffix);
        $lines = [$headerLine];

        if ([] !== $arguments) {
            $argLines = $this->toolArgumentColoredFormatter->formatColoredLines($arguments, $theme);
            $preview = $this->applyLinePreview($argLines, fullRender: false, lineLimit: $this->displayConfig->toolResultPreviewLines);
            foreach ($preview['lines'] as $argLine) {
                $lines[] = '    '.$argLine;
            }
            if (null !== $preview['ellipsis']) {
                $lines[] = '    '.TranscriptPreviewEllipsis::style($theme, $preview['ellipsis']);
            }
        }

        foreach ($this->toolExchangeResultBodyLines($resultBlock) as $bodyLine) {
            $lines[] = $this->styledIndentedBodyLine($theme, $this->toolExchangeBodyColor($resultBlock), $bodyLine);
        }

        $coloredHeader = $theme->color(ThemeColorEnum::ToolTitle, $lines[0]);
        $body = \array_slice($lines, 1);

        return new TextWidget(implode("\n", array_merge([$coloredHeader], $body)));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function buildEditToolExchangeWidget(
        TranscriptBlock $callBlock,
        TranscriptBlock $resultBlock,
        TuiTheme $theme,
        array $arguments,
    ): ContainerWidget {
        $header = $this->toolCallHeaderLabel($callBlock);
        $suffix = $callBlock->streaming ? TranscriptGlyphs::STREAMING_SUFFIX : '';
        $headerLine = \sprintf('%s %s%s', TranscriptGlyphs::GLYPH_TOOL, $header, $suffix);

        $container = new ContainerWidget();
        $container->add(new TextWidget($theme->color(ThemeColorEnum::ToolTitle, $headerLine)));

        $path = $arguments['path'] ?? null;
        if (\is_string($path) && '' !== $path) {
            $container->add(new TextWidget($this->coloredArgumentPathLine($theme, $path)));
        }

        $patch = $arguments['patch'] ?? '';
        if (\is_string($patch) && '' !== $patch) {
            $patchBody = $this->editDiffRenderer->buildPatchBodyWidget($patch, $theme, $this->displayConfig, $this->displayState);
            if (null !== $patchBody) {
                $container->add($patchBody);
            }
        }

        foreach ($this->toolExchangeResultBodyLines($resultBlock) as $bodyLine) {
            $container->add(new TextWidget(
                $this->styledIndentedBodyLine($theme, $this->toolExchangeBodyColor($resultBlock), $bodyLine),
            ));
        }

        return $container;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function buildWriteToolExchangeWidget(
        TranscriptBlock $callBlock,
        TranscriptBlock $resultBlock,
        TuiTheme $theme,
        array $arguments,
    ): ContainerWidget {
        $header = $this->toolCallHeaderLabel($callBlock);
        $suffix = $callBlock->streaming ? TranscriptGlyphs::STREAMING_SUFFIX : '';
        $headerLine = \sprintf('%s %s%s', TranscriptGlyphs::GLYPH_TOOL, $header, $suffix);

        $container = new ContainerWidget();
        $container->add(new TextWidget($theme->color(ThemeColorEnum::ToolTitle, $headerLine)));

        $path = $arguments['path'] ?? '';
        if (!\is_string($path)) {
            $path = '';
        }
        if ('' !== $path) {
            $container->add(new TextWidget($this->coloredArgumentPathLine($theme, $path)));
        }

        $content = $arguments['content'] ?? '';
        if (!\is_string($content)) {
            $content = '';
        }

        foreach ($this->writeContentRenderer->buildContentBodyWidgets($content, $path, $theme, $this->displayConfig, $this->displayState) as $widget) {
            $container->add($widget);
        }

        foreach ($this->toolExchangeResultBodyLines($resultBlock) as $bodyLine) {
            $container->add(new TextWidget(
                $this->styledIndentedBodyLine($theme, $this->toolExchangeBodyColor($resultBlock), $bodyLine),
            ));
        }

        return $container;
    }

    private function buildViewImageToolExchangeWidget(
        TranscriptBlock $callBlock,
        TranscriptBlock $resultBlock,
        TuiTheme $theme,
    ): TextWidget {
        $header = $this->toolCallHeaderLabel($callBlock);
        $suffix = $callBlock->streaming ? TranscriptGlyphs::STREAMING_SUFFIX : '';
        $headerLine = \sprintf('%s %s%s', TranscriptGlyphs::GLYPH_TOOL, $header, $suffix);
        $lines = [$headerLine];

        $arguments = $callBlock->meta['arguments'] ?? null;
        if (!\is_array($arguments)) {
            $arguments = [];
        }
        foreach ($this->viewImageFormatter->formatToolCallLines($arguments) as $bodyLine) {
            $lines[] = '    '.$bodyLine;
        }

        foreach ($this->toolExchangeResultBodyLines($resultBlock) as $bodyLine) {
            $lines[] = $this->styledIndentedBodyLine($theme, $this->toolExchangeBodyColor($resultBlock), $bodyLine);
        }

        $coloredHeader = $theme->color(ThemeColorEnum::ToolTitle, $lines[0]);
        $body = \array_slice($lines, 1);

        return new TextWidget(implode("\n", array_merge([$coloredHeader], $body)));
    }

    /**
     * @return list<string>
     */
    private function toolExchangeResultBodyLines(TranscriptBlock $resultBlock): array
    {
        if ($this->isViewImageToolName($resultBlock->meta['tool_name'] ?? null)) {
            $result = $resultBlock->meta['result'] ?? null;
            $bodyLines = $this->viewImageFormatter->formatToolResultLines($result);
            if ([] === $bodyLines && \is_string($result) && '' !== $result) {
                if ($this->toolResultFacts->toolResultIsFullRender($resultBlock)) {
                    return [$result];
                }

                return ['(image metadata)'];
            }

            return $bodyLines;
        }

        $body = $this->toolResultFacts->toolResultBodyText($resultBlock);
        if ('' === $body) {
            return [];
        }

        $bodyLines = explode("\n", $body);
        $preview = $this->applyToolResultPreview($bodyLines, $resultBlock);

        $lines = $preview['lines'];
        if (null !== $preview['ellipsis']) {
            $lines[] = $preview['ellipsis'];
        }

        return $lines;
    }

    private function toolExchangeBodyColor(TranscriptBlock $resultBlock): ThemeColorEnum
    {
        if ($this->toolResultFacts->toolResultIsFullRender($resultBlock) && $this->toolResultFacts->metaIsTruthy($resultBlock->meta['is_error'] ?? false)) {
            return ThemeColorEnum::Error;
        }

        return ThemeColorEnum::ToolOutput;
    }

    /**
     * Indent a tool body/ellipsis line and apply theme color unless it is already a styled ellipsis.
     */
    private function styledIndentedBodyLine(TuiTheme $theme, ThemeColorEnum $color, string $bodyLine): string
    {
        if (str_starts_with($bodyLine, '…')) {
            return '    '.TranscriptPreviewEllipsis::style($theme, $bodyLine);
        }

        return $theme->color($color, '    '.$bodyLine);
    }

    private function coloredArgumentPathLine(TuiTheme $theme, string $path): string
    {
        return '    '
            .$theme->color(ThemeColorEnum::ToolArgumentKey, 'path')
            .':'
            .$theme->color(ThemeColorEnum::ToolArgumentValue, ' '.$path);
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
            if ($this->toolResultFacts->toolResultIsFullRender($block)) {
                $bodyLines = [$result];
            } else {
                $bodyLines = ['(image metadata)'];
            }
        }
        foreach ($bodyLines as $bodyLine) {
            $lines[] = '    '.$bodyLine;
        }

        $color = $this->toolResultFacts->toolResultIsFullRender($block) && $this->toolResultFacts->metaIsTruthy($block->meta['is_error'] ?? false)
            ? ThemeColorEnum::Error
            : ThemeColorEnum::ToolOutput;

        return new TextWidget($theme->color($color, implode("\n", $lines)));
    }
}
