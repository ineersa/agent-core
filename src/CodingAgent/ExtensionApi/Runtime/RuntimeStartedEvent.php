<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Runtime;

/**
 * Dispatched once when the owning headless controller runtime is ready.
 *
 * Scalar-only payload so extensions can launch their own child processes with
 * the same source/PHAR-safe application prefix used by Hatfield, without
 * receiving container, Process, Messenger, Doctrine, or internal runtime objects.
 */
final readonly class RuntimeStartedEvent
{
    /**
     * @param list<string> $applicationCommand Executable argv prefix, e.g. [PHP_BINARY, '/path/to/bin/console']
     */
    public function __construct(
        public string $sessionId,
        public string $runtimeCwd,
        public array $applicationCommand,
        public string $executablePath,
    ) {
        if ('' === $this->sessionId) {
            throw new \InvalidArgumentException('RuntimeStartedEvent sessionId must be non-empty.');
        }
        if ('' === $this->runtimeCwd) {
            throw new \InvalidArgumentException('RuntimeStartedEvent runtimeCwd must be non-empty.');
        }
        if ([] === $this->applicationCommand) {
            throw new \InvalidArgumentException('RuntimeStartedEvent applicationCommand must be non-empty.');
        }
        if ('' === $this->executablePath) {
            throw new \InvalidArgumentException('RuntimeStartedEvent executablePath must be non-empty.');
        }
        foreach ($this->applicationCommand as $part) {
            if (!\is_string($part) || '' === $part) {
                throw new \InvalidArgumentException('RuntimeStartedEvent applicationCommand parts must be non-empty strings.');
            }
        }
    }
}
