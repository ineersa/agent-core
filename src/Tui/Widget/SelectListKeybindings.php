<?php

declare(strict_types=1);

namespace Ineersa\Tui\Widget;

use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * Standard SelectListWidget interaction policy shared by pickers,
 * questions, and the completion menu.
 *
 * Six actions only: up/down/page-up/page-down/confirm/cancel.
 * cursor_left/cursor_right are deliberately omitted so Left/Right
 * and Ctrl+F stay available to caller onInput handlers (e.g. the
 * Ctrl+F favorite toggle in ModelPickerController).
 */
final class SelectListKeybindings
{
    /** Visible rows for the shared select lists (pickers, questions, completion). */
    public const MAX_VISIBLE = 10;

    public static function standard(): Keybindings
    {
        return new Keybindings([
            'select_up' => [Key::UP],
            'select_down' => [Key::DOWN],
            'select_page_up' => [Key::PAGE_UP],
            'select_page_down' => [Key::PAGE_DOWN],
            'select_confirm' => [Key::ENTER],
            'select_cancel' => [Key::ESCAPE, Key::ctrl('c')],
        ]);
    }
}
