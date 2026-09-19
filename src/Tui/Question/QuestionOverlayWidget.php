<?php

declare(strict_types=1);

namespace Ineersa\Tui\Question;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\WidgetContainerInterface;

/**
 * Bounded question overlay host for ChatScreen.
 *
 * Renders interactive question children against a physical-row budget of
 * {@code min(12, terminalRows − lowerBlockRows)}. Lower-block height is the
 * actual rendered height of siblings below this overlay (compact header,
 * editor separators, editor, footer), measured through the live widget
 * context — not a magic constant.
 *
 * This widget is intentionally not vertically expandable: LayoutEngine would
 * otherwise give it only leftover fill rows after a tall transcript. As a
 * natural-height leaf it receives the parent row count as context, emits at
 * most the computed budget, and relies on bottom-aligned taller-frame writing
 * so older transcript scrolls away while the question stays above
 * editor/footer.
 *
 * Children stay ordinary attached widgets so focus and input continue to
 * target the SelectListWidget (or editor) inside. The overlay always emits
 * exactly the computed budget rows (padding unused rows with blanks) so a
 * short selected option such as "Type your answer" cannot shrink the
 * surrounding ChatScreen frame and trip ScreenWriter's overheight-shrink
 * clear path.
 */
final class QuestionOverlayWidget extends AbstractWidget implements WidgetContainerInterface
{
    public const int MAX_PHYSICAL_ROWS = 12;

    /** @var list<AbstractWidget> */
    private array $children = [];

    /** @var array<int, int> */
    private array $lowerBlockRevisions = [];

    private int $promptOffset = 0;
    private int $promptPageRows = 0;
    private int $promptMaxOffset = 0;

    public function pagePrompt(int $direction): void
    {
        $offset = max(0, min($this->promptMaxOffset, $this->promptOffset + $direction * $this->promptPageRows));
        if ($offset !== $this->promptOffset) {
            $this->promptOffset = $offset;
            $this->invalidate();
        }
    }

    public function beforeRender(): void
    {
        // Sibling invalidation reaches our parent, not this widget. Check the
        // dependencies before Symfony can reuse the cached row budget.
        $revisions = [];
        foreach ($this->lowerSiblings() as $sibling) {
            $revisions[spl_object_id($sibling)] = $sibling->getRenderRevision();
        }
        if ($revisions !== $this->lowerBlockRevisions) {
            $this->lowerBlockRevisions = $revisions;
            $this->invalidate();
        }
    }

    /**
     * @return $this
     */
    public function add(AbstractWidget $widget): static
    {
        $this->children[] = $widget;
        $this->attachChild($widget);
        $this->invalidate();

        return $this;
    }

    /**
     * @return $this
     */
    public function remove(AbstractWidget $widget): static
    {
        if (false !== $index = array_search($widget, $this->children, true)) {
            $this->detachChild($this->children[$index]);
            array_splice($this->children, $index, 1);
            $this->invalidate();
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function clear(): static
    {
        foreach ($this->children as $child) {
            $this->detachChild($child);
        }
        if ([] !== $this->children) {
            $this->children = [];
            $this->invalidate();
        }

        return $this;
    }

    /**
     * @return list<AbstractWidget>
     */
    public function all(): array
    {
        return $this->children;
    }

    /**
     * @return list<string>
     */
    public function render(RenderContext $context): array
    {
        $widgetContext = $this->getContext();
        if (null === $widgetContext) {
            return [];
        }

        $terminalRows = max(1, $context->getRows());
        $lowerBlockRows = $this->measureLowerBlockRows($context);
        // Prefer keeping the question block within the viewport even when the
        // measured lower chrome is tall: never let lower siblings claim so many
        // rows that the select list collapses to a single clamped option.
        $available = max(1, $terminalRows - $lowerBlockRows);
        $budget = max(1, min(self::MAX_PHYSICAL_ROWS, $available));
        $columns = $context->getColumns();
        $gap = max(0, $this->getStyle()?->getGap() ?? 0);

        $selectIndex = null;
        $promptIndex = null;
        $selectRows = 0;
        foreach ($this->children as $index => $child) {
            if ($child instanceof MarkdownWidget) {
                $promptIndex = $index;
            }
            if ($child instanceof SelectListWidget) {
                $selectIndex = $index;
                // Reserve useful answer rows, not blank space that could hide
                // the command or path the user is being asked to approve.
                $selectRows = \count($widgetContext->renderWidget($child, new RenderContext($columns, max(1, intdiv($budget, 2)))));
                break;
            }
        }
        if (null !== $promptIndex && null !== $selectIndex) {
            // A prompt/warning row takes precedence over decorative spacing.
            $gap = min($gap, max(0, $budget - $selectRows - 1));
        }

        $lines = [];
        $remaining = $budget;
        $previousEmitted = false;

        foreach ($this->children as $index => $child) {
            if ($remaining <= 0) {
                break;
            }

            $needsGap = $previousEmitted && $gap > 0;
            // Keep the measured answer rows and their preceding gap available.
            // Short lists leave the rest of the band for the prompt.
            $reserveForSelect = 0;
            if (null !== $selectIndex && $index < $selectIndex) {
                $reserveForSelect = $gap + $selectRows;
                if (null !== $promptIndex && $index < $promptIndex) {
                    // Omit or shorten the header before hiding the approval prompt.
                    $reserveForSelect += $gap + 1;
                }
            }
            $childBudget = $remaining - ($needsGap ? $gap : 0) - $reserveForSelect;
            if ($childBudget <= 0) {
                if (null !== $selectIndex && $index < $selectIndex) {
                    continue;
                }
                break;
            }

            $childLines = $widgetContext->renderWidget($child, new RenderContext($columns, $childBudget));
            if ([] === $childLines) {
                continue;
            }
            if (null !== $selectIndex && $child instanceof MarkdownWidget) {
                $childLines = $this->promptWindow($childLines, $childBudget, $columns);
            } elseif (\count($childLines) > $childBudget) {
                $childLines = \array_slice($childLines, 0, $childBudget);
            }

            if ($needsGap) {
                for ($i = 0; $i < $gap; ++$i) {
                    $lines[] = '';
                }
                $remaining -= $gap;
            }

            foreach ($childLines as $line) {
                $lines[] = $line;
            }
            $remaining -= \count($childLines);
            $previousEmitted = true;
        }

        // Keep the reserved question band height stable across selection changes.
        while (\count($lines) < $budget) {
            $lines[] = '';
        }

        return $lines;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function promptWindow(array $lines, int $rows, int $columns): array
    {
        if (\count($lines) <= $rows) {
            $this->promptOffset = $this->promptPageRows = $this->promptMaxOffset = 0;

            return $lines;
        }

        // Keep the warning visible on every page, including the last one.
        $this->promptPageRows = max(0, $rows - 1);
        $this->promptMaxOffset = max(0, \count($lines) - $this->promptPageRows);
        $this->promptOffset = min($this->promptOffset, $this->promptMaxOffset);
        $visible = \array_slice($lines, $this->promptOffset, $this->promptPageRows);
        $notice = $this->promptPageRows > 0
            ? \sprintf('Partial prompt %d-%d/%d Ctrl+↑/↓', $this->promptOffset + 1, $this->promptOffset + $this->promptPageRows, \count($lines))
            : 'Prompt clipped; enlarge terminal';
        $visible[] = AnsiUtils::truncateToWidth($notice, $columns);

        return $visible;
    }

    /**
     * Measure siblings rendered after this overlay in the parent container.
     *
     * That lower block (compact header + editor separators + editor + footer)
     * must remain visible in the bottom-aligned viewport, so the question
     * budget excludes those actual rendered rows.
     */
    private function measureLowerBlockRows(RenderContext $context): int
    {
        $widgetContext = $this->getContext();
        if (null === $widgetContext) {
            return 0;
        }

        $columns = $context->getColumns();
        $rows = max(1, $context->getRows());
        $siblingContext = new RenderContext($columns, $rows);
        $lowerRows = 0;

        foreach ($this->lowerSiblings() as $sibling) {
            $lowerRows += \count($widgetContext->renderWidget($sibling, $siblingContext));
        }

        return max(0, $lowerRows);
    }

    /** @return list<AbstractWidget> */
    private function lowerSiblings(): array
    {
        $parent = $this->getParent();
        if (!$parent instanceof WidgetContainerInterface) {
            return [];
        }
        $siblings = [];
        $seenSelf = false;
        foreach ($parent->all() as $sibling) {
            if ($sibling === $this) {
                $seenSelf = true;
                continue;
            }
            if (!$seenSelf) {
                continue;
            }

            $siblings[] = $sibling;
        }

        return $siblings;
    }
}
