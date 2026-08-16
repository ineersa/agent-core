<?php

declare(strict_types=1);

namespace Ineersa\Tests\Tui\Widget;

use Ineersa\Tui\Widget\SelectListKeybindings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Input\Key;

/**
 * Shared SelectList interaction policy used by pickers, questions,
 * and the completion menu.
 *
 * @covers \Ineersa\Tui\Widget\SelectListKeybindings
 */
final class SelectListKeybindingsTest extends TestCase
{
    public function testStandardExposesExactlyTheSixActions(): void
    {
        $kb = SelectListKeybindings::standard();

        $this->assertSame([Key::UP], $kb->getBindings('select_up'));
        $this->assertSame([Key::DOWN], $kb->getBindings('select_down'));
        $this->assertSame([Key::PAGE_UP], $kb->getBindings('select_page_up'));
        $this->assertSame([Key::PAGE_DOWN], $kb->getBindings('select_page_down'));
        $this->assertSame([Key::ENTER], $kb->getBindings('select_confirm'));
        $this->assertSame([Key::ESCAPE, Key::ctrl('c')], $kb->getBindings('select_cancel'));

        // cursor_left/cursor_right must stay unbound so Left/Right and
        // Ctrl+F remain available to caller onInput handlers.
        $this->assertSame([], $kb->getBindings('cursor_left'));
        $this->assertSame([], $kb->getBindings('cursor_right'));
    }

    public function testMaxVisibleRowsIsTen(): void
    {
        $this->assertSame(10, SelectListKeybindings::MAX_VISIBLE);
    }
}
