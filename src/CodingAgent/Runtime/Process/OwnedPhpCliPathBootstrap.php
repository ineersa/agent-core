<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Process;

/**
 * Ensures Amp can resolve micro.php_binary=hatfield-php after relocation.
 *
 * Fused natives ship beside a single adjacent helper named hatfield-php. Amp
 * launches PHP_BINARY as a PATH basename, so this bootstrap prepends that
 * helper's directory. Install directories need not be on the caller PATH.
 * Host/build-tree PHP is never used as fallback. No temporary links or copies.
 */
final class OwnedPhpCliPathBootstrap
{
    public const string CLI_BASENAME = 'hatfield-php';

    /**
     * Fail closed when a fused native expects the owned CLI and it is missing.
     *
     * @return bool True when PATH was adjusted for the owned CLI
     */
    public static function ensure(): bool
    {
        $self = \function_exists('micro_get_self_filename') ? (string) micro_get_self_filename() : '';
        if ('' === $self) {
            return false;
        }

        $phpBinary = \defined('PHP_BINARY') ? (string) \constant('PHP_BINARY') : '';
        if (self::CLI_BASENAME !== $phpBinary && self::CLI_BASENAME !== basename($phpBinary)) {
            return false;
        }

        $selfReal = realpath($self);
        if (false === $selfReal || !is_file($selfReal)) {
            return false;
        }

        $helper = self::resolveAdjacentHelper($selfReal);
        if (null === $helper) {
            fwrite(\STDERR, \sprintf(
                "Error: fused Hatfield binary %s requires adjacent owned PHP CLI %s\n",
                $selfReal,
                self::CLI_BASENAME,
            ));
            exit(1);
        }

        self::prependPath(\dirname($helper));

        return true;
    }

    private static function resolveAdjacentHelper(string $selfReal): ?string
    {
        $dirReal = realpath(\dirname($selfReal));
        if (false === $dirReal || !is_dir($dirReal)) {
            return null;
        }

        $candidate = $dirReal.\DIRECTORY_SEPARATOR.self::CLI_BASENAME;
        if (!file_exists($candidate)) {
            return null;
        }

        $real = realpath($candidate);
        if (false === $real || !is_file($real) || is_dir($real)) {
            return null;
        }
        if (\dirname($real) !== $dirReal) {
            return null;
        }
        if (!is_executable($real) || 0 === filesize($real)) {
            return null;
        }

        return $real;
    }

    private static function prependPath(string $directory): void
    {
        $current = getenv('PATH');
        $parts = [];
        if (false !== $current && '' !== $current) {
            foreach (explode(\PATH_SEPARATOR, $current) as $part) {
                if ('' !== $part) {
                    $parts[] = $part;
                }
            }
        }
        array_unshift($parts, $directory);
        $path = implode(\PATH_SEPARATOR, array_values(array_unique($parts)));
        putenv('PATH='.$path);
        $_ENV['PATH'] = $path;
        $_SERVER['PATH'] = $path;
    }
}
