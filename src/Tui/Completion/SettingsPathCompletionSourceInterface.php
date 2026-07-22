<?php

declare(strict_types=1);

namespace Ineersa\Tui\Completion;

/** Fresh effective settings for path completion without AppConfig in TuiCompletion. */
interface SettingsPathCompletionSourceInterface
{
    /** @return array<string, mixed> */
    public function loadEffectiveSettings(): array;
}
