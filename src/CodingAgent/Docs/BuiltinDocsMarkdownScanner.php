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
     * GitHub-style heading slugs from Markdown body (outside fenced code via AST).
     *
     * Duplicate headings receive -1, -2, … suffixes matching common GitHub behavior.
     *
     * @return list<string>
     */
    public function headingSlugs(string $markdown): array
    {
        $document = $this->parser->parse($markdown);
        $slugs = [];
        $counts = [];
        $this->walk($document, function (Node $node) use (&$slugs, &$counts): void {
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
            $slugs[] = 0 === $n ? $base : $base.'-'.$n;
        });

        return $slugs;
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
