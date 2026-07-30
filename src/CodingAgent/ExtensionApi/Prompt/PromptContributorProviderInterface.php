<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Prompt;

/**
 * Narrow provider interface for reading registered prompt contributors.
 *
 * Implemented by ExtensionHookRegistry (AppExtension layer) and consumed
 * by SystemPromptBuilder (AppSystemPrompt layer) through the AppExtensionApi
 * public contract, avoiding a direct AppSystemPrompt → AppExtension dependency.
 *
 * @see PromptContributorInterface
 */
interface PromptContributorProviderInterface
{
    /**
     * @param list<string>|null $allowedOwnerClasses null keeps all contributors (parent/global);
     *                                               a list filters to those extension owner classes
     *
     * @return list<PromptContributorInterface> In registration order
     */
    public function promptContributors(?array $allowedOwnerClasses = null): array;
}
