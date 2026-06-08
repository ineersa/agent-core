<?php

declare(strict_types=1);

namespace Ineersa\Tui\Completion;

use Ineersa\Tui\Command\SlashCommandRegistry;

/**
 * Completion provider for slash commands.
 *
 * Triggers only when editor text starts with a leading slash.
 * Matches both canonical command names and aliases; inserts
 * canonical command text when an alias match is accepted.
 *
 * Uses {@see SlashCommandRegistry::allMetadata()} at suggestion time
 * so runtime-registered commands (e.g. /model, /model-favourites)
 * are included.
 *
 * EDITOR-08 limitation: only cursor-at-end is honoured.
 * When {@see CompletionContext::$cursorByteOffset} is not at the
 * end of the text, the entire text is still used as the prefix.
 * This matches the MVP where {@see PromptEditor} does not expose
 * live cursor state.
 */
final readonly class SlashCommandCompletionProvider implements CompletionProvider
{
    public function __construct(
        private SlashCommandRegistry $registry,
    ) {
    }

    public function getSuggestions(CompletionContext $context): array
    {
        $slashContext = $this->extractSlashContext($context->text);

        if (null === $slashContext) {
            return [];
        }

        [$prefix, $replacementStart] = $slashContext;

        // Collect unique canonical matches (deduplicate alias + name collisions)
        $matched = [];

        foreach ($this->registry->allMetadata() as $meta) {
            $canonical = $meta->name;

            // Match canonical name (empty prefix = show all)
            if ('' === $prefix || str_starts_with($canonical, $prefix)) {
                $matched[$canonical] = $meta;

                continue;
            }

            // Match aliases — insert canonical command when accepted.
            // At this point $prefix is non-empty (empty prefix was handled above).
            foreach ($meta->aliases as $alias) {
                if (str_starts_with($alias, $prefix)) {
                    $matched[$canonical] = $meta;

                    break;
                }
            }
        }

        // Build suggestions preserving registry order (allMetadata is already sorted)
        $suggestions = [];
        foreach ($matched as $meta) {
            $suggestions[] = new CompletionSuggestion(
                display: '/'.$meta->name,
                insertText: '/'.$meta->name.' ',
                description: $meta->description,
                replacementStart: $replacementStart,
                replacementLength: \strlen($prefix) + 1, // +1 for the leading slash
            );
        }

        return $suggestions;
    }

    /**
     * Extract the slash prefix and its replacement start position.
     *
     * Only triggers when the full editor text starts with "/".
     * Escaped slashes "//" are NOT treated as slash commands.
     * Text where "/" appears after a newline or elsewhere does not
     * trigger completion — the leading character must be "/".
     *
     * @return array{string, int}|null [prefix (after slash), replacementStart byte offset]
     */
    private function extractSlashContext(string $text): ?array
    {
        if (!str_starts_with($text, '/')) {
            return null;
        }

        // "//" is an escaped slash — not a command
        if (\strlen($text) >= 2 && '/' === $text[1]) {
            return null;
        }

        // Prefix is everything after the leading "/"
        $prefix = substr($text, 1);

        return [$prefix, 0];
    }
}
