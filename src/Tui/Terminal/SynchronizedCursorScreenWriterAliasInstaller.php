<?php

declare(strict_types=1);

namespace Ineersa\Tui\Terminal;

/**
 * Installs Hatfield's ScreenWriter before Symfony TUI constructs its final writer.
 *
 * Symfony TUI 8.1 does not expose ScreenWriter injection. Keep this installer
 * until the component restores the cursor within synchronized output upstream.
 */
final class SynchronizedCursorScreenWriterAliasInstaller
{
    private const string SYMFONY_SCREEN_WRITER = 'Symfony\\Component\\Tui\\Render\\ScreenWriter';

    public static function install(): void
    {
        if (class_exists(self::SYMFONY_SCREEN_WRITER, false)) {
            if (SynchronizedCursorScreenWriter::class === (new \ReflectionClass(self::SYMFONY_SCREEN_WRITER))->getName()) {
                return;
            }

            throw new \RuntimeException('Synchronized cursor ScreenWriter must be installed before Symfony ScreenWriter loads.');
        }

        if (!class_exists(SynchronizedCursorScreenWriter::class, true)) {
            throw new \RuntimeException('Synchronized cursor ScreenWriter could not be loaded.');
        }

        if (!class_alias(SynchronizedCursorScreenWriter::class, self::SYMFONY_SCREEN_WRITER, false)) {
            throw new \RuntimeException('Unable to install synchronized cursor ScreenWriter.');
        }
    }
}
