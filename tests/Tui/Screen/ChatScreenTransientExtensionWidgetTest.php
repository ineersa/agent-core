<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet;
use Ineersa\Hatfield\ExtensionApi\Tui\TransientTuiExtensionContextInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiSemanticColorEnum;
use Ineersa\Tui\Runtime\BridgeTuiExtensionContext;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\ThemeColorEnum;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Thesis: ChatScreen hosts at most one temporary extension widget above the
 * editor; replace-on-show and clear on meaningful transcript transitions only.
 * Semantic styles resolve through the active theme palette.
 */
final class ChatScreenTransientExtensionWidgetTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    #[Test]
    public function showTransientWidgetRendersAboveEditorAndSecondShowReplacesFirst(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'transient-om');
        $screen = $harness->screen();

        $screen->showTransientExtensionWidget(new TextWidget('OM STATUS MARKER A'));
        $first = $harness->plainScreenText();
        $this->assertStringContainsString('OM STATUS MARKER A', $first);

        $screen->showTransientExtensionWidget(new TextWidget('OM STATUS MARKER B'));
        $second = $harness->plainScreenText();
        $this->assertStringContainsString('OM STATUS MARKER B', $second);
        $this->assertStringNotContainsString('OM STATUS MARKER A', $second);
    }

    #[Test]
    public function emptyTranscriptChangeDoesNotClearTransientWidget(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'transient-empty');
        $screen = $harness->screen();
        $screen->showTransientExtensionWidget(new TextWidget('KEEP TRANSIENT MARKER'));

        $screen->applyTranscriptChangeSet(TranscriptChangeSet::incremental([]));
        $text = $harness->plainScreenText();
        $this->assertStringContainsString('KEEP TRANSIENT MARKER', $text);
    }

    #[Test]
    public function nonEmptyIncrementalAndFullReplacementClearTransientWidget(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'transient-clear');
        $screen = $harness->screen();
        $block = new TranscriptBlock(
            id: 'b1',
            kind: TranscriptBlockKindEnum::UserMessage,
            runId: 'transient-clear',
            seq: 1,
            text: 'hello from user',
        );

        $screen->showTransientExtensionWidget(new TextWidget('CLEAR ON DELTA'));
        $screen->applyTranscriptChangeSet(TranscriptChangeSet::incremental([$block]));
        $this->assertStringNotContainsString('CLEAR ON DELTA', $harness->plainScreenText());

        $screen->showTransientExtensionWidget(new TextWidget('CLEAR ON FULL'));
        $screen->setTranscriptBlocks([$block]);
        $this->assertStringNotContainsString('CLEAR ON FULL', $harness->plainScreenText());
        $this->assertStringContainsString('hello from user', $harness->plainScreenText());
    }

    #[Test]
    public function bridgeCreateTextStyleMapsSemanticColorsFromActiveTheme(): void
    {
        $palette = new \Ineersa\Tui\Theme\ThemePalette('semantic-test', [
            ThemeColorEnum::Muted->value => 'gray',
            ThemeColorEnum::Warning->value => 'yellow',
            ThemeColorEnum::Accent->value => 'cyan',
            ThemeColorEnum::Error->value => 'red',
            ThemeColorEnum::Success->value => 'green',
            ThemeColorEnum::Text->value => 'white',
        ]);
        $harness = new VirtualTuiHarness(sessionId: 'bridge-style', palette: $palette);
        $state = new TuiSessionState('bridge-style');
        $runtime = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();
        $bridge = new BridgeTuiExtensionContext($runtime);
        $this->assertInstanceOf(TransientTuiExtensionContextInterface::class, $bridge);

        $warning = $bridge->createTextStyle(TuiSemanticColorEnum::Warning, dim: true, italic: true);
        $this->assertTrue($warning->getDim());
        $this->assertTrue($warning->getItalic());
        $this->assertNotNull($warning->getColor());
        $this->assertSame(
            [205, 205, 0],
            array_values($warning->getColor()->toRgb()),
        );

        $muted = $bridge->createTextStyle(TuiSemanticColorEnum::Muted);
        $this->assertNotNull($muted->getColor());
        $this->assertSame(
            [127, 127, 127],
            array_values($muted->getColor()->toRgb()),
        );

        $widget = new TextWidget('BRIDGE TRANSIENT');
        $widget->setStyle($muted);
        $bridge->showTransientWidget($widget);
        $this->assertStringContainsString('BRIDGE TRANSIENT', $harness->plainScreenText());
    }
}
