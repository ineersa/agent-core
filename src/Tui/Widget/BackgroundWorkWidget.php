<?php

declare(strict_types=1);

namespace Ineersa\Tui\Widget;

use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;

/** Bounded native text rows; editor/footer measurements reuse native render caches. */
final class BackgroundWorkWidget extends AbstractWidget
{
    /** @var list<array{kind: string, label: string, status: string, needs_input?: bool}> */
    private array $rows = [];
    private int $editorRevision = -1;
    private int $footerRevision = -1;

    public function __construct(private readonly TuiTheme $theme, private readonly AbstractWidget $editor, private readonly AbstractWidget $footer)
    {
    }

    /** @param list<array{kind: string, label: string, status: string, needs_input?: bool}> $rows */
    public function setRows(array $rows): void
    {
        if ($rows !== $this->rows) {
            $this->rows = $rows;
            $this->invalidate();
        }
    }

    public function beforeRender(): void
    {
        // Native cache keys include terminal dimensions, but not sibling revisions.
        // Reconsider the budget when multiline editing or footer content changes.
        $editor = $this->editor->getRenderRevision();
        $footer = $this->footer->getRenderRevision();
        if ($editor !== $this->editorRevision || $footer !== $this->footerRevision) {
            $this->editorRevision = $editor;
            $this->footerRevision = $footer;
            $this->invalidate();
        }
    }

    public function render(RenderContext $context): array
    {
        $reserved = 2; // Editor and footer separators remain visible.
        foreach ([$this->editor, $this->footer] as $widget) {
            $reserved += \count($widget->getRenderCache($context->getColumns(), $context->getRows()) ?? $widget->render($context));
        }
        $limit = max(0, min(5, $context->getRows() - $reserved));
        $rows = $this->rows;
        $overflow = \count($rows) > $limit;
        if ($overflow) {
            // Stable partitions retain canonical launch order within each group.
            $rows = array_merge(
                array_values(array_filter($rows, static fn (array $row): bool => $row['needs_input'] ?? false)),
                array_values(array_filter($rows, static fn (array $row): bool => !($row['needs_input'] ?? false))),
            );
        }
        $shown = \array_slice($rows, 0, max(0, $limit - (int) $overflow));
        $lines = [];
        foreach ($shown as $row) {
            $color = match ($row['kind']) {
                'fork' => ThemeColorEnum::Warning,
                'agent', 'subagent' => ThemeColorEnum::Accent,
                'code_mode' => ThemeColorEnum::Success,
                default => ThemeColorEnum::ThinkingLow,
            };
            $plain = StringUtils::stripControlBytes(StringUtils::sanitizeUtf8(str_replace(["\r", "\n"], ' ', $row['label'])));
            $lines[] = '' === $row['status']
                ? $this->theme->color($color, '  '.$plain)
                : $this->theme->color($color, '  '.$row['kind']).' ['.$row['status'].'] '.$plain;
        }
        if ($overflow && $limit > 0) {
            $hidden = \count($rows) - \count($shown);
            $lines[] = $context->getColumns() >= 32
                ? '  +'.$hidden.' more · /bg or Ctrl+\\'
                : '+'.$hidden.($context->getColumns() >= 20 ? ' more' : '').' /bg Ctrl+\\';
        }

        // TextWidget owns native ANSI-aware one-physical-line truncation.
        return (new TextWidget(implode("\n", $lines), truncate: true))->render($context);
    }
}
