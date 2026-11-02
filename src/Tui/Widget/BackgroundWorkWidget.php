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
    /** @var list<array{kind: string, label: string, status: string, needs_input?: bool, suffix?: string}> */
    private array $rows = [];
    /** @var array<int, true> */
    private array $modalOverlays = [];
    /** @var array<int, AbstractWidget> */
    private array $overlays = [];
    /** @var list<int> */
    private array $reservedRevisions = [];

    public function __construct(private readonly TuiTheme $theme, private readonly AbstractWidget $editor, private readonly AbstractWidget $footer)
    {
    }

    /** @param list<array{kind: string, label: string, status: string, needs_input?: bool, suffix?: string}> $rows */
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
        // Reconsider the budget when editing, footer or overlay content changes.
        $revisions = array_map(static fn (AbstractWidget $widget): int => $widget->getRenderRevision(), [$this->editor, $this->footer, ...array_values($this->overlays)]);
        if ($revisions !== $this->reservedRevisions) {
            $this->reservedRevisions = $revisions;
            $this->invalidate();
        }
    }

    public function reserveOverlay(AbstractWidget $widget): void
    {
        $this->overlays[spl_object_id($widget)] = $widget;
        $this->invalidate();
    }

    public function releaseOverlay(AbstractWidget $widget): void
    {
        if (isset($this->modalOverlays[spl_object_id($widget)])) {
            unset($this->modalOverlays[spl_object_id($widget)]);
            $this->invalidate();
        }
        if (isset($this->overlays[spl_object_id($widget)])) {
            unset($this->overlays[spl_object_id($widget)]);
            $this->invalidate();
        }
    }

    public function render(RenderContext $context): array
    {
        // Questions measure their lower siblings, including this widget. Give
        // focused modals priority without recursively measuring them here.
        if ([] !== $this->modalOverlays) {
            return [];
        }
        $reserved = 2; // Editor and footer separators remain visible.
        foreach ([$this->editor, $this->footer, ...array_values($this->overlays)] as $widget) {
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
            if (isset($row['suffix'])) {
                // Keep operational metadata visible before spending remaining
                // columns on task text and optional identity details.
                $suffix = ' · '.StringUtils::stripControlBytes(StringUtils::sanitizeUtf8(str_replace(["\r", "\n"], ' ', $row['suffix'])));
                $prefix = '  '.$row['kind'].' ['.$row['status'].'] ';
                $budget = max(0, $context->getColumns() - mb_strwidth($prefix.$suffix));
                $detail = mb_strimwidth($plain, 0, $budget, $budget >= 1 ? '…' : '');
                $lines[] = $this->theme->color($color, $prefix).$detail.$suffix;

                continue;
            }
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

    public function reserveModalOverlay(AbstractWidget $widget): void
    {
        $this->modalOverlays[spl_object_id($widget)] = true;
        $this->invalidate();
    }
}
