<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use function Symfony\Component\String\u;

/**
 * Bounds and redacts diagnostic text for model-visible tool failures and logs.
 *
 * Keeps actionable exception text while removing common secret-bearing
 * substrings. Exception context omits stack traces and argument dumps.
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
        ['/(api[-_]?key|token|password|secret|encrypted_content|prompt_cache_key)\s*["\']?\s*[:=]\s*("(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|[^\s,"\'}]+)/i', '$1=<redacted>'],
        ['#(https?://)[^\s/@]+:[^\s/@]+@#i', '$1<redacted>@'],
        ['/\b(?:gh[pousr]_[A-Za-z0-9_]+|github_pat_[A-Za-z0-9_]+)\b/', '<redacted>'],
        ['/\bsk-[A-Za-z0-9_-]+\b/', '<redacted>'],
    ];

    private const int MAX_LENGTH = 500;

    public static function sanitize(string $message, int $maxLength = self::MAX_LENGTH): string
    {
        return u(self::redact($message))->truncate($maxLength, '...')->toString();
    }

    /** @return list<array{class: class-string, message: string, file: string, line: int}> */
    public static function exceptionChain(\Throwable $exception): array
    {
        $chain = [];
        do {
            $chain[] = [
                'class' => $exception::class,
                'message' => self::sanitize($exception->getMessage()),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        } while (null !== ($exception = $exception->getPrevious()));

        return $chain;
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
