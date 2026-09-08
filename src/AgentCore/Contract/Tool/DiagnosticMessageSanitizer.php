<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use function Symfony\Component\String\u;

/**
 * Bounds and redacts diagnostic text for model-visible tool failures and logs.
 *
 * Keeps actionable exception text while removing common secret-bearing
 * substrings. Does not include stack traces, argument dumps, or exception
 * chains — callers choose which single message string to sanitize.
 */
final class DiagnosticMessageSanitizer
{
    /**
     * @var list<array{string, string}>
     */
    private const SECRET_PATTERNS = [
        ['/authorization:\s*Bearer\s+\S+/i', 'authorization: Bearer <redacted>'],
        ['/Authorization:\s*Bearer\s+\S+/i', 'Authorization: Bearer <redacted>'],
        ['/bearer\s+\S+/i', 'bearer <redacted>'],
        ['/[?&]api_key=\S+/i', 'api_key=<redacted>'],
        ['/[?&]secret=\S+/i', 'secret=<redacted>'],
        ['/[?&]token=\S+/i', 'token=<redacted>'],
        ['/[?&]password=\S+/i', 'password=<redacted>'],
        ['/api[-_]?key\s*[:=]\s*\S+/i', 'api_key <redacted>'],
        ['/(token|password|secret)\s*["\']?\s*[:=]\s*["\']?[^\s,"\'}]+/i', '$1=<redacted>'],
        ['#(https?://)[^\s/@]+:[^\s/@]+@#i', '$1<redacted>@'],
        ['/\b(?:gh[pousr]_[A-Za-z0-9_]+|github_pat_[A-Za-z0-9_]+)\b/', '<redacted>'],
    ];

    private const int MAX_LENGTH = 500;

    public static function sanitize(string $message, int $maxLength = self::MAX_LENGTH): string
    {
        return u(self::redact($message))->truncate($maxLength, '...')->toString();
    }

    /** Explicit tool output already has the central output-cap contract. */
    public static function redact(string $message): string
    {
        foreach (self::SECRET_PATTERNS as [$pattern, $replacement]) {
            $message = preg_replace($pattern, $replacement, $message) ?? $message;
        }

        return $message;
    }
}
