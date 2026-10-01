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
 * Owns one CommonMark parser and Tempest highlighter for many MarkdownWidget instances.
 *
 * Symfony {@see MarkdownWidget} already accepts optional shared dependencies; without
 * them each widget builds its own GFM environment and highlighter. Resume of a long
 * transcript creates hundreds of markdown widgets, so the owning transcript factory
 * keeps one support object for the screen lifetime.
 */
final readonly class MarkdownRenderSupport
{
    private MarkdownParser $parser;

    private Highlighter $highlighter;

    public function __construct()
    {
        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $this->parser = new MarkdownParser($environment);
        $this->highlighter = new Highlighter(new DarkTerminalTheme());
    }

    public function create(string $text): MarkdownWidget
    {
        return new MarkdownWidget($text, $this->parser, $this->highlighter);
    }
}
