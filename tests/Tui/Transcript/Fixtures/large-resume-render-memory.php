<?php

declare(strict_types=1);

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\ThemeStyleSheetFactory;
use Ineersa\Tui\Transcript\TranscriptMountedWidget;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\TextWidget;

require dirname(__DIR__, 4).'/vendor/autoload.php';

/*
 * Strict-128M first-frame render probe for a large mounted transcript.
 *
 * Mirrors the measured session-2 retention shape (~1350 blocks, ~600 markdown
 * widgets, ~800 KiB visible text) with synthetic content only. Markers are
 * checked on mounted widget text so the probe does not materialize a full
 * multi-thousand-line VirtualTerminal write buffer.
 */
ini_set('memory_limit', '128M');

$runId = 'large-resume-render';
$blocks = [];
$seq = 1;

$blocks[] = new TranscriptBlock(
    id: 'user-anchor',
    kind: TranscriptBlockKindEnum::UserMessage,
    runId: $runId,
    seq: $seq++,
    text: 'MEMORY_MARKER_EARLY user asks for a long retained transcript resume.',
);

for ($i = 0; $i < 413; ++$i) {
    $blocks[] = new TranscriptBlock(
        id: 'thinking-'.$i,
        kind: TranscriptBlockKindEnum::AssistantThinking,
        runId: $runId,
        seq: $seq++,
        text: 'thinking step '.$i.' '.str_repeat('token ', 12),
    );
}

for ($i = 0; $i < 113; ++$i) {
    $body = str_repeat('assistant paragraph with **bold**, `code`, and a [link](https://example.test). ', 8 + ($i % 12));
    if (0 === $i % 7) {
        $body .= "\n\n```php\n".str_repeat("echo {$i};\n", 15)."```\n";
    }
    $blocks[] = new TranscriptBlock(
        id: 'assistant-'.$i,
        kind: TranscriptBlockKindEnum::AssistantMessage,
        runId: $runId,
        seq: $seq++,
        text: $body,
    );
}

for ($i = 0; $i < 740; ++$i) {
    $tool = 0 === $i % 3 ? 'read' : (0 === $i % 2 ? 'bash' : 'edit');
    $blocks[] = new TranscriptBlock(
        id: 'tool-'.$i,
        kind: TranscriptBlockKindEnum::ToolCall,
        runId: $runId,
        seq: $seq++,
        text: $tool.' '.str_repeat("path=/tmp/synthetic-{$i}.txt preview line\n", 12),
        meta: [
            'tool_name' => $tool,
            'tool_call_id' => 'call-'.$i,
            'arguments' => ['path' => '/tmp/synthetic-'.$i.'.txt', 'limit' => 20],
        ],
    );
}

for ($i = 0; $i < 82; ++$i) {
    $blocks[] = new TranscriptBlock(
        id: 'user-'.$i,
        kind: TranscriptBlockKindEnum::UserMessage,
        runId: $runId,
        seq: $seq++,
        text: 'follow-up '.$i.' '.str_repeat('please continue with the retained history. ', 6),
    );
}

$blocks[] = new TranscriptBlock(
    id: 'assistant-late',
    kind: TranscriptBlockKindEnum::AssistantMessage,
    runId: $runId,
    seq: $seq++,
    text: 'MEMORY_MARKER_LATE final retained assistant answer with **markdown**.',
);

$textSum = 0;
foreach ($blocks as $block) {
    $textSum += strlen($block->text);
}

$palette = new ThemePalette('large-resume-render', []);
$theme = new DefaultTheme($palette);
$terminal = new VirtualTerminal(columns: 120, rows: 40);
$tui = new Tui(terminal: $terminal);
$tui->addStyleSheet((new ThemeStyleSheetFactory())->createMarkdown($palette));
$transcript = new TranscriptMountedWidget(theme: $theme);
$tui->add($transcript);
$transcript->setBlocks($blocks);
$tui->requestRender(force: true);
$tui->processRender();

$joined = joinedWidgetText($transcript);

echo json_encode([
    'ok' => true,
    'limit' => ini_get('memory_limit'),
    'blocks' => count($blocks),
    'text_sum' => $textSum,
    'peak_bytes' => memory_get_peak_usage(true),
    'has_early' => str_contains($joined, 'MEMORY_MARKER_EARLY'),
    'has_late' => str_contains($joined, 'MEMORY_MARKER_LATE'),
    'markdown_widgets' => countMarkdownWidgets($transcript),
], \JSON_THROW_ON_ERROR), "\n";

function joinedWidgetText(AbstractWidget $root): string
{
    $parts = [];
    $stack = [$root];
    while ([] !== $stack) {
        $widget = array_pop($stack);
        if ($widget instanceof MarkdownWidget || $widget instanceof TextWidget) {
            $parts[] = $widget->getText();
        }
        if ($widget instanceof ContainerWidget) {
            foreach ($widget->all() as $child) {
                $stack[] = $child;
            }
        }
    }

    return implode("\n", $parts);
}

function countMarkdownWidgets(AbstractWidget $root): int
{
    $count = 0;
    $stack = [$root];
    while ([] !== $stack) {
        $widget = array_pop($stack);
        if ($widget instanceof MarkdownWidget) {
            ++$count;
        }
        if ($widget instanceof ContainerWidget) {
            foreach ($widget->all() as $child) {
                $stack[] = $child;
            }
        }
    }

    return $count;
}
