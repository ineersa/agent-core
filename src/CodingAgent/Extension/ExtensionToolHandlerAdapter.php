<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ContextualExtensionToolHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ExtensionToolHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;

/**
 * Bridges an extension-facing tool handler to the internal ToolRegistry contract.
 *
 * Lives in the AppExtension layer — the only place allowed to depend on both
 * ExtensionApi and CodingAgent tool internals.
 *
 * Legacy {@see ExtensionToolHandlerInterface} handlers keep the argument-only
 * signature. Contextual handlers receive a public {@see ToolInvocationContextDTO}
 * built from the ambient ToolExecutor context (run id only).
 */
final readonly class ExtensionToolHandlerAdapter implements ToolHandlerInterface
{
    public function __construct(
        private ExtensionToolHandlerInterface|ContextualExtensionToolHandlerInterface $extensionHandler,
        private ?StackToolExecutionContextAccessor $contextAccessor = null,
    ) {
    }

    public function __invoke(array $arguments): mixed
    {
        if ($this->extensionHandler instanceof ContextualExtensionToolHandlerInterface) {
            $runId = $this->contextAccessor?->current()?->runId();
            if (null === $runId || '' === trim($runId)) {
                throw new \LogicException('A tool execution context with runId is required for contextual extension tools.');
            }

            return ($this->extensionHandler)($arguments, new ToolInvocationContextDTO($runId));
        }

        return ($this->extensionHandler)($arguments);
    }
}
