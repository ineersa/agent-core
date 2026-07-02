<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi;

use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Command\InteractiveCommandHostInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookInterface;
use Ineersa\Hatfield\ExtensionApi\Prompt\PromptContributorInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallRewriteHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultHookInterface;

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
     * Optional interactive command host (TUI). Null in headless contexts.
     */
    public function interactiveCommandHost(): ?InteractiveCommandHostInterface;
}
