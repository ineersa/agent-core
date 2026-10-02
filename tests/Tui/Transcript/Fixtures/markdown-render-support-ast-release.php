<?php

declare(strict_types=1);

use Ineersa\Tui\Transcript\MarkdownRenderSupport;
use Ineersa\Tui\Transcript\TranscriptBlockWidgetFactory;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\Markdown\DarkTerminalTheme;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Tempest\Highlight\Highlighter;

require dirname(__DIR__, 4).'/vendor/autoload.php';

/*
 * Widget-owned parser AST release probe.
 *
 * Mode production: factory-shared Environment/Highlighter, widget-owned parser.
 * Mode shared-parser: one parser reused across widgets (rejected design).
 * Both drop the rendered widget, collect cycles, and report whether the
 * Document remains reachable without another parse. Production keeps the
 * factory; shared-parser keeps the shared parser.
 */
ini_set('memory_limit', '128M');

$mode = $argv[1] ?? 'production';
$text = str_repeat("A paragraph about AST release and widget ownership.\n\n", 220);
$closedProperty = new ReflectionProperty(MarkdownParser::class, 'closedBlockParsers');
$parserProperty = new ReflectionProperty(MarkdownWidget::class, 'parser');
$factory = null;
$parserRetainer = null;

if ('production' === $mode) {
    $support = new MarkdownRenderSupport();
    $factory = new TranscriptBlockWidgetFactory(markdown: $support);
    $widget = $support->create($text);
    $parser = $parserProperty->getValue($widget);
} elseif ('shared-parser' === $mode) {
    $environment = new Environment();
    $environment->addExtension(new CommonMarkCoreExtension());
    $environment->addExtension(new GithubFlavoredMarkdownExtension());
    $highlighter = new Highlighter(new DarkTerminalTheme());
    $parserRetainer = new MarkdownParser($environment);
    $widget = new MarkdownWidget($text, $parserRetainer, $highlighter);
    $parser = $parserRetainer;
} else {
    fwrite(\STDERR, "unknown mode: {$mode}\n");
    exit(2);
}

if (!$parser instanceof MarkdownParser) {
    fwrite(\STDERR, "expected MarkdownParser\n");
    exit(2);
}

$terminal = new VirtualTerminal(columns: 80, rows: 24);
$tui = new Tui(terminal: $terminal);
$tui->add($widget);
$tui->requestRender(force: true);
$tui->processRender();

$closed = $closedProperty->getValue($parser);
$firstBlock = $closed[0]->getBlock();
$document = documentRoot($firstBlock);
$weakDocument = WeakReference::create($document);
$closedBefore = count($closed);

unset($document, $firstBlock, $closed, $widget, $tui, $terminal, $parser);
gc_collect_cycles();

$alive = $weakDocument->get() instanceof Document;

echo json_encode([
    'ok' => true,
    'mode' => $mode,
    'closed_before_evict' => $closedBefore,
    'root_class' => 'Document',
    'document_alive_after_evict' => $alive,
    'factory_retained' => null !== $factory,
    'shared_parser_retained' => null !== $parserRetainer,
    'parsed_after_evict' => false,
], \JSON_THROW_ON_ERROR), "\n";

function documentRoot(Node $node): Document
{
    $current = $node;
    while (null !== $current->parent()) {
        $current = $current->parent();
    }

    if (!$current instanceof Document) {
        throw new RuntimeException('expected Document root, got '.$current::class);
    }

    return $current;
}
