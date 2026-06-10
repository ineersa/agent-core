<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command\Hotkey;

/**
 * Tagged-provider seam for contributing display-only hotkey hints.
 *
 * Services tagged with `app.hotkey_provider` are collected at container
 * build time and their hints are registered in {@see HotkeyRegistry}
 * during TUI startup.
 *
 * This is internal infrastructure. Extensions that want hotkey hints
 * would need a bridge from {@see ExtensionApiInterface} through the
 * extension loader to a provider implementation — that bridge is a
 * future follow-up and is not implemented in this task.
 *
 * All concrete implementations receive the hotkey registry via DI
 * and call {@see HotkeyRegistry::add()} during their register phase.
 */
interface HotkeyProviderInterface
{
    /**
     * Register hotkey hints with the given registry.
     *
     * Called once during TUI startup, before the interactive loop.
     */
    public function register(HotkeyRegistry $registry): void;
}
