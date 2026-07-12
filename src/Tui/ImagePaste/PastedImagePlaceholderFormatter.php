<?php

declare(strict_types=1);

namespace Ineersa\Tui\ImagePaste;

/**
 * Stable editor placeholder and LLM-visible reference text for pasted images.
 *
 * Placeholders are sequential per TUI session: [Image #1], [Image #2], …
 */
final class PastedImagePlaceholderFormatter
{
    public const string PLACEHOLDER_PATTERN = '/\[Image #(\d+)\]/';

    public static function placeholder(int $index): string
    {
        return \sprintf('[Image #%d]', $index);
    }

    /**
     * Text injected into the canonical user prompt after promotion.
     *
     * @param string $projectRelativePath Path relative to project cwd (POSIX slashes)
     */
    public static function llmReference(int $index, string $projectRelativePath): string
    {
        return \sprintf(
            '[Image #%d: %s — inspect this file with the view_image tool]',
            $index,
            $projectRelativePath,
        );
    }
}
