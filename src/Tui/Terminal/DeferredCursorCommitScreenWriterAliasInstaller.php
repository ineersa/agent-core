<?php

declare(strict_types=1);

namespace Ineersa\Tui\Terminal;

/**
 * Installs Hatfield's ScreenWriter before Symfony TUI constructs its final writer.
 *
 * Symfony TUI 8.1 does not expose ScreenWriter injection. Keep this installer
 * until the component provides that seam upstream.
 */
final class DeferredCursorCommitScreenWriterAliasInstaller
{
    private const string SYMFONY_SCREEN_WRITER = 'Symfony\\Component\\Tui\\Render\\ScreenWriter';

    public static function install(): void
    {
        if (class_exists(self::SYMFONY_SCREEN_WRITER, false)) {
            if (DeferredCursorCommitScreenWriter::class === (new \ReflectionClass(self::SYMFONY_SCREEN_WRITER))->getName()) {
                return;
            }

            throw new \RuntimeException('Deferred cursor ScreenWriter must be installed before Symfony ScreenWriter loads.');
        }

        if (!class_exists(DeferredCursorCommitScreenWriter::class, true)) {
            throw new \RuntimeException('Deferred cursor ScreenWriter could not be loaded.');
        }

        if (!class_alias(DeferredCursorCommitScreenWriter::class, self::SYMFONY_SCREEN_WRITER, false)) {
            throw new \RuntimeException('Unable to install deferred cursor ScreenWriter.');
        }
    }
}
