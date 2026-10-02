<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Parser\MarkdownParser;
use Symfony\Component\Tui\Widget\Markdown\DarkTerminalTheme;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Tempest\Highlight\Highlighter;

/**
 * Owns one CommonMark Environment and Tempest highlighter for many MarkdownWidget instances.
 *
 * Symfony {@see MarkdownWidget} already accepts optional shared dependencies; without
 * them each widget builds its own GFM environment and highlighter. Resume of a long
 * transcript creates hundreds of markdown widgets, so the owning transcript factory
 * keeps one support object for the screen lifetime.
 *
 * Parsers stay widget-owned. {@see MarkdownParser} retains the last document through
 * closedBlockParsers until the next parse, so a shared parser would pin evicted
 * transcript ASTs for the screen lifetime. Spec §10 allows shared parser/highlighter
 * infrastructure, but not parsed documents for evicted blocks.
 */
final readonly class MarkdownRenderSupport
{
    private Environment $environment;

    private Highlighter $highlighter;

    public function __construct()
    {
        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $this->environment = $environment;
        $this->highlighter = new Highlighter(new DarkTerminalTheme());
    }

    public function create(string $text): MarkdownWidget
    {
        return new MarkdownWidget($text, new MarkdownParser($this->environment), $this->highlighter);
    }
}
