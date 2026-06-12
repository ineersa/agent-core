<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\PromptTemplate;

/**
 * Internal value object for a successfully loaded prompt template.
 *
 * @internal
 */
final readonly class LoadedPromptTemplate
{
    public function __construct(
        /** Lowercase command name derived from filename stem. */
        public string $name,
        /** Short description from frontmatter or first body line. */
        public string $description,
        /** Template body content (after frontmatter extraction). */
        public string $content,
        /** Absolute file path where the template was loaded from. */
        public string $filePath,
    ) {
    }
}
