<?php

declare(strict_types=1);

namespace Ineersa\Tui\Terminal;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Installs the app-owned ScreenWriter under Symfony's private ScreenWriter FQCN.
 *
 * Symfony TUI constructs `new ScreenWriter($terminal)` inside a private
 * property of Tui, and ScreenWriter itself is `final`/`@internal`. There is no
 * public injection seam.
 *
 * This installer uses an early `class_alias` so that the first autoload of
 * `Symfony\Component\Tui\Render\ScreenWriter` resolves to
 * {@see PiStyleScreenWriter} instead of the vendor class. Tui then constructs
 * the app implementation under the aliased FQCN.
 *
 * Install order (hard requirement):
 * 1. Call {@see install()} once before the first `new Symfony\Component\Tui\Tui`.
 * 2. If the real vendor ScreenWriter is already loaded, fail loudly — never
 *    silently fall back to the stock writer.
 * 3. Repeat calls are idempotent for InteractiveMode session-switch loops.
 *
 * Global process risk: the alias is process-wide. Any code in the same PHP
 * process that later expects the stock Symfony ScreenWriter by FQCN will get
 * the app implementation.
 */
final class PiStyleScreenWriterAliasInstaller
{
    private const string SYMFONY_SCREEN_WRITER_FQCN = 'Symfony\\Component\\Tui\\Render\\ScreenWriter';

    private static bool $installed = false;

    private static bool $activationLogged = false;

    /**
     * Install the class_alias if not already installed.
     *
     * @throws \RuntimeException when the real vendor ScreenWriter is already loaded
     *                           or the alias cannot be registered
     */
    public static function install(?LoggerInterface $logger = null): void
    {
        $logger ??= new NullLogger();

        if (self::$installed) {
            self::assertAliasStillOwnedByAppWriter();
            self::logActivationOnce($logger);

            return;
        }

        if (class_exists(self::SYMFONY_SCREEN_WRITER_FQCN, false)) {
            // Already defined in this process — either our alias or the vendor class.
            $reflection = new \ReflectionClass(self::SYMFONY_SCREEN_WRITER_FQCN);
            if (self::isAppWriterImplementation($reflection)) {
                self::$installed = true;
                self::logActivationOnce($logger);

                return;
            }

            throw new \RuntimeException('Pi-style ScreenWriter alias install failed: Symfony\\Component\\Tui\\Render\\ScreenWriter is already loaded from '.$reflection->getFileName().'. Call PiStyleScreenWriterAliasInstaller::install() before the first `new Symfony\\Component\\Tui\\Tui` (and before any other code autoloads ScreenWriter). No silent fallback is allowed.');
        }

        // Ensure the app class is loaded before aliasing so the alias target exists.
        if (!class_exists(PiStyleScreenWriter::class, true)) {
            throw new \RuntimeException('Pi-style ScreenWriter alias install failed: '.PiStyleScreenWriter::class.' could not be autoloaded.');
        }

        if (!class_alias(PiStyleScreenWriter::class, self::SYMFONY_SCREEN_WRITER_FQCN, false)) {
            throw new \RuntimeException('Pi-style ScreenWriter alias install failed: class_alias() returned false for '.self::SYMFONY_SCREEN_WRITER_FQCN.'.');
        }

        self::$installed = true;
        self::assertAliasStillOwnedByAppWriter();
        self::logActivationOnce($logger);
    }

    /**
     * Whether the process currently has the app writer alias installed.
     */
    public static function isInstalled(): bool
    {
        if (!self::$installed) {
            return false;
        }

        if (!class_exists(self::SYMFONY_SCREEN_WRITER_FQCN, false)) {
            return false;
        }

        return self::isAppWriterImplementation(new \ReflectionClass(self::SYMFONY_SCREEN_WRITER_FQCN));
    }

    private static function assertAliasStillOwnedByAppWriter(): void
    {
        if (!class_exists(self::SYMFONY_SCREEN_WRITER_FQCN, false)) {
            throw new \RuntimeException('Pi-style ScreenWriter alias install state is corrupted: FQCN is not defined after install.');
        }

        $reflection = new \ReflectionClass(self::SYMFONY_SCREEN_WRITER_FQCN);
        if (!self::isAppWriterImplementation($reflection)) {
            throw new \RuntimeException('Pi-style ScreenWriter alias install state is corrupted: '.self::SYMFONY_SCREEN_WRITER_FQCN.' resolves to '.$reflection->getName().' from '.$reflection->getFileName().' instead of PiStyleScreenWriter.');
        }
    }

    /**
     * @param \ReflectionClass<*> $reflection
     */
    private static function isAppWriterImplementation(\ReflectionClass $reflection): bool
    {
        return PiStyleScreenWriter::class === $reflection->getName()
            || str_ends_with(str_replace('\\', '/', (string) $reflection->getFileName()), '/PiStyleScreenWriter.php');
    }

    private static function logActivationOnce(LoggerInterface $logger): void
    {
        if (self::$activationLogged) {
            return;
        }

        self::$activationLogged = true;
        $logger->info('tui.screen_writer.alias.activated', [
            'component' => 'tui',
            'event_type' => 'tui.screen_writer.alias.activated',
            'policy' => 'pi_style_class_alias',
            'algorithm' => 'pi_native_viewport_bookkeeping',
            'alias_fqcn' => self::SYMFONY_SCREEN_WRITER_FQCN,
            'implementation' => PiStyleScreenWriter::class,
            'fail_fast_if_vendor_loaded' => true,
            'preserves_native_scrollback' => true,
            'scroll_offset_default' => 0,
        ]);
    }
}
