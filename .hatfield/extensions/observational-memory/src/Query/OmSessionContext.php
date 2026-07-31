<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Query;

use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;

/**
 * Holds the live public TUI context for current-session slash commands.
 *
 * session_id is lazy: never cache the string returned by getSessionId().
 * Call getSessionId() on every /om-status and /om-view invocation because
 * BridgeTuiExtensionContext resolves mutable runtime state. Re-registering
 * TUI may replace the bound context object.
 */
final class OmSessionContext
{
    private ?TuiExtensionContextInterface $tui = null;

    public function bindTui(TuiExtensionContextInterface $context): void
    {
        $this->tui = $context;
    }

    public function sessionIdOrNull(): ?string
    {
        if (null === $this->tui) {
            return null;
        }

        $sessionId = trim($this->tui->getSessionId());

        return '' !== $sessionId ? $sessionId : null;
    }

    public function requireSessionId(): string
    {
        $sessionId = $this->sessionIdOrNull();
        if (null === $sessionId) {
            throw new \RuntimeException('No active TUI session is available for observational memory commands.');
        }

        return $sessionId;
    }
}
