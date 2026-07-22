<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Runtime;

/**
 * Dispatched when the owning headless controller is shutting down.
 *
 * Fired before Hatfield tears down its own messenger consumers so extensions
 * can stop extension-owned child processes first.
 */
final readonly class RuntimeStoppingEvent
{
    public function __construct(
        public string $sessionId,
        public string $runtimeCwd,
    ) {
        if ('' === $this->sessionId) {
            throw new \InvalidArgumentException('RuntimeStoppingEvent sessionId must be non-empty.');
        }
        if ('' === $this->runtimeCwd) {
            throw new \InvalidArgumentException('RuntimeStoppingEvent runtimeCwd must be non-empty.');
        }
    }
}
