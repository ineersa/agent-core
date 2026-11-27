<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Prompt;

/**
 * Narrow provider interface for reading registered prompt contributors.
 *
 * Implemented by the host extension hook registry and consumed by the host
 * system prompt builder through this public contract, so prompt building does
 * not depend on the extension registry implementation.
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
