<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookInterface;
use Ineersa\Hatfield\ExtensionApi\Prompt\PromptContributorInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallRewriteHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultHookInterface;

/**
 * In-memory test-double for ExtensionApiInterface.
 *
 * Collects tool registrations, hooks, and settings in-memory so tests
 * can inspect what an extension registered during the loading phase
 * without depending on the production ToolRegistry/DI wiring.
 *
 * The v2+ methods (exec, registerPromptContributor, registerCommand,
 * registerToolCallRewriteHook) throw LogicException — tests that need
 * these capabilities should use the production ExtensionToolRegistryBridge
 * or a dedicated test stub.
 */
final class InMemoryExtensionApiBridge implements ExtensionApiInterface
{
    private string $cwd;

    /**
     * Collected tool registrations, in registration order.
     *
     * @var list<ToolRegistrationDTO>
     */
    private array $registeredTools = [];

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

    /** @var list<AfterTurnCommitHookInterface> */
    private array $afterTurnCommitHooks = [];

    public function __construct(?string $cwd = null)
    {
        $this->cwd = $cwd ?? '';
    }

    public function registerTool(ToolRegistrationDTO $tool): void
    {
        $this->registeredTools[] = $tool;
    }

    /**
     * Return all collected tool registrations and clear the buffer.
     *
     * @return list<ToolRegistrationDTO>
     */
    public function drainRegistrations(): array
    {
        $tools = $this->registeredTools;
        $this->registeredTools = [];

        return $tools;
    }

    /**
     * Peek at collected registrations without draining.
     *
     * @return list<ToolRegistrationDTO>
     */
    public function getRegistrations(): array
    {
        return $this->registeredTools;
    }

    public function registerToolCallHook(ToolCallHookInterface $hook): void
    {
        $this->toolCallHooks[] = $hook;
    }

    /**
     * @return list<ToolCallHookInterface>
     */
    public function getToolCallHooks(): array
    {
        return $this->toolCallHooks;
    }

    public function registerToolResultHook(ToolResultHookInterface $hook): void
    {
        $this->toolResultHooks[] = $hook;
    }

    /**
     * @return list<ToolResultHookInterface>
     */
    public function getToolResultHooks(): array
    {
        return $this->toolResultHooks;
    }

    public function getSettings(string $key): array
    {
        return [];
    }

    public function getCwd(): string
    {
        return $this->cwd;
    }

    public function exec(): ExecInterface
    {
        return new class implements ExecInterface {
            public function exec(string $command, array $args = [], ?ExecOptionsDTO $options = null): ExecResultDTO
            {
                throw new \LogicException('exec is not supported on the InMemoryExtensionApiBridge. Use the production ExtensionToolRegistryBridge.');
            }
        };
    }

    public function registerPromptContributor(PromptContributorInterface $contributor): void
    {
        throw new \LogicException('registerPromptContributor is not supported on the InMemoryExtensionApiBridge. Use the production ExtensionToolRegistryBridge.');
    }

    public function registerCommand(CommandDefinitionDTO $definition, ExtensionCommandHandlerInterface $handler): void
    {
        throw new \LogicException('registerCommand is not supported on the InMemoryExtensionApiBridge. Use the production ExtensionToolRegistryBridge.');
    }

    public function registerToolCallRewriteHook(string $toolName, ToolCallRewriteHookInterface $hook): void
    {
        throw new \LogicException('registerToolCallRewriteHook is not supported on the InMemoryExtensionApiBridge. Use the production ExtensionToolRegistryBridge.');
    }

    public function registerAfterTurnCommitHook(AfterTurnCommitHookInterface $hook): void
    {
        $this->afterTurnCommitHooks[] = $hook;
    }
}
