<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

/**
 * Formats code_mode stdout/stderr for model-facing diagnostics.
 *
 * Warning duplication and Xdebug stacks are prevented at process start
 * (display_errors=0, xdebug.mode=off, log_errors=1). This helper only
 * normalizes script/bootstrap paths and renders labeled stream sections.
 * Size limits belong to host stream tails and ordinary OutputCap, not a
 * separate diagnostics truncation marker.
 *
 * @internal
 */
final class CodeModeDiagnostics
{
    /**
     * @return array{stdout?: string, stderr?: string}
     */
    public static function prepare(?string $stdout, ?string $stderr, int $wrapperPrefixLines = 3): array
    {
        $stdout = self::normalizePaths(trim((string) $stdout), $wrapperPrefixLines);
        $stderr = self::normalizePaths(trim((string) $stderr), $wrapperPrefixLines);

        return array_filter([
            'stdout' => $stdout,
            'stderr' => $stderr,
        ], static fn (string $chunk): bool => '' !== $chunk);
    }

    /**
     * @param array{stdout?: string, stderr?: string} $diagnostics
     */
    public static function renderBlock(array $diagnostics): string
    {
        $sections = [];
        $stdout = trim((string) ($diagnostics['stdout'] ?? ''));
        $stderr = trim((string) ($diagnostics['stderr'] ?? ''));
        if ('' !== $stdout) {
            $sections[] = "stdout:\n".$stdout;
        }
        if ('' !== $stderr) {
            $sections[] = "stderr:\n".$stderr;
        }

        if ([] === $sections) {
            return '';
        }

        return "code_mode diagnostics\n".implode("\n\n", $sections);
    }

    /**
     * Append already-prepared stdout/stderr sections to an early-exit message.
     *
     * @param array{stdout?: string, stderr?: string} $diagnostics
     */
    public static function appendToMessage(string $message, array $diagnostics): string
    {
        $stdout = trim((string) ($diagnostics['stdout'] ?? ''));
        $stderr = trim((string) ($diagnostics['stderr'] ?? ''));
        if ('' !== $stdout) {
            $message .= "\nstdout:\n".$stdout;
        }
        if ('' !== $stderr) {
            $message .= "\nstderr:\n".$stderr;
        }

        return $message;
    }

    public static function normalizePaths(string $text, int $wrapperPrefixLines = 3): string
    {
        $replace = static function (string $file, int $line) use ($wrapperPrefixLines): string {
            if ('script' === $file && $line > $wrapperPrefixLines) {
                $line -= $wrapperPrefixLines;
            }

            return $file.'.php('.$line.')';
        };

        // PHP emits several path/line shapes. Keep one stable label and adjust
        // wrapper lines for the user script body.
        $patterns = [
            '#(?:phar://)?[^\s"\']+/(script|bootstrap)\.php\((\d+)\)#' => static function (array $m) use ($replace): string {
                return $replace($m[1], (int) $m[2]);
            },
            '#(?:phar://)?[^\s"\']+/(script|bootstrap)\.php on line (\d+)#' => static function (array $m) use ($replace): string {
                return $replace($m[1], (int) $m[2]);
            },
            '#on line (\d+) in (?:phar://)?[^\s"\']+/(script|bootstrap)\.php#' => static function (array $m) use ($replace): string {
                return $replace($m[2], (int) $m[1]);
            },
            '# in (?:phar://)?[^\s"\']+/(script|bootstrap)\.php on line (\d+)#' => static function (array $m) use ($replace): string {
                return ' in '.$replace($m[1], (int) $m[2]);
            },
            '#(?:phar://)?[^\s"\']+/(script|bootstrap)\.php:(\d+)#' => static function (array $m) use ($replace): string {
                return $replace($m[1], (int) $m[2]);
            },
        ];

        $normalized = $text;
        foreach ($patterns as $pattern => $callback) {
            $next = preg_replace_callback($pattern, $callback, $normalized);
            if (\is_string($next)) {
                $normalized = $next;
            }
        }

        return $normalized;
    }
}
