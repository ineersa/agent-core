<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\State;

use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;

/**
 * Resolves the active Hatfield session/run id for jbcontext session state.
 *
 * Interactive TUI may bind a live context for status polling. Tool and
 * after-turn paths use the ambient runId. Eligibility starts from the
 * controller session-start hook.
 */
final class JbcontextSessionLocator
{
    private ?TuiExtensionContextInterface $tui = null;

    public function bindTui(TuiExtensionContextInterface $context): void
    {
        $this->tui = $context;
    }

    public function resolve(?string $runId = null): ?string
    {
        $fromRun = null !== $runId ? trim($runId) : '';
        if ('' !== $fromRun) {
            return $fromRun;
        }

        if (null === $this->tui) {
            return null;
        }

        $sessionId = trim($this->tui->getSessionId());

        return '' !== $sessionId ? $sessionId : null;
    }

    public function storeFor(JbcontextPaths $paths, ?string $runId = null): ?JbcontextStatusStore
    {
        $sessionId = $this->resolve($runId);
        if (null === $sessionId) {
            return null;
        }

        return JbcontextStatusStore::forSession($paths, $sessionId);
    }
}
