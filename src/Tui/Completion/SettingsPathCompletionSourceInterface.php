<?php

declare(strict_types=1);

namespace Ineersa\Tui\Completion;

/**
 * Source of fresh effective settings maps for path completion.
 *
 * Implementations bridge AppConfig loading without leaking AppConfig
 * dependencies into the TuiCompletion layer (same pattern as
 * {@see SessionCompletionSourceInterface}).
 */
interface SettingsPathCompletionSourceInterface
{
    /**
     * Fresh effective settings tree (defaults < user < project).
     *
     * @return array<string, mixed>
     */
    public function loadEffectiveSettings(): array;
}
