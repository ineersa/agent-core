<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Command\SlashCommandRegistry;

/**
 * Contract for registrars that add dynamic commands (code review, prompt
 * templates, skills) to the process-scoped {@see SlashCommandCatalog}.
 *
 * Built-in commands are static data registered when the catalog is
 * constructed, and their handlers are bound per session in the
 * {@see SlashCommandRegistry}. These registrars instead read runtime state
 * (discovered skills and templates), so InteractiveMode runs them once,
 * before the session switch loop and after extensions have loaded and CLI
 * options are applied. They skip any name that is already taken.
 */
interface SlashCommandCatalogRegistrar
{
    public function registerCatalog(SlashCommandCatalog $catalog): void;
}
