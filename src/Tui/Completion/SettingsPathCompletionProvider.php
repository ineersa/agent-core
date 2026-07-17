<?php

declare(strict_types=1);

namespace Ineersa\Tui\Completion;

/**
 * Completes /settings-show path arguments from fresh effective settings.
 *
 * Triggers only for editor text matching "/settings-show <prefix>" where
 * <prefix> is a single no-whitespace dotted path fragment (or empty after
 * the trailing space). Command-name completion remains with
 * {@see SlashCommandCompletionProvider}.
 *
 * Suggestions are DIRECT children of the current effective map:
 *   /settings-show        → top-level keys
 *   /settings-show tui.   → direct children of tui
 *   /settings-show tui.t  → direct children under tui whose final segment starts with t
 *
 * Accepted insert text is the complete dotted path without a forced trailing
 * dot so group paths remain executable.
 *
 * Fresh effective settings come through {@see SettingsPathCompletionSourceInterface}
 * so TuiCompletion stays free of AppConfig dependencies (Deptrac).
 */
final readonly class SettingsPathCompletionProvider implements CompletionProvider
{
    public function __construct(
        private SettingsPathCompletionSourceInterface $settingsSource,
    ) {
    }

    public function getSuggestions(CompletionContext $context): array
    {
        $prefix = $this->extractPathPrefix($context->text);
        if (null === $prefix) {
            return [];
        }

        $effective = $this->settingsSource->loadEffectiveSettings();
        $parentPath = '';
        $segmentPrefix = $prefix;
        if (str_contains($prefix, '.')) {
            $dot = strrpos($prefix, '.');
            $parentPath = substr($prefix, 0, $dot);
            $segmentPrefix = substr($prefix, $dot + 1);
        }

        $parent = $this->mapAtPath($effective, $parentPath);
        if (null === $parent) {
            return [];
        }

        $replacementStart = \strlen('/settings-show ');
        $replacementLength = \strlen($prefix);
        $suggestions = [];

        foreach ($parent as $key => $value) {
            $segment = (string) $key;
            if ('' !== $segmentPrefix && !str_starts_with($segment, $segmentPrefix)) {
                continue;
            }

            $fullPath = '' === $parentPath ? $segment : $parentPath.'.'.$segment;
            $isGroup = \is_array($value) && [] !== $value && !array_is_list($value);
            $suggestions[] = new CompletionSuggestion(
                display: $fullPath,
                insertText: $fullPath,
                description: $isGroup ? 'settings group' : 'setting',
                replacementStart: $replacementStart,
                replacementLength: $replacementLength,
            );
        }

        usort(
            $suggestions,
            static fn (CompletionSuggestion $a, CompletionSuggestion $b): int => $a->display <=> $b->display,
        );

        return $suggestions;
    }

    /**
     * @return string|null path prefix including empty string after trailing space
     */
    private function extractPathPrefix(string $text): ?string
    {
        if (!preg_match('#^/settings-show(?:\s+([^\s]*))?$#', $text, $matches)) {
            return null;
        }

        // Require the space after the command so SlashCommandCompletionProvider
        // still owns bare "/settings-show" name completion.
        if (!str_starts_with($text, '/settings-show ')) {
            return null;
        }

        return $matches[1] ?? '';
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    private function mapAtPath(array $data, string $path): ?array
    {
        if ('' === $path) {
            return $data;
        }

        $current = $data;
        foreach (explode('.', $path) as $segment) {
            if (!\is_array($current) || !\array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        if (!\is_array($current) || [] === $current || array_is_list($current)) {
            return null;
        }

        return $current;
    }
}
