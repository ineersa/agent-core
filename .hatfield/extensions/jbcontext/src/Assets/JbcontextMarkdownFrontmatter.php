<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Assets;

use Symfony\Component\Yaml\Yaml;

/**
 * Minimal Markdown frontmatter parse/dump for package-local asset install.
 */
final class JbcontextMarkdownFrontmatter
{
    /**
     * @return array{frontmatter: array<string, mixed>, body: string}
     */
    public static function parse(string $raw): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", $raw);
        if (!str_starts_with($content, "---\n")) {
            return ['frontmatter' => [], 'body' => $content];
        }

        $end = strpos($content, "\n---\n", 4);
        if (false === $end) {
            return ['frontmatter' => [], 'body' => $content];
        }

        $yamlBlock = trim(substr($content, 4, $end - 4));
        $body = substr($content, $end + \strlen("\n---\n"));
        $parsed = '' !== $yamlBlock ? Yaml::parse($yamlBlock) : [];

        return [
            'frontmatter' => \is_array($parsed) ? $parsed : [],
            'body' => $body,
        ];
    }

    /**
     * @param array<string, mixed> $frontmatter
     */
    public static function dump(array $frontmatter, string $body): string
    {
        $yaml = trim(Yaml::dump($frontmatter, 4, 2));

        return "---\n".$yaml."\n---\n".$body;
    }
}
