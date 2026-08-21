<?php

declare(strict_types=1);

namespace Ineersa\Tui\Setup;

use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\BracketedPasteTrait;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\Util\Line;

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
        $this->line = new Line($initial);
        $this->prompt = $prompt;
    }

    public function getValue(): string
    {
        return $this->line->getText();
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
        if ($kb->matches($data, 'select_confirm') || "\n" === $data || "\r" === $data) {
            $this->dispatch(new SelectEvent($this, [
                'value' => $this->line->getText(),
                'label' => $this->line->getText(),
            ]));

            return;
        }

        // Backspace / delete
        if ("\x7f" === $data || "\x08" === $data) {
            $text = $this->line->getText();
            if ('' !== $text) {
                $this->line->setText(mb_substr($text, 0, -1));
                $this->invalidate();
            }

            return;
        }

        // Ignore other control bytes; insert printable / UTF-8 text.
        if (1 === preg_match('/^[\x20-\x7e\x80-\xff]+$/u', $data)) {
            $this->line->insert($data);
            $this->invalidate();
        }
    }

    /**
     * @return list<string>
     */
    public function render(RenderContext $context): array
    {
        return [$this->prompt.$this->line->getText().'█'];
    }

    /**
     * @return array<string, list<string>>
     */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'select_confirm' => [Key::ENTER],
            'select_cancel' => [Key::ESCAPE, 'ctrl+c'],
        ];
    }
}
