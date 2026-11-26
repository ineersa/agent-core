<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Query;

use Ineersa\Hatfield\ExtensionApi\Session\SessionContextInterface;

/**
 * Resolves the selected session for current-session slash commands.
 *
 * The selection changes on resume, switch and draft promotion, so never cache
 * the session ID: read it on every /om-view invocation.
 */
final readonly class OmSessionContext
{
    public function __construct(private SessionContextInterface $session)
    {
    }

    public function sessionIdOrNull(): ?string
    {
        $sessionId = trim($this->session->getSessionId());

        return '' !== $sessionId ? $sessionId : null;
    }

    public function requireSessionId(): string
    {
        $sessionId = $this->sessionIdOrNull();
        if (null === $sessionId) {
            throw new \RuntimeException('No selected session is available for observational memory commands.');
        }

        return $sessionId;
    }
}
