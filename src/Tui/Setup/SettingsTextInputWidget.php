<?php

declare(strict_types=1);

namespace Ineersa\Tui\Setup;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\InputWidget;

/**
 * Text input for SettingsListWidget submenus.
 *
 * SettingsListWidget passes `$onDone` to the submenu factory; submit applies the
 * value through it. Rejected submits (blank secret, unchanged value) dispatch
 * CancelEvent instead: SettingsListWidget routes that to `$onDone(null)`, and
 * callers' onCancel hooks still run.
 */
final class SettingsTextInputWidget extends InputWidget
{
    /**
     * @param callable(?string): void $onDone
     */
    public function __construct(callable $onDone, string $initial = '', private readonly bool $secret = false, bool $cancelUnchanged = false)
    {
        parent::__construct();
        $this->setValue($initial);
        // SettingsListWidget forwards input while keeping focus itself; the
        // submenu must mark itself focused for InputWidget to render its cursor.
        $this->setFocused(true);
        // Compare against the sanitized value InputWidget actually holds.
        $initialValue = $this->getValue();
        $this->onSubmit(function (SubmitEvent $event) use ($onDone, $initialValue, $cancelUnchanged): void {
            $value = $event->getValue();
            if (($this->secret && '' === trim($value)) || ($cancelUnchanged && $value === $initialValue)) {
                $this->dispatch(new CancelEvent($this));

                return;
            }
            $onDone($value);
        });
    }

    /**
     * @return list<string>
     */
    public function render(RenderContext $context): array
    {
        if ($this->secret) {
            return [AnsiUtils::truncateToWidth('Hidden input · Enter applies · Esc cancels', $context->getColumns(), '')];
        }

        return parent::render($context);
    }
}
