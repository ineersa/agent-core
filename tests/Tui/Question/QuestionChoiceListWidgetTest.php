<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Question;

use Ineersa\Tui\Question\QuestionChoiceListWidget;
use Ineersa\Tui\Widget\SelectListKeybindings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Widget\ContainerWidget;

final class QuestionChoiceListWidgetTest extends TestCase
{
    #[Test]
    public function testLongLabelsWrapWithoutEllipsisAtNarrowAndWideWidths(): void
    {
        $longA = 'ALPHA_BEGIN '.str_repeat('alpha-middle-word ', 8).'ALPHA_TAIL_UNIQUE';
        $longB = 'BETA_BEGIN '.str_repeat('beta-middle-word ', 8).'BETA_TAIL_UNIQUE';
        $widget = $this->widget([
            ['value' => $longA, 'label' => $longA],
            ['value' => $longB, 'label' => $longB, 'description' => 'Long description that also wraps across several columns when space is tight'],
        ]);

        foreach ([50, 120] as $width) {
            $plain = $this->plain($this->render($widget, $width));
            $this->assertStringContainsString('ALPHA_BEGIN', $plain);
            $this->assertStringContainsString('ALPHA_TAIL_UNIQUE', $plain);
            $this->assertStringContainsString('BETA_BEGIN', $plain);
            $this->assertStringContainsString('BETA_TAIL_UNIQUE', $plain);
            $this->assertStringNotContainsString('…', $plain);
            $this->assertStringContainsString('Long description that also wraps', $plain);

            foreach ($this->render($widget, $width) as $line) {
                $this->assertLessThanOrEqual(
                    $width,
                    AnsiUtils::visibleWidth($line),
                    "Wrapped line exceeds width {$width}: {$line}",
                );
            }
        }
    }

    #[Test]
    public function testSelectedMarkerOnlyOnFirstRowAndStyleCoversContinuation(): void
    {
        $label = 'SELECTED_BEGIN '.str_repeat('wrap-me-please ', 10).'SELECTED_TAIL';
        $widget = $this->widget([
            ['value' => $label, 'label' => $label],
            ['value' => 'short', 'label' => 'short'],
        ]);

        $lines = $this->render($widget, 40);
        $selectedRows = [];
        foreach ($lines as $line) {
            $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $line) ?? $line;
            if ('' === trim($plain)) {
                continue;
            }
            if (str_contains($plain, 'SELECTED_') || (str_starts_with($plain, '  ') && [] !== $selectedRows && !str_contains($plain, 'short'))) {
                if (str_contains($plain, 'SELECTED_') || (str_starts_with($plain, '  ') && str_contains($line, "\x1b"))) {
                    $selectedRows[] = $line;
                }
            }
        }

        $this->assertNotEmpty($selectedRows);
        $firstPlain = preg_replace('/\x1b\[[0-9;]*m/', '', $selectedRows[0]) ?? $selectedRows[0];
        $this->assertStringStartsWith('→ ', $firstPlain);

        foreach (\array_slice($selectedRows, 1) as $continuation) {
            $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $continuation) ?? $continuation;
            $this->assertStringStartsWith('  ', $plain);
            $this->assertStringNotContainsString('→', $plain);
            $this->assertStringContainsString("\x1b", $continuation, 'Selected continuation must keep selected styling');
        }
    }

    #[Test]
    public function testExactlyOneBlankRowBetweenLogicalChoicesNeverInsideWrappedLabel(): void
    {
        $long = 'WRAP_BEGIN '.str_repeat('wrap-token ', 8).'WRAP_TAIL';
        $widget = $this->widget([
            ['value' => 'short-a', 'label' => 'SHORT_A'],
            ['value' => $long, 'label' => $long, 'description' => 'DESC_BEGIN '.str_repeat('desc-token ', 6).'DESC_TAIL'],
            ['value' => 'short-b', 'label' => 'SHORT_B'],
        ], maxVisible: 10);

        foreach ([42, 100] as $width) {
            $lines = $this->render($widget, $width);
            $plainLines = array_map(
                static fn (string $line): string => preg_replace('/\x1b\[[0-9;]*m/', '', $line) ?? $line,
                $lines,
            );

            $shortA = null;
            $wrapBegin = null;
            $wrapTail = null;
            $descBegin = null;
            $descTail = null;
            $shortB = null;
            foreach ($plainLines as $index => $plain) {
                if (str_contains($plain, 'SHORT_A')) {
                    $shortA = $index;
                }
                if (str_contains($plain, 'WRAP_BEGIN')) {
                    $wrapBegin = $index;
                }
                if (str_contains($plain, 'WRAP_TAIL')) {
                    $wrapTail = $index;
                }
                if (str_contains($plain, 'DESC_BEGIN')) {
                    $descBegin = $index;
                }
                if (str_contains($plain, 'DESC_TAIL')) {
                    $descTail = $index;
                }
                if (str_contains($plain, 'SHORT_B')) {
                    $shortB = $index;
                }
            }

            $this->assertNotNull($shortA);
            $this->assertNotNull($wrapBegin);
            $this->assertNotNull($wrapTail);
            $this->assertNotNull($descBegin);
            $this->assertNotNull($descTail);
            $this->assertNotNull($shortB);

            $this->assertSame('', trim($plainLines[$shortA + 1] ?? 'missing'), 'Exactly one blank row after first logical choice');
            $this->assertSame($wrapBegin, $shortA + 2, 'Wrapped second choice starts immediately after the blank separator');

            for ($i = $wrapBegin; $i < $wrapTail; ++$i) {
                $this->assertNotSame('', trim($plainLines[$i]), 'No blank rows inside wrapped label');
            }
            for ($i = $wrapTail; $i < $descBegin; ++$i) {
                $this->assertNotSame('', trim($plainLines[$i]), 'No blank rows between wrapped label and its description');
            }
            for ($i = $descBegin; $i < $descTail; ++$i) {
                $this->assertNotSame('', trim($plainLines[$i]), 'No blank rows inside wrapped description');
            }

            $this->assertSame('', trim($plainLines[$descTail + 1] ?? 'missing'), 'Exactly one blank row after second logical choice');
            $this->assertSame($shortB, $descTail + 2, 'Third choice starts immediately after the blank separator');
            $this->assertNotSame('', trim($plainLines[\count($plainLines) - 1] ?? ''), 'No trailing blank after final visible choice');
        }
    }

    #[Test]
    public function testDownThenEnterReturnsExactLogicalValue(): void
    {
        $first = 'First short option';
        $second = 'SECOND_BEGIN '.str_repeat('second-wrap ', 6).'SECOND_TAIL';
        $widget = $this->widget([
            ['value' => $first, 'label' => $first],
            ['value' => $second, 'label' => $second],
        ], maxVisible: 10);

        $selected = null;
        $widget->onSelect(static function (SelectEvent $event) use (&$selected): void {
            $selected = $event->getValue();
        });

        $widget->handleInput("\x1b[B");
        $this->assertSame($second, $widget->getSelectedItem()['value'] ?? null);
        $widget->handleInput("\r");

        $this->assertSame($second, $selected);
        $this->assertTrue($widget->wasSelected());
    }

    #[Test]
    public function testLeftAndRightPageAcrossMaxVisibleOptionsUsingParentCursorActions(): void
    {
        $items = [];
        for ($i = 0; $i < 8; ++$i) {
            $label = \sprintf('OPTION_%02d', $i);
            $items[] = ['value' => $label, 'label' => $label];
        }

        $widget = $this->widget($items, maxVisible: 3);
        $kb = $widget->getKeybindings();

        // Parent SelectListWidget defaults still merge under SelectListKeybindings::standard().
        $this->assertSame(['left', 'ctrl+b'], $kb->getBindings('cursor_left'));
        $this->assertSame(['right', 'ctrl+f'], $kb->getBindings('cursor_right'));

        $this->assertSame('OPTION_00', $widget->getSelectedItem()['value'] ?? null);

        // Left/Ctrl+B share cursor_left; Right/Ctrl+F share cursor_right.
        $widget->handleInput("\x1b[C"); // Right → page down by maxVisible
        $this->assertSame('OPTION_03', $widget->getSelectedItem()['value'] ?? null);

        $widget->handleInput("\x06"); // Ctrl+F → same cursor_right / page-down path
        $this->assertSame('OPTION_06', $widget->getSelectedItem()['value'] ?? null);

        $widget->handleInput("\x1b[D"); // Left → page up by maxVisible
        $this->assertSame('OPTION_03', $widget->getSelectedItem()['value'] ?? null);

        $widget->handleInput("\x02"); // Ctrl+B → same cursor_left / page-up path
        $this->assertSame('OPTION_00', $widget->getSelectedItem()['value'] ?? null);

        // Explicit page keys still work through select_page_* actions.
        $widget->handleInput("\x1b[6~"); // Page Down
        $this->assertSame('OPTION_03', $widget->getSelectedItem()['value'] ?? null);
        $widget->handleInput("\x1b[5~"); // Page Up
        $this->assertSame('OPTION_00', $widget->getSelectedItem()['value'] ?? null);
    }

    #[Test]
    public function testEscapeCancelsWithoutSelecting(): void
    {
        $widget = $this->widget([
            ['value' => 'only', 'label' => 'only'],
        ]);

        $cancelled = false;
        $widget->onCancel(static function (CancelEvent $event) use (&$cancelled): void {
            $cancelled = true;
        });

        $widget->handleInput("\x1b");
        $this->assertTrue($cancelled);
        $this->assertFalse($widget->wasSelected());
    }

    #[Test]
    public function testResizeReflowsWhilePreservingSelectedItem(): void
    {
        $first = 'FIRST '.str_repeat('aaa ', 12).'END1';
        $second = 'SECOND '.str_repeat('bbb ', 12).'END2';
        $widget = $this->widget([
            ['value' => $first, 'label' => $first],
            ['value' => $second, 'label' => $second],
        ]);

        $widget->handleInput("\x1b[B");
        $this->assertSame($second, $widget->getSelectedItem()['value'] ?? null);

        $narrow = $this->plain($this->render($widget, 36));
        $this->assertStringContainsString('SECOND', $narrow);
        $this->assertStringContainsString('END2', $narrow);
        $this->assertStringNotContainsString('…', $narrow);

        $wide = $this->plain($this->render($widget, 100));
        $this->assertStringContainsString('SECOND', $wide);
        $this->assertStringContainsString('END2', $wide);
        $this->assertSame($second, $widget->getSelectedItem()['value'] ?? null);
    }

    #[Test]
    public function testAnsiStyledLabelsWrapWithoutBreakingEscapesOrExceedingWidth(): void
    {
        $styled = "\033[35mMAGENTA_BEGIN ".str_repeat('styled-token ', 8)."MAGENTA_TAIL\033[0m";
        $widget = $this->widget([
            ['value' => 'plain', 'label' => 'plain'],
            ['value' => 'styled', 'label' => $styled],
        ]);
        $widget->handleInput("\x1b[B");

        foreach ($this->render($widget, 42) as $line) {
            $this->assertLessThanOrEqual(42, AnsiUtils::visibleWidth($line));
            $this->assertSame(0, preg_match('/\x1b(?!\[[0-9;]*m)/', $line) ? 1 : 0);
        }

        $plain = $this->plain($this->render($widget, 42));
        $this->assertStringContainsString('MAGENTA_BEGIN', $plain);
        $this->assertStringContainsString('MAGENTA_TAIL', $plain);
    }

    /**
     * @param list<array{value: string, label: string, description?: string}> $items
     */
    private function widget(array $items, int $maxVisible = SelectListKeybindings::MAX_VISIBLE): QuestionChoiceListWidget
    {
        return new QuestionChoiceListWidget(
            items: $items,
            maxVisible: $maxVisible,
            keybindings: SelectListKeybindings::standard(),
        );
    }

    /** @return list<string> */
    private function render(QuestionChoiceListWidget $widget, int $width): array
    {
        $root = new ContainerWidget();
        $root->add($widget);

        return (new Renderer())->render($root, $width, 40);
    }

    /** @param list<string> $lines */
    private function plain(array $lines): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', implode("\n", $lines)) ?? '';
    }
}
