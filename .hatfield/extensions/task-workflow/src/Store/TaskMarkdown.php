<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Store;

final class TaskMarkdown
{
    public static function slugify(string $input): string
    {
        $slug = strtolower($input);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ('' === $slug) {
            return 'task';
        }

        return substr($slug, 0, 80);
    }

    public static function today(): string
    {
        return (new \DateTimeImmutable('now'))->format('Y-m-d');
    }

    /**
     * @param list<string>|null $acceptance
     */
    public static function renderTask(string $title, ?string $body = null, ?array $acceptance = null): string
    {
        $lines = [
            '# '.$title,
            '',
            '## Goal',
            null !== $body && '' !== trim($body) ? trim($body) : 'TODO: describe the task.',
            '',
            '## Acceptance criteria',
        ];
        if (null !== $acceptance && [] !== $acceptance) {
            foreach ($acceptance as $item) {
                $lines[] = '- '.$item;
            }
        } else {
            $lines[] = '- TODO: add acceptance criteria.';
        }
        $lines[] = '';
        $lines[] = '## Workflow metadata';
        $lines[] = 'Status: TODO';
        $lines[] = 'Branch:';
        $lines[] = 'Worktree:';
        $lines[] = 'Fork run:';
        $lines[] = 'PR URL:';
        $lines[] = 'PR Status:';
        $lines[] = 'Started:';
        $lines[] = 'Completed:';
        $lines[] = '';
        $lines[] = '## Work log';
        $lines[] = '- Created: '.(new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM);
        $lines[] = '';

        return implode("\n", $lines);
    }

    public static function extractTitle(string $text, string $file): string
    {
        if (1 === preg_match('/^#\s+(.+)$/m', $text, $m)) {
            return trim($m[1]);
        }

        return preg_replace('/\.md$/', '', $file) ?? $file;
    }

    public static function extractField(string $text, string $name): ?string
    {
        $escaped = preg_quote($name, '/');
        // Horizontal whitespace only after the colon. PHP `\s` includes newlines, so
        // empty fields like `Worktree:\nFork run:` would otherwise capture the next label.
        if (1 === preg_match('/^'.$escaped.':[ \t]*([^\r\n]*)$/mi', $text, $m)) {
            $v = trim($m[1]);

            return '' === $v ? null : $v;
        }

        return null;
    }

    public static function updateField(string $text, string $name, string $value): string
    {
        $escaped = preg_quote($name, '/');
        $regex = '/^'.$escaped.':.*$/mi';
        if (1 === preg_match($regex, $text)) {
            return preg_replace($regex, $name.': '.$value, $text) ?? $text;
        }

        return rtrim($text)."\n".$name.': '.$value."\n";
    }

    /**
     * @param list<string> $lines
     */
    public static function appendLog(string $text, array $lines): string
    {
        $iso = (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM);
        $block = "\n\n## Task workflow update - ".$iso."\n";
        foreach ($lines as $line) {
            $block .= '- '.$line."\n";
        }

        return rtrim($text).$block;
    }
}
