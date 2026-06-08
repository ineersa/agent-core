<?php

declare(strict_types=1);

namespace Ineersa\Tui\Completion;

/**
 * Contract for completion suggestion providers.
 *
 * Providers produce typed suggestion lists from editor text context.
 * Returns an empty list when the provider does not apply.
 */
interface CompletionProvider
{
    /**
     * @param string $text Current editor text (cursor-at-end for MVP)
     *
     * @return list<CompletionSuggestion>
     */
    public function getSuggestions(string $text): array;
}
