<?php

declare(strict_types=1);

namespace Ineersa\Tui\Widget;

use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * Standard SelectListWidget interaction policy shared by pickers,
 * questions, and the completion menu.
 *
 * Core select actions: up/down/page-up/page-down/confirm/cancel.
 * cursor_left/cursor_right are deliberately omitted so Left/Right
 * stay available to callers. ModelPickerController adds
 * toggle_favorite (ctrl+f) on top of this baseline.
 */
final class SelectListKeybindings
{
    /** Visible rows for the shared select lists (pickers, questions, completion). */
    public const MAX_VISIBLE = 10;

    /**
     * @return array<string, list<string>>
     */
    public static function standardBindings(): array
    {
        return [
            'select_up' => [Key::UP],
            'select_down' => [Key::DOWN],
            'select_page_up' => [Key::PAGE_UP],
            'select_page_down' => [Key::PAGE_DOWN],
            'select_confirm' => [Key::ENTER],
            'select_cancel' => [Key::ESCAPE, Key::ctrl('c')],
        ];
    }

    public static function standard(): Keybindings
    {
        return new Keybindings(self::standardBindings());
    }

    public static function withFavoriteToggle(): Keybindings
    {
        return new Keybindings([
            ...self::standardBindings(),
            'toggle_favorite' => [Key::ctrl('f')],
        ]);
    }

    public static function withSpaceFavoriteToggle(): Keybindings
    {
        return new Keybindings([
            ...self::standardBindings(),
            'toggle_favorite_space' => [Key::SPACE],
        ]);
    }
}
