<?php

declare(strict_types=1);

namespace Ineersa\Tui\Completion;

/** Direct-child path completion for "/settings-show <prefix>" (exactly one space; Deptrac via source interface). */
final readonly class SettingsPathCompletionProvider implements CompletionProvider
{
    public function __construct(
        private SettingsPathCompletionSourceInterface $settingsSource,
    ) {
    }

    public function getSuggestions(CompletionContext $context): array
    {
        if (!preg_match('#^/settings-show ([^\s]*)$#', $context->text, $matches)) {
            return [];
        }

        $prefix = $matches[1];
        $parentPath = '';
        $segmentPrefix = $prefix;
        if (str_contains($prefix, '.')) {
            $dot = strrpos($prefix, '.');
            $parentPath = substr($prefix, 0, $dot);
            $segmentPrefix = substr($prefix, $dot + 1);
        }

        $parent = $this->mapAtPath($this->settingsSource->loadEffectiveSettings(), $parentPath);
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

        usort($suggestions, static fn (CompletionSuggestion $a, CompletionSuggestion $b): int => $a->display <=> $b->display);

        return $suggestions;
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

        return \is_array($current) && [] !== $current && !array_is_list($current) ? $current : null;
    }
}
