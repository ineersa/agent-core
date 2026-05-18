<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Editor;

use Ineersa\Tui\Editor\EditorState;
use Ineersa\Tui\Editor\PromptEditor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Widget\EditorWidget;

#[CoversClass(PromptEditor::class)]
final class PromptEditorTest extends TestCase
{
    private EditorWidget $widget;
    private PromptEditor $editor;

    protected function setUp(): void
    {
        $this->widget = new EditorWidget();
        $this->editor = new PromptEditor($this->widget);
    }

    // ─── Construction ────────────────────────────────────────────

    #[Test]
    public function defaultsToEmpty(): void
    {
        $this->assertTrue($this->editor->isEmpty());
        $this->assertSame('', $this->editor->getText());
    }

    // ─── setText / getText ──────────────────────────────────────

    #[Test]
    public function setAndGetText(): void
    {
        $this->editor->setText('hello');

        $this->assertSame('hello', $this->editor->getText());
        $this->assertFalse($this->editor->isEmpty());
    }

    #[Test]
    public function setTextMultiline(): void
    {
        $this->editor->setText("line1\nline2\nline3");

        $this->assertSame("line1\nline2\nline3", $this->editor->getText());
    }

    #[Test]
    public function setTextEmptyString(): void
    {
        $this->editor->setText('content');
        $this->editor->setText('');

        $this->assertTrue($this->editor->isEmpty());
        $this->assertSame('', $this->editor->getText());
    }

    #[Test]
    public function setTextOverwritesWidget(): void
    {
        $this->editor->setText('first');
        $this->assertSame('first', $this->widget->getText());

        $this->editor->setText('second');
        $this->assertSame('second', $this->widget->getText());
    }

    // ─── clear ──────────────────────────────────────────────────

    #[Test]
    public function clearResetsToEmpty(): void
    {
        $this->editor->setText("multi\nline");
        $this->editor->clear();

        $this->assertTrue($this->editor->isEmpty());
        $this->assertSame('', $this->editor->getText());
    }

    // ─── isEmpty ────────────────────────────────────────────────

    #[Test]
    public function isEmptyTrueByDefault(): void
    {
        $this->assertTrue($this->editor->isEmpty());
    }

    #[Test]
    public function isEmptyFalseWithContent(): void
    {
        $this->editor->setText('x');

        $this->assertFalse($this->editor->isEmpty());
    }

    #[Test]
    public function isEmptyTrueAfterClear(): void
    {
        $this->editor->setText('content');
        $this->editor->clear();

        $this->assertTrue($this->editor->isEmpty());
    }

    // ─── extract ─────────────────────────────────────────────────

    #[Test]
    public function extractReturnsTextAndClears(): void
    {
        $this->editor->setText('submit me');
        $text = $this->editor->extract();

        $this->assertSame('submit me', $text);
        $this->assertTrue($this->editor->isEmpty());
        $this->assertSame('', $this->editor->getText());
    }

    #[Test]
    public function extractOnEmptyReturnsEmptyString(): void
    {
        $text = $this->editor->extract();

        $this->assertSame('', $text);
        $this->assertTrue($this->editor->isEmpty());
    }

    #[Test]
    public function extractMultiline(): void
    {
        $this->editor->setText("line1\nline2");
        $text = $this->editor->extract();

        $this->assertSame("line1\nline2", $text);
        $this->assertSame('', $this->editor->getText());
    }

    // ─── getState ────────────────────────────────────────────────

    #[Test]
    public function getStateReturnsCorrectSnapshot(): void
    {
        $this->editor->setText('hello');
        $state = $this->editor->getState();

        $this->assertSame('hello', $state->getText());
    }

    #[Test]
    public function getStateOnEmpty(): void
    {
        $state = $this->editor->getState();

        $this->assertTrue($state->isEmpty());
        $this->assertSame([''], $state->lines);
    }

    #[Test]
    public function getStateMultiline(): void
    {
        $this->editor->setText("a\nb\nc");
        $state = $this->editor->getState();

        $this->assertSame(['a', 'b', 'c'], $state->lines);
        $this->assertSame("a\nb\nc", $state->getText());
    }

    #[Test]
    public function getStateIsImmutableViaEditorState(): void
    {
        $this->editor->setText('hello');
        $state = $this->editor->getState();

        // EditorState is readonly — this is a snapshot, not a reference
        $this->assertInstanceOf(EditorState::class, $state);
        $this->assertSame('hello', $state->getText());

        // Modifying the editor does not affect the snapshot
        $this->editor->setText('world');
        $this->assertSame('hello', $state->getText());
    }

    // ─── getWidget ───────────────────────────────────────────────

    #[Test]
    public function getWidgetReturnsSameInstance(): void
    {
        $this->assertSame($this->widget, $this->editor->getWidget());
    }

    #[Test]
    public function widgetTextReadsBackAfterSet(): void
    {
        $this->editor->setText('via prompt editor');

        // Same text visible through both PromptEditor and raw widget
        $this->assertSame('via prompt editor', $this->editor->getText());
        $this->assertSame('via prompt editor', $this->widget->getText());
    }
}
