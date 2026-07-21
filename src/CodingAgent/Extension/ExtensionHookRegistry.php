<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterConversationBoundaryHookInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\RuntimeLifecycleHookInterface;
use Ineersa\Hatfield\ExtensionApi\Prompt\PromptContributorInterface;
use Ineersa\Hatfield\ExtensionApi\Prompt\PromptContributorProviderInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallRewriteHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallRewriteHookProviderInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultHookInterface;

/**
 * Internal registry for tool call/result hooks registered by extensions.
 *
 * Hooks are stored in registration order. Path A approvals use canonical
 * WaitingHuman + typed resume correlation (hook_class/hook_id embedded in the
 * pending request payload), not this registry for answer routing.
 *
 * @internal this is app-internal wiring, not part of the public ExtensionApi
 */
final class ExtensionHookRegistry implements PromptContributorProviderInterface, ToolCallRewriteHookProviderInterface
{
    /**
     * Registered tool call hooks, in registration order.
     *
     * @var list<ToolCallHookInterface>
     */
    private array $toolCallHooks = [];

    /**
     * Registered tool result hooks, in registration order.
     *
     * @var list<ToolResultHookInterface>
     */
    private array $toolResultHooks = [];

    /**
     * Registered prompt contributors, in registration order.
     *
     * @var list<PromptContributorInterface>
     */
    private array $promptContributors = [];

    /**
     * Registered rewrite hooks, keyed by tool name.
     *
     * @var array<string, list<ToolCallRewriteHookInterface>>
     */
    private array $rewriteHooks = [];

    /** @var list<AfterTurnCommitHookInterface> */
    private array $afterTurnCommitHooks = [];

    /** @var list<AfterConversationBoundaryHookInterface> */
    private array $afterConversationBoundaryHooks = [];

    /** @var list<RuntimeLifecycleHookInterface> */
    private array $runtimeLifecycleHooks = [];

    public function addToolCallHook(ToolCallHookInterface $hook): void
    {
        $this->toolCallHooks[] = $hook;
    }

    /**
     * @return list<ToolCallHookInterface>
     */
    public function toolCallHooks(): array
    {
        return $this->toolCallHooks;
    }

    public function addToolResultHook(ToolResultHookInterface $hook): void
    {
        $this->toolResultHooks[] = $hook;
    }

    /**
     * @return list<ToolResultHookInterface>
     */
    public function toolResultHooks(): array
    {
        return $this->toolResultHooks;
    }

    public function addPromptContributor(PromptContributorInterface $contributor): void
    {
        $this->promptContributors[] = $contributor;
    }

    /**
     * @return list<PromptContributorInterface>
     */
    public function promptContributors(): array
    {
        return $this->promptContributors;
    }

    /**
     * Register a tool-call rewrite hook for a specific tool or wildcard.
     *
     * @param string $toolName Specific tool name or '*' for all tools
     */
    public function addToolCallRewriteHook(string $toolName, ToolCallRewriteHookInterface $hook): void
    {
        $this->rewriteHooks[$toolName][] = $hook;
    }

    /**
     * Get rewrite hooks matching the given tool name.
     *
     * Returns hooks registered for the exact tool name (registration
     * order), followed by wildcard hooks ('*', registration order).
     * Duplicates are possible if the same hook is registered for both
     * the tool name and wildcard.
     *
     * @return list<ToolCallRewriteHookInterface>
     */
    public function rewriteHooksForTool(string $toolName): array
    {
        $specific = $this->rewriteHooks[$toolName] ?? [];
        $wildcard = $this->rewriteHooks['*'] ?? [];

        // Registration order: specific hooks first, then wildcard.
        // Within each group, registration order is preserved.
        return [...$specific, ...$wildcard];
    }

    public function addAfterTurnCommitHook(AfterTurnCommitHookInterface $hook): void
    {
        $this->afterTurnCommitHooks[] = $hook;
    }

    /** @return list<AfterTurnCommitHookInterface> */
    public function afterTurnCommitHooks(): array
    {
        return $this->afterTurnCommitHooks;
    }

    public function addAfterConversationBoundaryHook(AfterConversationBoundaryHookInterface $hook): void
    {
        $this->afterConversationBoundaryHooks[] = $hook;
    }

    /** @return list<AfterConversationBoundaryHookInterface> */
    public function afterConversationBoundaryHooks(): array
    {
        return $this->afterConversationBoundaryHooks;
    }

    public function addRuntimeLifecycleHook(RuntimeLifecycleHookInterface $hook): void
    {
        $this->runtimeLifecycleHooks[] = $hook;
    }

    /** @return list<RuntimeLifecycleHookInterface> */
    public function runtimeLifecycleHooks(): array
    {
        return $this->runtimeLifecycleHooks;
    }
}
