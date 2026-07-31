<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Extension\ExtensionManager;
use Ineersa\CodingAgent\Runtime\Contract\LoadedExtensionItemDTO;

/**
 * Resolves effective child-run extension allowlists and validates availability.
 *
 * Subagent effective = agents.extensions.always_on ∪ frontmatter.extensions
 * Fork effective     = forks.extensions.always_on ∪ forks.extensions.enabled
 * Stable first-seen dedup. Never inherits global extensions.enabled optionals.
 *
 * @internal
 */
final readonly class ChildExtensionSelectionService
{
    public function __construct(
        private AppConfig $appConfig,
        private ExtensionManager $extensionManager,
    ) {
    }

    /**
     * @return list<string>
     */
    public function resolveForSubagent(AgentDefinitionDTO $definition): array
    {
        $optional = $definition->extensions ?? [];

        return $this->unionStable(
            $this->appConfig->agents->extensions->alwaysOn,
            $optional,
        );
    }

    /**
     * @return list<string>
     */
    public function resolveForFork(): array
    {
        $forks = $this->appConfig->forks->extensions;

        return $this->unionStable($forks->alwaysOn, $forks->enabled);
    }

    /**
     * Fail closed when a selected class is missing, not globally enabled, or failed to load.
     *
     * @param list<string> $selected
     *
     * @throws \RuntimeException
     */
    public function assertSelectedAvailable(array $selected, string $context): void
    {
        if ([] === $selected) {
            return;
        }

        // Ensure process load outcomes exist. Console workers load via
        // ExtensionLoaderSubscriber; kernel/in-process paths may not have
        // fired ConsoleEvents::COMMAND yet. loadExtensions() is idempotent.
        $this->extensionManager->loadExtensions();

        $globallyEnabled = array_fill_keys(
            array_values(array_filter($this->appConfig->extensions->enabled, \is_string(...))),
            true,
        );

        $outcomesByClass = [];
        foreach ($this->extensionManager->getLoadOutcomes() as $outcome) {
            $outcomesByClass[$outcome->className] = $outcome;
        }

        foreach ($selected as $className) {
            if (!isset($globallyEnabled[$className])) {
                throw new \RuntimeException(\sprintf('%s: selected extension "%s" is not in extensions.enabled (not available to this process).', $context, $className));
            }

            if (!class_exists($className)) {
                throw new \RuntimeException(\sprintf('%s: selected extension class "%s" does not exist.', $context, $className));
            }

            $outcome = $outcomesByClass[$className] ?? null;
            if (!$outcome instanceof LoadedExtensionItemDTO) {
                throw new \RuntimeException(\sprintf('%s: selected extension "%s" was not loaded by ExtensionManager.', $context, $className));
            }

            if (!$outcome->loaded) {
                throw new \RuntimeException(\sprintf('%s: selected extension "%s" failed to register: %s', $context, $className, '' !== $outcome->errorMessage ? $outcome->errorMessage : 'unknown error'));
            }
        }
    }

    /**
     * @param list<string> $alwaysOn
     * @param list<string> $optional
     *
     * @return list<string>
     */
    private function unionStable(array $alwaysOn, array $optional): array
    {
        $seen = [];
        $result = [];
        foreach ([...$alwaysOn, ...$optional] as $className) {
            $className = trim($className);
            if ('' === $className || isset($seen[$className])) {
                continue;
            }
            $seen[$className] = true;
            $result[] = $className;
        }

        return $result;
    }
}
