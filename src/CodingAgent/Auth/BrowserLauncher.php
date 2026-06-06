<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Auth;

use Symfony\Component\Process\Process;

/**
 * Cross-platform browser URL launcher.
 *
 * Uses Symfony Process to run the platform's default browser command:
 *  - Linux:    xdg-open
 *  - macOS:    open
 *  - Windows:  cmd /c start
 *
 * Silently degrades when no browser command is available.
 */
final class BrowserLauncher
{
    /**
     * Try to open a URL in the default browser.
     *
     * @return bool True if the browser command was launched successfully
     */
    public static function open(string $url): bool
    {
        $command = self::detectCommand($url);

        if (null === $command) {
            return false;
        }

        try {
            $process = new Process($command);
            $process->setTimeout(5);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether this platform supports browser auto-open.
     */
    public static function isSupported(): bool
    {
        return null !== self::detectCommand('http://localhost');
    }

    /**
     * @return list<string>|null Shell command with arguments, or null if undetectable
     */
    private static function detectCommand(string $url): ?array
    {
        return match (\PHP_OS_FAMILY) {
            'Linux' => ['xdg-open', $url],
            'Darwin' => ['open', $url],
            'Windows' => ['cmd', '/c', 'start', '', $url],
            default => null,
        };
    }
}
