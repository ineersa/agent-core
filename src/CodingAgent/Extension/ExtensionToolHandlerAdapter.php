<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ExtensionToolHandlerInterface;

/**
 * Bridges an extension-facing tool handler to the internal ToolRegistry contract.
 *
 * Lives in the AppExtension layer — the only place allowed to depend on both
 * ExtensionApi and CodingAgent tool internals.
 */
final readonly class ExtensionToolHandlerAdapter implements ToolHandlerInterface
{
    public function __construct(
        private ExtensionToolHandlerInterface $extensionHandler,
    ) {
    }

    public function __invoke(array $arguments): mixed
    {
        return ($this->extensionHandler)($arguments);
    }
}
