<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi;

/**
 * Programmatic contributor that injects markdown into the system prompt.
 *
 * Extensions implement this interface and register via
 * ExtensionApiInterface::registerPromptContributor() to append dynamic
 * content (e.g. task workflow rules) to the system prompt at build time.
 *
 * The contributed text is appended after static APPEND_SYSTEM.md files
 * and flows through the {appends_part} placeholder in the base template.
 *
 * @see ExtensionApiInterface::registerPromptContributor()
 */
interface PromptContributorInterface
{
    /**
     * Return markdown text to append to the system prompt.
     *
     * Called once per prompt build. Return an empty string to contribute nothing.
     */
    public function contribute(): string;
}
