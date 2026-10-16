<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Process;

/**
 * Ensures Amp can resolve micro.php_binary=hatfield-php after relocation.
 *
 * Fused natives ship beside either hatfield-php or hatfield.<target>.php. Amp
 * launches PHP_BINARY as a PATH basename, so this bootstrap prepends a private
 * directory that contains an executable named hatfield-php. Install directories
 * need not be on the caller PATH. Host/build-tree PHP is never used as fallback.
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
        $phpBinary = \defined('PHP_BINARY') ? (string) \constant('PHP_BINARY') : '';

        return self::ensureFor($self, $phpBinary);
    }

    /**
     * Explicit-input form of ensure() for packaging proofs and callers that
     * already resolved the fused self path and PHP_BINARY value.
     *
     * @return bool True when PATH was adjusted for the owned CLI
     */
    public static function ensureFor(string $self, string $phpBinary): bool
    {
        if ('' === $self) {
            return false;
        }

        $selfReal = realpath($self);
        if (false === $selfReal || !is_file($selfReal)) {
            return false;
        }

        if (self::CLI_BASENAME !== $phpBinary && self::CLI_BASENAME !== basename($phpBinary)) {
            return false;
        }

        $helper = self::resolveAdjacentHelper($selfReal);
        if (null === $helper) {
            fwrite(\STDERR, \sprintf(
                "Error: fused Hatfield binary %s requires adjacent owned PHP CLI %s or %s.php\n",
                $selfReal,
                self::CLI_BASENAME,
                basename($selfReal),
            ));
            exit(1);
        }

        $pathDir = self::stagePathDirectory($selfReal, $helper);
        self::prependPath($pathDir);

        return true;
    }

    private static function resolveAdjacentHelper(string $selfReal): ?string
    {
        $dirReal = realpath(\dirname($selfReal));
        if (false === $dirReal || !is_dir($dirReal)) {
            return null;
        }

        foreach ([
            $dirReal.\DIRECTORY_SEPARATOR.self::CLI_BASENAME,
            $dirReal.\DIRECTORY_SEPARATOR.basename($selfReal).'.php',
        ] as $candidate) {
            $resolved = self::acceptSameDirectoryHelper($dirReal, $candidate);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        return null;
    }

    private static function acceptSameDirectoryHelper(string $dirReal, string $candidate): ?string
    {
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

    private static function stagePathDirectory(string $selfReal, string $helperReal): string
    {
        if (self::CLI_BASENAME === basename($helperReal)) {
            return \dirname($helperReal);
        }

        $stageRoot = rtrim(sys_get_temp_dir(), '/\\').'/hatfield-owned-php-'.hash('sha256', $selfReal."\0".$helperReal);
        if (!is_dir($stageRoot) && !mkdir($stageRoot, 0700, true) && !is_dir($stageRoot)) {
            fwrite(\STDERR, \sprintf("Error: unable to create owned PHP CLI stage directory: %s\n", $stageRoot));
            exit(1);
        }
        @chmod($stageRoot, 0700);

        $staged = $stageRoot.\DIRECTORY_SEPARATOR.self::CLI_BASENAME;
        if (file_exists($staged)) {
            $existing = realpath($staged);
            if (false !== $existing && $existing === $helperReal) {
                return $stageRoot;
            }
            unlink($staged);
        }

        if (!@symlink($helperReal, $staged) && !@link($helperReal, $staged)) {
            if (!@copy($helperReal, $staged)) {
                fwrite(\STDERR, \sprintf(
                    "Error: unable to stage owned PHP CLI %s as %s\n",
                    $helperReal,
                    $staged,
                ));
                exit(1);
            }
            chmod($staged, 0755);
        }

        $stagedReal = realpath($staged);
        if (false === $stagedReal || !is_executable($stagedReal) || 0 === filesize($stagedReal)) {
            fwrite(\STDERR, \sprintf("Error: staged owned PHP CLI is not executable: %s\n", $staged));
            exit(1);
        }

        return $stageRoot;
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
