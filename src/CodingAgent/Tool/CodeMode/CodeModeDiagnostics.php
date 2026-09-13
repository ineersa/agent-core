<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

/**
 * Formats and bounds code_mode stdout/stderr for model-facing diagnostics.
 *
 * Warning duplication and Xdebug stacks are prevented at process start
 * (display_errors=0, xdebug.mode=off, log_errors=1). This helper only
 * normalizes script/bootstrap paths and hard-bounds the rendered block so
 * headers plus stream tails cannot quietly exceed the default OutputCap.
 *
 * @internal
 */
final class CodeModeDiagnostics
{
    public const int MAX_BLOCK_CHARS = 4000;
    public const string TRUNCATION_MARKER = "\n...[code_mode diagnostics truncated]";

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

        return self::truncateBlock("code_mode diagnostics\n".implode("\n\n", $sections));
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

        return self::truncateBlock($message);
    }

    public static function truncateBlock(string $text): string
    {
        if (\strlen($text) <= self::MAX_BLOCK_CHARS) {
            return $text;
        }

        $marker = self::TRUNCATION_MARKER;
        $budget = self::MAX_BLOCK_CHARS - \strlen($marker);
        if ($budget < 1) {
            return substr($marker, -self::MAX_BLOCK_CHARS);
        }

        $prefix = substr($text, 0, $budget);
        if (!mb_check_encoding($prefix, 'UTF-8')) {
            // substr can split a multibyte character. Drop incomplete trailing
            // bytes until the prefix is valid UTF-8 while staying within budget.
            while ('' !== $prefix && !mb_check_encoding($prefix, 'UTF-8')) {
                $prefix = substr($prefix, 0, -1);
            }
        }

        return $prefix.$marker;
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
