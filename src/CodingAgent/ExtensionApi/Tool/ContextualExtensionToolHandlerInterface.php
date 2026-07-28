<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tool;

/**
 * Extension-facing permanent tool handler that receives ambient session context.
 *
 * Prefer this when a tool must stay session-scoped without exposing run_id to the model.
 * Legacy {@see ExtensionToolHandlerInterface} handlers remain supported.
 */
interface ContextualExtensionToolHandlerInterface
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __invoke(array $arguments, ToolInvocationContextDTO $context): mixed;
}
