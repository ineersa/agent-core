<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Setup;

use Ineersa\Tui\Setup\SettingsTextInputWidget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SettingChangeEvent;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\SettingItem;
use Symfony\Component\Tui\Widget\SettingsListWidget;

/**
 * Test thesis: SettingsTextInputWidget applies submits through the vendor
 * SettingsListWidget submenu `$onDone` contract, turns rejected submits into
 * CancelEvent, and never renders secret input.
 */
#[CoversClass(SettingsTextInputWidget::class)]
final class SettingsTextInputWidgetTest extends TestCase
{
    /** @var list<string> */
    private array $changes = [];
    private int $cancels = 0;
    private SettingsListWidget $list;

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
    public function enterAppliesValueThroughSubmenu(string $sequence): void
    {
        $this->openSubmenu('abc');

        $this->list->handleInput('d');
        $this->list->handleInput($sequence);

        $this->assertSame(['abcd'], $this->changes);
        $this->assertSame(0, $this->cancels);
    }

    #[Test]
    #[DataProvider('provideBackspaceSequences')]
    public function backspaceDeletesLastCharacter(string $sequence): void
    {
        $this->openSubmenu('ab');

        $this->list->handleInput($sequence);
        $this->list->handleInput("\r");

        $this->assertSame(['a'], $this->changes);
    }

    #[Test]
    public function escapeCancelsWithoutChange(): void
    {
        $this->openSubmenu('abc');

        $this->list->handleInput('x');
        $this->list->handleInput("\x1b");

        $this->assertSame([], $this->changes);
        $this->assertSame(1, $this->cancels);
        $this->assertSubmenuClosed();
    }

    #[Test]
    public function unchangedSubmitCancelsWhenRequested(): void
    {
        $this->openSubmenu('abc', cancelUnchanged: true);

        $this->list->handleInput("\r");

        $this->assertSame([], $this->changes);
        $this->assertSame(1, $this->cancels);
        $this->assertSubmenuClosed();
    }

    #[Test]
    public function blankSecretSubmitCancels(): void
    {
        $this->openSubmenu('', secret: true);

        $this->list->handleInput(' ');
        $this->list->handleInput("\r");

        $this->assertSame([], $this->changes);
        $this->assertSame(1, $this->cancels);
    }

    #[Test]
    public function secretInputIsNeverRendered(): void
    {
        $widget = new SettingsTextInputWidget(static function (?string $value): void {}, secret: true);
        $widget->handleInput('hunter2');

        $rendered = implode("\n", $widget->render(new RenderContext(60, 5)));

        $this->assertStringNotContainsString('hunter2', $rendered);
        $this->assertStringContainsString('Hidden input', $rendered);
    }

    private function openSubmenu(string $current, bool $secret = false, bool $cancelUnchanged = false): void
    {
        $item = new SettingItem('field', 'Field', $current, submenu: function (string $value, callable $done) use ($secret, $cancelUnchanged): SettingsTextInputWidget {
            $widget = new SettingsTextInputWidget($done, $value, secret: $secret, cancelUnchanged: $cancelUnchanged);
            $widget->onCancel(function (CancelEvent $event): void {
                ++$this->cancels;
            });

            return $widget;
        });
        $this->list = new SettingsListWidget([$item]);
        $this->list->onChange(function (SettingChangeEvent $event): void {
            $this->changes[] = $event->getValue();
        });
        $tui = new Tui(terminal: new VirtualTerminal(columns: 60, rows: 10));
        $tui->add($this->list);
        $tui->setFocus($this->list);

        $this->list->handleInput("\r");
    }

    private function assertSubmenuClosed(): void
    {
        // A closed submenu returns input to the list: Enter reopens a fresh editor.
        $this->list->handleInput("\r");
        $this->list->handleInput('z');
        $this->list->handleInput("\r");
        $this->assertSame(['abcz'], $this->changes);
    }
}
