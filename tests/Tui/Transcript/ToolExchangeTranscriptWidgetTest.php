<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\ToolExchangeTranscriptWidget;
use Ineersa\Tui\Transcript\TranscriptBlockWidgetFactory;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use Ineersa\Tui\Transcript\TranscriptVisualNode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToolExchangeTranscriptWidgetTest extends TestCase
{
    /** @return iterable<string, array{TranscriptBlockKindEnum, array<string, mixed>, bool}> */
    public static function textChanges(): iterable
    {
        yield 'generic arguments' => [TranscriptBlockKindEnum::ToolCall, ['tool_name' => 'bash', 'arguments' => ['command' => 'true']], true];
        yield 'skill read arguments' => [TranscriptBlockKindEnum::ToolCall, ['tool_name' => 'read', 'skill_name' => 'testing', 'arguments' => ['offset' => 2]], true];
        yield 'edit arguments' => [TranscriptBlockKindEnum::ToolCall, ['tool_name' => 'edit', 'arguments' => ['path' => 'fixture.txt', 'patch' => "@@\n-before\n+after"]], true];
        yield 'write arguments' => [TranscriptBlockKindEnum::ToolCall, ['tool_name' => 'write', 'arguments' => ['path' => 'fixture.txt', 'content' => 'body']], true];
        yield 'image arguments' => [TranscriptBlockKindEnum::ToolCall, ['tool_name' => 'view_image', 'arguments' => ['path' => 'fixture.png']], true];
        yield 'fallback call header' => [TranscriptBlockKindEnum::ToolCall, [], false];
        yield 'canonical result progress' => [TranscriptBlockKindEnum::ToolResult, ['tool_name' => 'read', 'result' => 'canonical body'], true];
        yield 'failed canonical result progress' => [TranscriptBlockKindEnum::ToolResult, ['tool_name' => 'edit', 'result' => 'diagnostic body', 'is_error' => true], true];
        yield 'collapsed read progress' => [TranscriptBlockKindEnum::ToolResult, ['tool_name' => 'read'], true];
        yield 'failed read progress' => [TranscriptBlockKindEnum::ToolResult, ['tool_name' => 'read', 'is_error' => true], false];
        yield 'visible bash progress' => [TranscriptBlockKindEnum::ToolResult, ['tool_name' => 'bash'], false];
        yield 'image progress' => [TranscriptBlockKindEnum::ToolResult, ['tool_name' => 'view_image'], true];
        yield 'canonical structured result' => [TranscriptBlockKindEnum::ToolResult, ['tool_name' => 'custom', 'result' => ['field' => 'body']], true];
        yield 'fallback result header' => [TranscriptBlockKindEnum::ToolResult, ['result' => 'canonical body'], false];
    }

    /** @param array<string, mixed> $meta */
    #[DataProvider('textChanges')]
    public function testOnlyRendererInvisibleTextKeepsNativeCard(TranscriptBlockKindEnum $kind, array $meta, bool $preserved): void
    {
        $state = new TranscriptDisplayState();
        $factory = new TranscriptBlockWidgetFactory(displayState: $state);
        $widget = new ToolExchangeTranscriptWidget($factory, new DefaultTheme(new ThemePalette('test', [])));
        $source = new TranscriptBlock('source', $kind, 'isolated-widget', 1, 'before text', $meta, true);
        $node = new TranscriptVisualNode('key', TranscriptVisualNode::KIND_TOOL_EXCHANGE, $source, null, 0);
        $widget->apply($node);
        $before = $widget->all()[0];

        $source = $source->with(text: 'latest argument/progress text');
        $node = new TranscriptVisualNode('key', TranscriptVisualNode::KIND_TOOL_EXCHANGE, $source, null, 0);
        $widget->apply($node);
        $this->assertSame($preserved, $before === $widget->all()[0]);
        $this->assertSame($node, new \ReflectionProperty($widget, 'node')->getValue($widget), 'Ignored text still updates retained source identity');

        $beforeExpansion = $widget->all()[0];
        $state->previewableBlocksExpanded = true;
        $widget->apply(new TranscriptVisualNode('key', TranscriptVisualNode::KIND_TOOL_EXCHANGE, $source, null, 1));
        $this->assertNotSame($beforeExpansion, $widget->all()[0], 'Preview revision invalidates even an otherwise invisible update');

        if (TranscriptBlockKindEnum::ToolResult === $kind && ['tool_name' => 'read'] === $meta) {
            $expanded = $widget->all()[0];
            $source = $source->with(text: 'expanded read progress is visible');
            $widget->apply(new TranscriptVisualNode('key', TranscriptVisualNode::KIND_TOOL_EXCHANGE, $source, null, 1));
            $this->assertNotSame($expanded, $widget->all()[0]);
        }
    }
}
