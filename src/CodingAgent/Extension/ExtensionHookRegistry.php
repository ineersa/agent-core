<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterSessionStartHookInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookInterface;
use Ineersa\Hatfield\ExtensionApi\Prompt\PromptContributorInterface;
use Ineersa\Hatfield\ExtensionApi\Prompt\PromptContributorProviderInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallRewriteHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallRewriteHookProviderInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultHookInterface;

/**
 * Internal registry for tool call/result hooks registered by extensions.
 *
 * Each registration is tagged with the owning extension class (when known)
 * so child-run filtering can drop unselected extension surfaces without
 * mutating process-global registration.
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
     * @var list<array{hook: ToolCallHookInterface, owner: ?string}>
     */
    private array $toolCallHooks = [];

    /**
     * @var list<array{hook: ToolResultHookInterface, owner: ?string}>
     */
    private array $toolResultHooks = [];

    /**
     * @var list<array{hook: PromptContributorInterface, owner: ?string}>
     */
    private array $promptContributors = [];

    /**
     * @var array<string, list<array{hook: ToolCallRewriteHookInterface, owner: ?string}>>
     */
    private array $rewriteHooks = [];

    /**
     * @var list<array{hook: AfterTurnCommitHookInterface, owner: ?string}>
     */
    private array $afterTurnCommitHooks = [];

    /**
     * @var list<array{hook: AfterSessionStartHookInterface, owner: ?string}>
     */
    private array $sessionStartHooks = [];

    /**
     * @var list<array{hook: BeforeCompactionHookInterface, owner: ?string}>
     */
    private array $beforeCompactionHooks = [];

    public function addToolCallHook(ToolCallHookInterface $hook): void
    {
        $this->toolCallHooks[] = ['hook' => $hook, 'owner' => ExtensionRegistrationContext::currentOwnerClass()];
    }

    /**
     * @param list<string>|null $allowedOwnerClasses null = no filter (parent/global)
     *
     * @return list<ToolCallHookInterface>
     */
    public function toolCallHooks(?array $allowedOwnerClasses = null): array
    {
        return $this->filterHooks($this->toolCallHooks, $allowedOwnerClasses);
    }

    public function addToolResultHook(ToolResultHookInterface $hook): void
    {
        $this->toolResultHooks[] = ['hook' => $hook, 'owner' => ExtensionRegistrationContext::currentOwnerClass()];
    }

    /**
     * @param list<string>|null $allowedOwnerClasses
     *
     * @return list<ToolResultHookInterface>
     */
    public function toolResultHooks(?array $allowedOwnerClasses = null): array
    {
        return $this->filterHooks($this->toolResultHooks, $allowedOwnerClasses);
    }

    public function addPromptContributor(PromptContributorInterface $contributor): void
    {
        $this->promptContributors[] = ['hook' => $contributor, 'owner' => ExtensionRegistrationContext::currentOwnerClass()];
    }

    /**
     * @param list<string>|null $allowedOwnerClasses
     *
     * @return list<PromptContributorInterface>
     */
    public function promptContributors(?array $allowedOwnerClasses = null): array
    {
        return $this->filterHooks($this->promptContributors, $allowedOwnerClasses);
    }

    /**
     * @param string $toolName Specific tool name or '*' for all tools
     */
    public function addToolCallRewriteHook(string $toolName, ToolCallRewriteHookInterface $hook): void
    {
        $this->rewriteHooks[$toolName][] = [
            'hook' => $hook,
            'owner' => ExtensionRegistrationContext::currentOwnerClass(),
        ];
    }

    /**
     * @param list<string>|null $allowedOwnerClasses
     *
     * @return list<ToolCallRewriteHookInterface>
     */
    public function rewriteHooksForTool(string $toolName, ?array $allowedOwnerClasses = null): array
    {
        $specific = $this->filterHooks($this->rewriteHooks[$toolName] ?? [], $allowedOwnerClasses);
        $wildcard = $this->filterHooks($this->rewriteHooks['*'] ?? [], $allowedOwnerClasses);

        return [...$specific, ...$wildcard];
    }

    public function addAfterTurnCommitHook(AfterTurnCommitHookInterface $hook): void
    {
        $this->afterTurnCommitHooks[] = ['hook' => $hook, 'owner' => ExtensionRegistrationContext::currentOwnerClass()];
    }

    /**
     * @param list<string>|null $allowedOwnerClasses
     *
     * @return list<AfterTurnCommitHookInterface>
     */
    public function afterTurnCommitHooks(?array $allowedOwnerClasses = null): array
    {
        return $this->filterHooks($this->afterTurnCommitHooks, $allowedOwnerClasses);
    }

    public function addSessionStartHook(AfterSessionStartHookInterface $hook): void
    {
        $this->sessionStartHooks[] = ['hook' => $hook, 'owner' => ExtensionRegistrationContext::currentOwnerClass()];
    }

    /**
     * @param list<string>|null $allowedOwnerClasses
     *
     * @return list<AfterSessionStartHookInterface>
     */
    public function sessionStartHooks(?array $allowedOwnerClasses = null): array
    {
        return $this->filterHooks($this->sessionStartHooks, $allowedOwnerClasses);
    }

    public function addBeforeCompactionHook(BeforeCompactionHookInterface $hook): void
    {
        $this->beforeCompactionHooks[] = ['hook' => $hook, 'owner' => ExtensionRegistrationContext::currentOwnerClass()];
    }

    /**
     * @param list<string>|null $allowedOwnerClasses
     *
     * @return list<BeforeCompactionHookInterface>
     */
    public function beforeCompactionHooks(?array $allowedOwnerClasses = null): array
    {
        return $this->filterHooks($this->beforeCompactionHooks, $allowedOwnerClasses);
    }

    /**
     * @template T of object
     *
     * @param list<array{hook: T, owner: ?string}> $entries
     * @param list<string>|null                    $allowedOwnerClasses
     *
     * @return list<T>
     */
    private function filterHooks(array $entries, ?array $allowedOwnerClasses): array
    {
        if (null === $allowedOwnerClasses) {
            $hooks = [];
            foreach ($entries as $entry) {
                $hooks[] = $entry['hook'];
            }

            return $hooks;
        }

        $allowed = array_fill_keys($allowedOwnerClasses, true);
        $hooks = [];
        foreach ($entries as $entry) {
            $owner = $entry['owner'];
            // Ownerless registrations are process infrastructure — keep them.
            // Extension-owned entries require explicit selection for child runs.
            if (null === $owner || isset($allowed[$owner])) {
                $hooks[] = $entry['hook'];
            }
        }

        return $hooks;
    }
}
