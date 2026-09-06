<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Cli;

/**
 * Maps known jbcontext CLI failure text to stable error codes.
 *
 * Never returns raw stderr. Auth detection is limited to the known
 * IsLoggedInGuard message so credentials and tokens are not echoed.
 */
final class JbcontextCliErrorClassifier
{
    public const string AUTH_REQUIRED = 'auth_required';
    public const string EMPTY_STDOUT = 'empty_stdout';

    public const string AUTH_USER_GUIDANCE = 'jbcontext authentication required. Run `jbcontext login`, then start a new Hatfield session.';

    public static function classifyEmptyStdout(string $stderr): string
    {
        if (self::isAuthenticationRequired($stderr)) {
            return self::AUTH_REQUIRED;
        }

        return self::EMPTY_STDOUT;
    }

    public static function isAuthenticationRequired(string $stderr): bool
    {
        return str_contains(strtolower($stderr), 'authentication required');
    }

    public static function userGuidance(string $errorCode): ?string
    {
        if (self::AUTH_REQUIRED === $errorCode) {
            return self::AUTH_USER_GUIDANCE;
        }

        return null;
    }
}
