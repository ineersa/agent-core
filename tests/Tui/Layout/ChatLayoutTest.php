<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Layout;

use Ineersa\Tui\Editor\PromptEditorWidget;
use Ineersa\Tui\Footer\FooterBarWidget;
use Ineersa\Tui\Footer\FooterDataProvider;
use Ineersa\Tui\Footer\FooterSegment;
use Ineersa\Tui\Footer\FooterSegmentProvider;
use Ineersa\Tui\Header\HeaderWidget;
use Ineersa\Tui\Layout\ChatLayout;
use Ineersa\Tui\Layout\TuiSlotRegistry;
use Ineersa\Tui\Status\WorkingStatusWidget;
use Ineersa\Tui\Transcript\PendingMessagesWidget;
use Ineersa\Tui\Transcript\TranscriptWidget;
use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;
use Ineersa\Tui\Widget\WidgetPlacement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatLayout::class)]
#[CoversClass(TuiSlotRegistry::class)]
final class ChatLayoutTest extends TestCase
{
    private TuiRenderContext $context;
    private TuiSlotRegistry $registry;
    private HeaderWidget $header;
    private TranscriptWidget $transcript;
    private PendingMessagesWidget $pendingMessages;
    private WorkingStatusWidget $workingStatus;
    private PromptEditorWidget $editor;
    private FooterBarWidget $footer;
    private ChatLayout $layout;

    protected function setUp(): void
    {
        $this->context = new TuiRenderContext(terminalWidth: 80);

        $this->registry = new TuiSlotRegistry();

        $this->header = new HeaderWidget('Test App');
        $this->transcript = new TranscriptWidget();
        $this->pendingMessages = new PendingMessagesWidget();
        $this->workingStatus = new WorkingStatusWidget();
        $this->editor = new PromptEditorWidget();
        $this->footer = new FooterBarWidget($this->createFooterDataProvider());

        $this->layout = new ChatLayout(
            registry: $this->registry,
            header: $this->header,
            transcript: $this->transcript,
            pendingMessages: $this->pendingMessages,
            workingStatus: $this->workingStatus,
            editor: $this->editor,
            footer: $this->footer,
        );
    }

    public function testRenderEmptyLayout(): void
    {
        $lines = $this->layout->render($this->context);

        // Header, separator, welcome, working status, editor separator, editor, footer separator, footer
        self::assertGreaterThanOrEqual(6, \count($lines));
        self::assertStringContainsString('Test App', $lines[0]);
        self::assertStringContainsString('Welcome to Agent Core', $lines[2]);
    }

    public function testRenderOrder(): void
    {
        $lines = $this->layout->render($this->context);

        // Verify order: header first
        self::assertStringContainsString('Test App', $lines[0]);

        // Separator after header
        self::assertStringContainsString('─', $lines[1]);

        // Separator before editor
        $editorSepIndex = null;
        $footerSepIndex = null;
        foreach ($lines as $i => $line) {
            if (\str_contains($line, '❯')) {
                $editorSepIndex = $i - 1; // line before editor prompt
            }
        }
        self::assertNotNull($editorSepIndex, 'Editor prompt should exist in output');
    }

    public function testReplacementHeaderRendersInsteadOfDefault(): void
    {
        $customHeader = new class implements TuiWidget {
            public function render(TuiRenderContext $context): array
            {
                return ['  CUSTOM HEADER'];
            }
        };

        $this->registry->setHeader($customHeader);
        $lines = $this->layout->render($this->context);

        self::assertStringContainsString('CUSTOM HEADER', $lines[0]);
        self::assertStringNotContainsString('Test App', $lines[0]);
    }

    public function testReplacementFooterRendersInsteadOfDefault(): void
    {
        $customFooter = new class implements TuiWidget {
            public function render(TuiRenderContext $context): array
            {
                return ['  CUSTOM FOOTER'];
            }
        };

        $this->registry->setFooter($customFooter);
        $lines = $this->layout->render($this->context);

        // Last non-empty line should contain custom footer
        $lastLine = '';
        foreach (\array_reverse($lines) as $line) {
            if (\trim($line) !== '') {
                $lastLine = $line;
                break;
            }
        }

        self::assertStringContainsString('CUSTOM FOOTER', $lastLine);
    }

    public function testReplacementEditorRendersInsteadOfDefault(): void
    {
        $customEditor = new class implements TuiWidget {
            public function render(TuiRenderContext $context): array
            {
                return ['  CUSTOM EDITOR'];
            }
        };

        $this->registry->setEditorComponent($customEditor);
        $lines = $this->layout->render($this->context);

        $foundEditor = false;
        $foundCustom = false;
        foreach ($lines as $line) {
            if (\str_contains($line, '❯')) {
                $foundEditor = true;
            }
            if (\str_contains($line, 'CUSTOM EDITOR')) {
                $foundCustom = true;
            }
        }

        self::assertFalse($foundEditor, 'Default editor should not appear when custom is set');
        self::assertTrue($foundCustom, 'Custom editor should appear');
    }

    public function testWidgetAboveEditorAppearsInCorrectPosition(): void
    {
        $aboveWidget = new class implements TuiWidget {
            public function render(TuiRenderContext $context): array
            {
                return ['  WIDGET ABOVE'];
            }
        };

        $this->registry->setWidget('test_above', $aboveWidget, WidgetPlacement::AboveEditor);
        $lines = $this->layout->render($this->context);

        $aboveIdx = null;
        $editorIdx = null;
        foreach ($lines as $i => $line) {
            if (\str_contains($line, 'WIDGET ABOVE')) {
                $aboveIdx = $i;
            }
            if (\str_contains($line, '❯')) {
                $editorIdx = $i;
            }
        }

        self::assertNotNull($aboveIdx, 'Above-editor widget should render');
        self::assertNotNull($editorIdx, 'Editor should render');
        self::assertLessThan($editorIdx, $aboveIdx, 'Above-editor widget should be above editor');
    }

    public function testWidgetBelowEditorAppearsInCorrectPosition(): void
    {
        $belowWidget = new class implements TuiWidget {
            public function render(TuiRenderContext $context): array
            {
                return ['  WIDGET BELOW'];
            }
        };

        $this->registry->setWidget('test_below', $belowWidget, WidgetPlacement::BelowEditor);
        $lines = $this->layout->render($this->context);

        $belowIdx = null;
        $editorIdx = null;
        foreach ($lines as $i => $line) {
            if (\str_contains($line, 'WIDGET BELOW')) {
                $belowIdx = $i;
            }
            if (\str_contains($line, '❯')) {
                $editorIdx = $i;
            }
        }

        self::assertNotNull($belowIdx, 'Below-editor widget should render');
        self::assertNotNull($editorIdx, 'Editor should render');
        self::assertGreaterThan($editorIdx, $belowIdx, 'Below-editor widget should be below editor');
    }

    public function testStatusEntriesRender(): void
    {
        $this->registry->setStatus('model', 'claude-sonnet');
        $this->registry->setStatus('cwd', '/project');

        $lines = $this->layout->render($this->context);

        $foundModel = false;
        $foundCwd = false;
        foreach ($lines as $line) {
            if (\str_contains($line, 'model') && \str_contains($line, 'claude-sonnet')) {
                $foundModel = true;
            }
            if (\str_contains($line, 'cwd') && \str_contains($line, '/project')) {
                $foundCwd = true;
            }
        }

        self::assertTrue($foundModel, 'Status entry should render');
        self::assertTrue($foundCwd, 'Status entry should render');
    }

    public function testRemovingStatusEntryHidesIt(): void
    {
        $this->registry->setStatus('modelstatus', 'visible');
        $this->registry->setStatus('modelstatus', null);

        $lines = $this->layout->render($this->context);

        foreach ($lines as $line) {
            self::assertStringNotContainsString('visible', $line);
        }
    }

    public function testWorkingStatusRespectsVisibility(): void
    {
        // Working status should be visible by default
        $this->workingStatus->setVisible(true);
        $this->workingStatus->setMessage('Processing...');

        $lines = $this->layout->render($this->context);
        $foundWorking = false;
        foreach ($lines as $line) {
            if (\str_contains($line, 'Processing')) {
                $foundWorking = true;
            }
        }
        self::assertTrue($foundWorking);

        // Now hide it
        $this->workingStatus->setVisible(false);
        $lines = $this->layout->render($this->context);
        $foundHidden = false;
        foreach ($lines as $line) {
            if (\str_contains($line, 'Processing')) {
                $foundHidden = true;
            }
        }
        self::assertFalse($foundHidden, 'Working status should not render when hidden');
    }

    public function testPendingMessagesRenderWhenNotEmpty(): void
    {
        $this->pendingMessages->addMessage('Waiting for response...');

        $lines = $this->layout->render($this->context);
        $found = false;
        foreach ($lines as $line) {
            if (\str_contains($line, 'Waiting')) {
                $found = true;
            }
        }
        self::assertTrue($found);
    }

    public function testEmptyPendingMessagesDoNotRender(): void
    {
        // PendingMessagesWidget is empty by default
        $lines = $this->layout->render($this->context);
        foreach ($lines as $line) {
            self::assertStringNotContainsString('⏳', $line);
        }
    }

    private function createFooterDataProvider(): FooterDataProvider
    {
        $provider = new FooterDataProvider();
        $provider->addProvider(new class implements FooterSegmentProvider {
            /** @return list<FooterSegment> */
            public function getSegments(): array
            {
                return [
                    new FooterSegment(text: '◆ test', priority: 0),
                ];
            }
        });

        return $provider;
    }
}
