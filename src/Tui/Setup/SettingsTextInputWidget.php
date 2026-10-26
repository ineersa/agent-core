<?php

declare(strict_types=1);

namespace Ineersa\Tui\Setup;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\BracketedPasteTrait;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\Util\Line;
use Symfony\Component\Tui\Widget\Util\StringUtils;

/**
 * Text input that satisfies SettingsListWidget's submenu contract.
 *
 * SettingsListWidget only closes submenus on SelectEvent/CancelEvent, but
 * InputWidget fires SubmitEvent — and SelectEvent's constructor requires a
 * SelectListWidget target. This thin bridge looks like an InputWidget and
 * dispatches SelectEvent on Enter so the vendor submenu lifecycle works.
 */
final class SettingsTextInputWidget extends SelectListWidget
{
    use BracketedPasteTrait;

    private Line $line;
    private string $prompt;

    public function __construct(string $initial = '', string $prompt = '> ')
    {
        parent::__construct([]);
        $this->line = new Line($initial, \strlen($initial));
        $this->prompt = $prompt;
        $this->setKeybindings(new Keybindings([
            'delete_char_backward' => [Key::BACKSPACE, 'shift+backspace'],
            'cursor_left' => [Key::LEFT, 'ctrl+b'],
            'cursor_right' => [Key::RIGHT, 'ctrl+f'],
            'cursor_start' => [Key::HOME, 'ctrl+a'],
            'cursor_end' => [Key::END, 'ctrl+e'],
            'delete_char_forward' => [Key::DELETE],
        ]));
    }

    public function handleInput(string $data): void
    {
        // Do not touch parent KeybindingsTrait::$onInput (private to SelectListWidget).
        // Ctrl+D quit is wired on the SettingsListWidget itself; Esc cancels this submenu.

        $pasted = $this->processBracketedPaste($data);
        if (null !== $pasted) {
            $this->line->insert($pasted);
            $this->invalidate();
        }
        if ('' === $data) {
            return;
        }

        $kb = $this->getKeybindings();
        if ($kb->matches($data, 'select_cancel')) {
            $this->dispatch(new CancelEvent($this));

            return;
        }
        if ($kb->matches($data, 'select_confirm')) {
            $this->dispatch(new SelectEvent($this, [
                'value' => $this->line->getText(),
                'label' => $this->line->getText(),
            ]));

            return;
        }

        $edited = match (true) {
            $kb->matches($data, 'delete_char_backward') => $this->line->deleteCharBackward(),
            $kb->matches($data, 'delete_char_forward') => $this->line->deleteCharForward(),
            $kb->matches($data, 'cursor_left') => $this->line->moveCursorLeft(),
            $kb->matches($data, 'cursor_right') => $this->line->moveCursorRight(),
            $kb->matches($data, 'cursor_start') => $this->line->moveCursorToStart(),
            $kb->matches($data, 'cursor_end') => $this->line->moveCursorToEnd(),
            default => null,
        };
        if (null !== $edited) {
            $this->invalidate();

            return;
        }

        // Accept full UTF-8 (CJK/emoji); strip control bytes so escapes can't inject.
        $text = StringUtils::stripControlBytes(StringUtils::sanitizeUtf8($data));
        if ('' !== $text) {
            $this->line->insert($text);
            $this->invalidate();
        }
    }

    /**
     * @return list<string>
     */
    public function render(RenderContext $context): array
    {
        $width = max(0, $context->getColumns() - AnsiUtils::visibleWidth($this->prompt));
        $before = substr($this->line->getText(), 0, $this->line->getCursor());
        $after = substr($this->line->getText(), $this->line->getCursor());
        $offset = max(0, AnsiUtils::visibleWidth($before) - $width + 1);

        return [AnsiUtils::truncateToWidth($this->prompt.AnsiUtils::sliceByColumn($before.'█'.$after, $offset, $width, true), $context->getColumns(), '')];
    }
}
