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
 * Argument-only {@see ExtensionToolHandlerInterface} handlers keep the
 * argument-only signature. Contextual handlers receive a public
 * {@see ToolInvocationContextDTO} built from the ambient ToolExecutor context
 * (run id, cooperative cancellation token, and timeout budget).
 */
final readonly class ExtensionToolHandlerAdapter implements ToolHandlerInterface
{
    public function __construct(
        private ExtensionToolHandlerInterface|ContextualExtensionToolHandlerInterface $extensionHandler,
        private StackToolExecutionContextAccessor $contextAccessor,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function __invoke(array $arguments): mixed
    {
        if ($this->extensionHandler instanceof ContextualExtensionToolHandlerInterface) {
            $current = $this->contextAccessor->current();
            $runId = $current?->runId();
            if (null === $current || null === $runId || '' === trim($runId)) {
                throw new \LogicException('A tool execution context with runId is required for contextual extension tools.');
            }

            return ($this->extensionHandler)($arguments, new ToolInvocationContextDTO(
                runId: $runId,
                cancellationToken: new ExtensionToolCancellationTokenAdapter($current->cancellationToken()),
                timeoutSeconds: $current->timeoutSeconds(),
            ));
        }

        return ($this->extensionHandler)($arguments);
    }
}
