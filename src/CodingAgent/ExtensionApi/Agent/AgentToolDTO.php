<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Agent;

use Ineersa\Hatfield\ExtensionApi\Tool\ExtensionToolHandlerInterface;

/**
 * Isolated tool available only for one agent invocation.
 *
 * These tools are never mixed into Hatfield's ambient tool registry. Handlers
 * are the same public ExtensionToolHandlerInterface used by permanent tools so
 * extensions can persist data directly (for example record observations).
 */
final readonly class AgentToolDTO
{
    /**
     * @param string                        $name                 unique tool name exposed to the model for this call
     * @param string                        $description          short description for the provider schema
     * @param array<string, mixed>          $parametersJsonSchema JSON Schema describing tool parameters
     * @param ExtensionToolHandlerInterface $handler              extension-owned execution handler
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parametersJsonSchema,
        public ExtensionToolHandlerInterface $handler,
    ) {
        if ('' === trim($this->name)) {
            throw new \InvalidArgumentException('Agent tool name must be a non-empty string.');
        }

        if ('' === trim($this->description)) {
            throw new \InvalidArgumentException('Agent tool description must be a non-empty string.');
        }
    }
}
