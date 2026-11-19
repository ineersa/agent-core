<?php

declare(strict_types=1);

namespace Ineersa\Tui\Setup;

use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\SettingItem;
use Symfony\Component\Tui\Widget\SettingsListWidget;

final class ScopedSettingsListWidget extends SettingsListWidget
{
    /** @var array<string, bool> */
    private array $local = [];

    /** @param list<SettingItem> $rows */
    public function __construct(private readonly array $rows, private readonly TuiTheme $theme)
    {
        parent::__construct($rows, maxVisible: 12);
    }

    public function setLocal(string $key, bool $exists): void
    {
        $this->local[$key] = $exists;
        $this->invalidate();
    }

    public function canRemove(): bool
    {
        return \in_array(true, $this->local, true);
    }

    public function render(RenderContext $context): array
    {
        if ([] !== $this->all()) {
            return parent::render($context);
        }
        // Decorate only the native render pass. Real row values must stay intact
        // for native choice cycling and submenu initialization.
        $values = [];
        foreach ($this->rows as $i => $row) {
            $values[$i] = $row->getCurrentValue();
            $row->setCurrentValue(($this->local[$row->getId()] ? '●' : '○').' '.$values[$i]);
        }
        try {
            return array_map(fn (string $line): string => str_replace(['● ', '○ '], [$this->theme->success('●').' ', $this->theme->error('○').' '], $line), parent::render($context));
        } finally {
            foreach ($this->rows as $i => $row) {
                $row->setCurrentValue($values[$i]);
            }
        }
    }
}
