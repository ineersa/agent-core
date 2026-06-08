<?php

declare(strict_types=1);

namespace Ineersa\Tui\Completion;

/**
 * Contract for completion suggestion providers.
 *
 * Providers produce typed suggestion lists from editor context.
 * Returns an empty list when the provider does not apply.
 *
 * The {@see CompletionContext} DTO carries full editor text and cursor
 * position.  EDITOR-08 operates with cursor-at-end because
 * {@see PromptEditor} does not expose live cursor state yet.
 */
interface CompletionProvider
{
    /**
     * @return list<CompletionSuggestion>
     */
    public function getSuggestions(CompletionContext $context): array;
}
