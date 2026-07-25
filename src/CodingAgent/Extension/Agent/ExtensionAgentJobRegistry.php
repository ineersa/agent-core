<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Agent;

use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface;

/**
 * Process-local registry of extension-agent job handlers.
 *
 * Handlers are registered during ExtensionManager::loadExtensions() in every
 * console process, including messenger:consume extension_agent.
 */
final class ExtensionAgentJobRegistry
{
    /** @var array<string, ExtensionAgentJobHandlerInterface> */
    private array $handlers = [];

    public function register(string $handlerId, ExtensionAgentJobHandlerInterface $handler): void
    {
        $handlerId = trim($handlerId);
        if ('' === $handlerId) {
            throw new \InvalidArgumentException('Extension agent job handler id must be a non-empty string.');
        }

        if (isset($this->handlers[$handlerId])) {
            throw new \InvalidArgumentException(\sprintf('Extension agent job handler "%s" is already registered.', $handlerId));
        }

        $this->handlers[$handlerId] = $handler;
    }

    public function get(string $handlerId): ?ExtensionAgentJobHandlerInterface
    {
        return $this->handlers[$handlerId] ?? null;
    }

    /**
     * @return list<string>
     */
    public function handlerIds(): array
    {
        return array_keys($this->handlers);
    }
}
