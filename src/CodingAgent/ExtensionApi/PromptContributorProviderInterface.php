<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi;

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
     * @return list<PromptContributorInterface> In registration order
     */
    public function promptContributors(): array;
}
