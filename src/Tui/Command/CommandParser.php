<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

/**
 * Parser that distinguishes normal prompts from slash commands and
 * shell commands in submitted editor text.
 *
 * Existing filesystem paths take precedence over slash commands.
 */
final readonly class CommandParser
{
    /**
     * Parse submitted text and return the appropriate discriminated result.
     *
     * Rules (applied after trimming leading/trailing whitespace):
     *  - Empty string → NormalPrompt
     *  - Starts with "//" → NormalPrompt (escaped slash, not a command)
     *  - Starts with an existing file/directory path → NormalPrompt
     *  - Starts with "/" followed by word chars → SlashCommand
     *    (name = first word after "/", lowercased; args = rest, trimmed)
     *  - Starts with "!!" → unsupported (EDITOR-11 MVP: only single !)
     *  - Starts with "!" → ShellCommand
     *  - Everything else → NormalPrompt
     */
    public function parse(string $submittedText): CommandParseResult
    {
        $text = trim($submittedText);

        if ('' === $text) {
            return new NormalPromptCommand();
        }

        // Escaped slash: "//..." — not a command
        if (str_starts_with($text, '//')) {
            return new NormalPromptCommand();
        }

        // "!!" prefix is intentionally not supported in EDITOR-11 MVP.
        // The parser still recognizes it as a shell command so the router
        // can produce a clear unsupported-message rather than silently
        // treating it as bash.
        if (str_starts_with($text, '!!')) {
            $command = trim(substr($text, 2));

            return new ShellCommand($command, $text);
        }

        // Shell: "!..."
        if (str_starts_with($text, '!')) {
            $command = trim(substr($text, 1));

            return new ShellCommand($command, $text);
        }

        // Slash command: "/<name>[ <args>]"
        if (str_starts_with($text, '/')) {
            $rest = substr($text, 1);

            // "/" alone or "/ " or "/@something" (not a word char) → NormalPrompt
            if ('' === $rest || !$this->isWordChar($rest[0])) {
                return new NormalPromptCommand();
            }

            // Extract name (first word) and args (rest)
            $parts = preg_split('/\s+/', $rest, 2);
            \assert(\is_array($parts));
            $path = '/'.$parts[0];
            if (is_file($text) || is_dir($text) || is_file($path) || is_dir($path)) {
                return new NormalPromptCommand();
            }

            $name = strtolower(trim($parts[0]));
            $args = isset($parts[1]) ? trim($parts[1]) : '';

            return new SlashCommand($name, $args, $text);
        }

        return new NormalPromptCommand();
    }

    /**
     * Check whether a character is a "word character" (alphanumeric or underscore).
     */
    private function isWordChar(string $char): bool
    {
        return ctype_alnum($char) || '_' === $char;
    }
}
