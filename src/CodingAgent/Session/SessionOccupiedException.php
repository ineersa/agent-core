<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

/**
 * Thrown when a Hatfield TUI process attempts to activate a session
 * already held by another live instance (same CWD, same session ID).
 */
final class SessionOccupiedException extends \RuntimeException
{
    public function __construct(
        private readonly string $sessionId,
    ) {
        parent::__construct(
            \sprintf(
                'Session "%s" is currently occupied by another Hatfield instance.'."\n"
                .'Refusing to resume. Only one Hatfield instance per session is supported.',
                $sessionId,
            ),
        );
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }
}
