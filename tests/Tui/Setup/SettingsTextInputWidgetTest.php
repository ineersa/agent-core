<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Setup;

use Ineersa\Tui\Setup\SettingsTextInputWidget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\Util\Line;

/**
 * Test thesis: SettingsTextInputWidget Enter/Backspace identity uses Symfony
 * keybindings for both legacy and Kitty forms.
 */
#[CoversClass(SettingsTextInputWidget::class)]
final class SettingsTextInputWidgetTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideEnterSequences(): iterable
    {
        yield 'legacy-cr' => ["\r"];
        yield 'legacy-lf' => ["\n"];
        yield 'kitty' => ["\x1b[13u"];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideBackspaceSequences(): iterable
    {
        yield 'legacy-del' => ["\x7f"];
        yield 'legacy-bs' => ["\x08"];
        yield 'kitty' => ["\x1b[127u"];
    }

    #[Test]
    #[DataProvider('provideEnterSequences')]
    public function enterDispatchesSelectEvent(string $sequence): void
    {
        $widget = new SettingsTextInputWidget('abc');
        $selected = null;
        $widget->onSelect(static function (SelectEvent $event) use (&$selected): void {
            $selected = $event->getValue();
        });
        $this->mount($widget);

        $widget->handleInput($sequence);

        $this->assertSame('abc', $selected);
    }

    #[Test]
    #[DataProvider('provideBackspaceSequences')]
    public function backspaceDeletesLastCharacter(string $sequence): void
    {
        $widget = new SettingsTextInputWidget('ab');
        $this->mount($widget);

        $widget->handleInput($sequence);

        $line = (new \ReflectionProperty(SettingsTextInputWidget::class, 'line'))->getValue($widget);
        $this->assertInstanceOf(Line::class, $line);
        $this->assertSame('a', $line->getText());
    }

    private function mount(SettingsTextInputWidget $widget): void
    {
        $tui = new Tui(terminal: new VirtualTerminal(columns: 40, rows: 10));
        $tui->add($widget);
        $tui->setFocus($widget);
    }
}
