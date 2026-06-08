<?php

declare(strict_types=1);

namespace Ineersa\Tui\Completion;

use Ineersa\Tui\Command\SlashCommandRegistry;

/**
 * Completion provider for slash commands.
 *
 * Triggers only when the current cursor-at-end context is a slash
 * at start of text or after a newline at column 0.  Matches both
 * canonical command names and aliases; inserts canonical command
 * text when an alias match is accepted.
 *
 * Uses {@see SlashCommandRegistry::allMetadata()} at suggestion time
 * so runtime-registered commands (e.g. /model) are included.
 */
final readonly class SlashCommandCompletionProvider implements CompletionProvider
{
    public function __construct(
        private SlashCommandRegistry $registry,
    ) {
    }

    public function getSuggestions(string $text): array
    {
        $slashContext = $this->extractSlashContext($text);

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
     * Only triggers when the text ends with a slash command context:
     * text starts with "/", or text contains "\n/" where "/" is at
     * column 0 of the last line.  Escaped slashes "//" are NOT
     * treated as slash commands.
     *
     * @return array{string, int}|null [prefix (after slash), replacementStart byte offset]
     */
    private function extractSlashContext(string $text): ?array
    {
        $len = \strlen($text);

        // Empty text — no context
        if (0 === $len) {
            return null;
        }

        // Find the start of the last line
        $lastNewlinePos = strrpos($text, "\n");

        if (false === $lastNewlinePos) {
            // Single-line text
            $line = $text;
            $lineStart = 0;
        } else {
            // Multi-line — check the last line
            $lineStart = $lastNewlinePos + 1;
            $line = substr($text, $lineStart);
        }

        // Must start with "/"
        if (!str_starts_with($line, '/')) {
            return null;
        }

        // "//" is an escaped slash — not a command
        if (\strlen($line) >= 2 && '/' === $line[1]) {
            return null;
        }

        // Prefix is everything after the "/"
        $prefix = substr($line, 1);

        return [$prefix, $lineStart];
    }
}
