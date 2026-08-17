<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\SlashCommandCatalog;

/**
 * Contract for command registrars that seed the process-scoped
 * {@see SlashCommandCatalog} once per process.
 *
 * Command metadata/aliases/help data is registered exactly once here;
 * the per-session {@see \Ineersa\Tui\Command\SlashCommandRegistry} then
 * binds only the current session's handlers in
 * {@see TuiListenerRegistrar::register()}.
 *
 * Called by InteractiveMode before the session switch loop, in
 * tag-priority order (same ordering as the listener registrars).
 */
interface SlashCommandCatalogRegistrar
{
    public function registerCatalog(SlashCommandCatalog $catalog): void;
}
