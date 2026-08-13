<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Docs;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Node\Inline\AbstractStringContainer;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;

/**
 * Shared Markdown scan for built-in documentation validation.
 *
 * Uses league/commonmark AST so headings/links respect fenced code blocks,
 * common indentation, link titles, and angle-bracket destinations without a
 * second hand-rolled parser.
 *
 * @internal
 */
final class BuiltinDocsMarkdownScanner
{
    private readonly MarkdownParser $parser;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => true,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $this->parser = new MarkdownParser($environment);
    }

    /**
     * Headings from Markdown body (outside fenced code via AST).
     *
     * @return list<array{level: int, text: string, slug: string}>
     */
    public function headings(string $markdown): array
    {
        $document = $this->parser->parse($markdown);
        $headings = [];
        $counts = [];
        $this->walk($document, function (Node $node) use (&$headings, &$counts): void {
            if (!$node instanceof Heading) {
                return;
            }
            $text = trim($this->plainText($node));
            if ('' === $text) {
                return;
            }
            $base = BuiltinDocsCatalog::githubStyleHeadingSlug($text);
            if ('' === $base) {
                return;
            }
            $n = $counts[$base] ?? 0;
            $counts[$base] = $n + 1;
            $headings[] = [
                'level' => $node->getLevel(),
                'text' => $text,
                'slug' => 0 === $n ? $base : $base.'-'.$n,
            ];
        });

        return $headings;
    }

    /**
     * GitHub-style heading slugs from Markdown body (outside fenced code via AST).
     *
     * Duplicate headings receive -1, -2, … suffixes matching common GitHub behavior.
     *
     * @return list<string>
     */
    public function headingSlugs(string $markdown): array
    {
        return array_map(
            static fn (array $heading): string => $heading['slug'],
            $this->headings($markdown),
        );
    }

    /**
     * Exactly one useful H1 title outside fenced code, or null when zero/many.
     *
     * @return array{title: string, count: int}
     */
    public function usefulH1(string $markdown): array
    {
        $titles = [];
        foreach ($this->headings($markdown) as $heading) {
            if (1 === $heading['level']) {
                $titles[] = $heading['text'];
            }
        }

        return [
            'title' => 1 === \count($titles) ? $titles[0] : '',
            'count' => \count($titles),
        ];
    }

    /**
     * Relative/local Markdown link destinations (URL field only; titles ignored).
     *
     * @return list<string>
     */
    public function linkDestinations(string $markdown): array
    {
        $document = $this->parser->parse($markdown);
        $destinations = [];
        $this->walk($document, static function (Node $node) use (&$destinations): void {
            if (!$node instanceof Link) {
                return;
            }
            $url = trim($node->getUrl());
            if ('' === $url) {
                return;
            }
            $destinations[] = $url;
        });

        return $destinations;
    }

    /**
     * @param callable(Node): void $visitor
     */
    private function walk(Node $node, callable $visitor): void
    {
        $visitor($node);
        $child = $node->firstChild();
        while (null !== $child) {
            $this->walk($child, $visitor);
            $child = $child->next();
        }
    }

    private function plainText(Node $node): string
    {
        $parts = [];
        $this->walk($node, static function (Node $current) use (&$parts): void {
            if ($current instanceof AbstractStringContainer) {
                $parts[] = $current->getLiteral();
            }
        });

        return implode('', $parts);
    }
}
