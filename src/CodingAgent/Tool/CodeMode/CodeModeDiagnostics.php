<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

/**
 * Formats and bounds code_mode stdout/stderr for model-facing diagnostics.
 *
 * Warnings stay concise and appear once. Errors keep stacks with stable
 * script.php/bootstrap.php path labels. The combined diagnostics block is
 * truncated before OutputCap so a tiny return plus chatty output stays
 * self-contained under the default tool-result cap.
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

        [$stdout, $stderr] = self::dedupeWarnings($stdout, $stderr);
        $stdout = self::compactWarningNoise($stdout);
        $stderr = self::compactWarningNoise($stderr);

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

    /**
     * @return array{0: string, 1: string}
     */
    private static function dedupeWarnings(string $stdout, string $stderr): array
    {
        if ('' === $stdout || '' === $stderr) {
            return [$stdout, $stderr];
        }

        $stdoutLines = preg_split("/\r\n|\n|\r/", $stdout) ?: [];
        $stderrLines = preg_split("/\r\n|\n|\r/", $stderr) ?: [];
        $stdoutFingerprints = [];
        foreach ($stdoutLines as $line) {
            $fingerprint = self::warningFingerprint($line);
            if (null !== $fingerprint) {
                $stdoutFingerprints[$fingerprint] = true;
            }
        }

        if ([] === $stdoutFingerprints) {
            return [$stdout, $stderr];
        }

        $kept = [];
        $skipStack = false;
        foreach ($stderrLines as $line) {
            $trimmed = trim($line);
            if ($skipStack) {
                if ('' === $trimmed || self::isStackFrameLine($trimmed) || self::isStackHeader($trimmed)) {
                    continue;
                }
                $skipStack = false;
            }

            $fingerprint = self::warningFingerprint($line);
            if (null !== $fingerprint && isset($stdoutFingerprints[$fingerprint])) {
                $skipStack = true;
                continue;
            }

            $kept[] = $line;
        }

        return [$stdout, trim(implode("\n", $kept))];
    }

    private static function compactWarningNoise(string $text): string
    {
        if ('' === $text) {
            return '';
        }

        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
        $kept = [];
        $skipStack = false;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($skipStack) {
                if ('' === $trimmed || self::isStackFrameLine($trimmed) || self::isStackHeader($trimmed)) {
                    continue;
                }
                $skipStack = false;
            }

            if (self::isWarningLine($trimmed)) {
                $kept[] = self::formatConciseWarning($trimmed);
                $skipStack = true;
                continue;
            }

            $kept[] = $line;
        }

        return trim(implode("\n", $kept));
    }

    private static function warningFingerprint(string $line): ?string
    {
        $line = trim($line);
        if (!self::isWarningLine($line)) {
            return null;
        }

        $line = preg_replace('/^(?:PHP\s+)?Warning:\s+/i', '', $line) ?? $line;

        return strtolower(preg_replace('/\s+/', ' ', $line) ?? $line);
    }

    private static function isWarningLine(string $line): bool
    {
        return 1 === preg_match('/^(?:PHP\s+)?Warning:/i', $line);
    }

    private static function formatConciseWarning(string $line): string
    {
        $line = preg_replace('/^(?:PHP\s+)?Warning:\s+/i', 'Warning: ', $line) ?? $line;

        return $line;
    }

    private static function isStackHeader(string $line): bool
    {
        return 1 === preg_match('/^(?:PHP\s+)?(?:Stack trace|Call Stack):/i', $line);
    }

    private static function isStackFrameLine(string $line): bool
    {
        return 1 === preg_match('/^(?:PHP\s+)?#\d+\s/', $line)
            || 1 === preg_match('/^(?:PHP\s+)?\d+\.\s/', $line)
            || 1 === preg_match('/^\d+\.\d+\s+\d+\s+\d+\.\s/', $line);
    }
}
