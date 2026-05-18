<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

/**
 * Pure parser that distinguishes normal prompts from slash commands and
 * shell commands in submitted editor text.
 *
 * No Symfony TUI or AgentCore dependencies — pure domain logic.
 */
final readonly class CommandParser
{
    /**
     * Parse submitted text and return the appropriate discriminated result.
     *
     * Rules (applied after trimming leading/trailing whitespace):
     *  - Empty string → NormalPrompt
     *  - Starts with "//" → NormalPrompt (escaped slash, not a command)
     *  - Starts with "/" followed by word chars → SlashCommand
     *    (name = first word after "/", lowercased; args = rest, trimmed)
     *  - Starts with "!!" → ShellCommand(hidden: true)
     *  - Starts with "!" → ShellCommand(hidden: false)
     *  - Everything else → NormalPrompt
     */
    public function parse(string $submittedText): CommandParseResult
    {
        $text = trim($submittedText);

        if ('' === $text) {
            return new NormalPrompt($text);
        }

        // Escaped slash: "//..." — not a command
        if (str_starts_with($text, '//')) {
            return new NormalPrompt($text);
        }

        // Shell hidden: "!!..."
        if (str_starts_with($text, '!!')) {
            $command = trim(substr($text, 2));

            return new ShellCommand($command, true, $text);
        }

        // Shell visible: "!..."
        if (str_starts_with($text, '!')) {
            $command = trim(substr($text, 1));

            return new ShellCommand($command, false, $text);
        }

        // Slash command: "/<name>[ <args>]"
        if (str_starts_with($text, '/')) {
            $rest = substr($text, 1);

            // "/" alone or "/ " or "/@something" (not a word char) → NormalPrompt
            if ('' === $rest || !$this->isWordChar($rest[0])) {
                return new NormalPrompt($text);
            }

            // Extract name (first word) and args (rest)
            $parts = preg_split('/\s+/', $rest, 2);
            \assert(\is_array($parts));
            $name = strtolower(trim($parts[0]));
            $args = isset($parts[1]) ? trim($parts[1]) : '';

            return new SlashCommand($name, $args, $text);
        }

        return new NormalPrompt($text);
    }

    /**
     * Check whether a character is a "word character" (alphanumeric or underscore).
     */
    private function isWordChar(string $char): bool
    {
        return ctype_alnum($char) || '_' === $char;
    }
}
