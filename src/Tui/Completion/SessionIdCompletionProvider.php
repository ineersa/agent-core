<?php

declare(strict_types=1);

namespace Ineersa\Tui\Completion;

/**
 * Completion provider for session IDs in /resume, /r, and /rename commands.
 *
 * Triggers only when the editor text matches one of:
 *   /resume <prefix>
 *   /r <prefix>
 *   /rename <prefix>
 *
 * Where <prefix> is an optional numeric prefix for the session ID.
 * When the user types a trailing space after the prefix
 * (e.g. "/rename 1 "), no completions are returned so the user can
 * type the new name without interference.
 *
 * Suggestions are filtered from the SessionCompletionSource
 * by session ID prefix.  Insert text includes a trailing space so
 * the cursor advances past the completed session ID.
 */
final readonly class SessionIdCompletionProvider implements CompletionProvider
{
    /** Max suggestions to return. */
    private const int MAX_SUGGESTIONS = 30;

    public function __construct(
        private SessionCompletionSourceInterface $sessionSource,
    ) {
    }

    public function getSuggestions(CompletionContext $context): array
    {
        // Parse session-id argument context from supported commands.
        $parsed = $this->extractSessionIdContext($context->text);

        if (null === $parsed) {
            return [];
        }

        [$command, $prefix] = $parsed;

        $sessions = $this->sessionSource->listCompletionSessions();

        if ([] === $sessions) {
            return [];
        }

        $prefixLower = mb_strtolower($prefix);
        $replacementStart = \strlen('/'.$command.' ');
        $replacementLength = \strlen($prefix);

        $suggestions = [];
        $count = 0;

        foreach ($sessions as $session) {
            if ($count >= self::MAX_SUGGESTIONS) {
                break;
            }

            $sessionId = $session->sessionId;
            $displayTitle = $session->displayTitle;

            // Filter by session ID prefix (case-insensitive, but IDs are numeric)
            if ('' !== $prefixLower && !str_starts_with($sessionId, $prefixLower)) {
                continue;
            }

            $suggestions[] = new CompletionSuggestion(
                display: '#'.$sessionId.' — '.$displayTitle,
                insertText: $sessionId.' ',
                description: 'Session '.$sessionId,
                replacementStart: $replacementStart,
                replacementLength: $replacementLength,
            );

            ++$count;
        }

        return $suggestions;
    }

    /**
     * Extract the session-id argument context from editor text.
     *
     * Returns the command name and the current session ID prefix when the
     * text matches "/<command> <prefix>".  Returns null when the text is
     * not a session-id argument context.
     *
     * Matches only cursor-at-end (EDITOR-08 MVP limitation). When the
     * prefix is followed by a space (e.g. "/rename 1 ") the trailing
     * space after the id means the user is typing the new name, so no
     * completions are offered.
     *
     * @return array{string, string}|null [command, prefix]
     */
    private function extractSessionIdContext(string $text): ?array
    {
        if (!str_starts_with($text, '/')) {
            return null;
        }

        // Match /resume, /r, or /rename followed by optional digits
        if (!preg_match('#^/(resume|r|rename)\s+(\d*)$#', $text, $matches)) {
            return null;
        }

        return [$matches[1], $matches[2]];
    }
}
