<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi;

use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterConversationBoundaryHookInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\RuntimeLifecycleHookInterface;
use Ineersa\Hatfield\ExtensionApi\Model\AiModelReference;
use Ineersa\Hatfield\ExtensionApi\Prompt\PromptContributorInterface;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventReaderInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallRewriteHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultHookInterface;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ResultInterface;

/**
 * Public API surface that Hatfield exposes to enabled extensions.
 *
 * Extensions receive this interface via HatfieldExtensionInterface::register()
 * and may call registerTool() to contribute permanent tools, and
 * registerToolCallHook() / registerToolResultHook() to intercept tool
 * execution lifecycle.
 *
 * This is the stable public contract for extension authors. All methods are
 * optional for v1; additional hooks may be added later without breaking
 * existing extensions.
 */
interface ExtensionApiInterface
{
    /**
     * Register a permanent tool with the Hatfield tool registry.
     *
     * Registered tools become part of the provider schema, execution allowlist,
     * and system prompt tool listing according to ToolRegistry policy.
     *
     * @param ToolRegistrationDTO $tool the tool definition to register
     */
    public function registerTool(ToolRegistrationDTO $tool): void;

    /**
     * Register a hook that is invoked before each tool call.
     *
     * Hooks run in registration order. The first non-Allow decision wins.
     *
     * @param ToolCallHookInterface $hook the hook implementation
     */
    public function registerToolCallHook(ToolCallHookInterface $hook): void;

    /**
     * Register a hook that is invoked after each tool call completes.
     *
     * Hooks run in registration order. Each hook sees the latest result state.
     *
     * @param ToolResultHookInterface $hook the hook implementation
     */
    public function registerToolResultHook(ToolResultHookInterface $hook): void;

    /**
     * Get extension settings by key.
     *
     * Returns the settings array for the given key from the extensions.settings
     * section of Hatfield configuration. Returns empty array if key not found.
     *
     * @return array<string, mixed>
     */
    public function getSettings(string $key): array;

    /**
     * Get the current working directory for the Hatfield session.
     *
     * Returns the resolved CWD path that extensions should use for
     * path resolution and policy evaluation.
     */
    public function getCwd(): string;

    /**
     * Get the exec capability object.
     *
     * Returns an ExecInterface that extensions can use to run shell
     * commands with argument arrays, configurable working directory,
     * timeout, and environment variables. Never shell-interpolates.
     *
     * @see ExecInterface
     */
    public function exec(): ExecInterface;

    /**
     * Get the recovery/catch-up/compaction canonical session event reader.
     *
     * Returns public SessionEventDTO values only. Reads are non-branch-aware
     * and use (run_id, seq) source identity. This is NOT a per-boundary hot-path
     * reader; hot commit hooks expose just-persisted batches instead. The MVP
     * implementation may scan the full journal and is acceptable only for
     * recovery/compaction workloads.
     *
     * @see SessionEventReaderInterface
     */
    public function sessionEvents(): SessionEventReaderInterface;

    /**
     * Perform one blocking, non-streaming model call through Hatfield's configured
     * Symfony AI Platform.
     *
     * Uses a standard Symfony AI Agent for `$model->toString()`. When a toolbox is
     * supplied, attaches Symfony's AgentProcessor as both input and output processor
     * so the normal tool-loop executes. Ambient Hatfield tools are never injected.
     * Returns the native Symfony AI ResultInterface. Native provider/platform
     * exceptions propagate; there is no parallel public error DTO.
     *
     * Structured-output helpers are intentionally out of scope for MVP.
     */
    public function callModel(
        AiModelReference $model,
        MessageBag $messages,
        ?ToolboxInterface $toolbox = null,
    ): ResultInterface;

    /**
     * Register a prompt contributor that injects markdown into the system prompt.
     *
     * The contributor's output is appended after static APPEND_SYSTEM.md content
     * and before the final prompt is sent to the LLM.
     *
     * @see PromptContributorInterface
     */
    public function registerPromptContributor(PromptContributorInterface $contributor): void;

    /**
     * Register a slash command with the TUI command registry.
     *
     * The command definition (name, aliases, description, usage) and handler
     * are forwarded to the SlashCommandRegistry via the TUI adapter bridge.
     *
     * @see CommandDefinitionDTO
     * @see ExtensionCommandHandlerInterface
     */
    public function registerCommand(CommandDefinitionDTO $definition, ExtensionCommandHandlerInterface $handler): void;

    /**
     * Register a tool-call argument rewrite hook.
     *
     * Rewrite hooks run BEFORE SafeGuard/policy hooks and transform tool
     * arguments. The rewritten arguments are visible to subsequent hooks
     * and to the tool handler.
     *
     * @param string                       $toolName Tool name or '*' for wildcard (all tools)
     * @param ToolCallRewriteHookInterface $hook     The rewrite hook
     *
     * @see ToolCallRewriteHookInterface
     */
    public function registerToolCallRewriteHook(string $toolName, ToolCallRewriteHookInterface $hook): void;

    /**
     * Register a hook invoked after AgentCore commits a turn.
     */
    public function registerAfterTurnCommitHook(AfterTurnCommitHookInterface $hook): void;

    /**
     * Register a post-commit terminal conversation-boundary hook.
     *
     * Delivery is best-effort after canonical event persistence with allocated
     * seq. Distinct from AfterTurnCommitHookInterface, which remains for
     * file-rewind and other per-commit observers.
     */
    public function registerAfterConversationBoundaryHook(AfterConversationBoundaryHookInterface $hook): void;

    /**
     * Register a hook for owning runtime process start/stop notifications.
     *
     * Notifications are emitted once per controller process, not per run and
     * not from Messenger workers.
     */
    public function registerRuntimeLifecycleHook(RuntimeLifecycleHookInterface $hook): void;
}
