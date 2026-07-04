<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\PromptHistoryListener;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Tui;

#[CoversClass(PromptHistoryListener::class)]
final class PromptHistoryListenerTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;
    private PromptEditor $editor;
    private TuiSessionState $state;
    private ChatScreen $screen;

    protected function setUp(): void
    {
        $this->editor = new PromptEditor();
        $this->state = new TuiSessionState('test-session');

        $theme = new DefaultTheme(new ThemePalette('default'));
        $this->screen = new ChatScreen($theme, 'test-session', $this->editor);
    }

    // ─── Up on empty editor recalls latest user prompt ────────

    #[Test]
    public function upOnEmptyEditorRecallsLatestUserPrompt(): void
    {
        $this->state->transcript = [
            self::userBlock('first prompt', 1),
            self::assistantBlock('reply to first', 2),
            self::userBlock('second prompt', 3),
        ];

        $this->registerListener();

        // Send Up arrow (cursor_up) when editor is empty
        $this->editor->getWidget()->handleInput("\x1b[A");

        $this->assertSame('second prompt', $this->editor->getText());
        $this->assertFalse($this->editor->isEmpty());
    }

    #[Test]
    public function repeatedUpWalksBackwardThroughHistory(): void
    {
        $this->state->transcript = [
            self::userBlock('oldest prompt', 1),
            self::assistantBlock('reply', 2),
            self::userBlock('middle prompt', 3),
            self::userBlock('newest prompt', 4),
        ];

        $this->registerListener();

        // First Up — newest
        $this->editor->getWidget()->handleInput("\x1b[A");
        $this->assertSame('newest prompt', $this->editor->getText());

        // Second Up — middle
        $this->editor->getWidget()->handleInput("\x1b[A");
        $this->assertSame('middle prompt', $this->editor->getText());

        // Third Up — oldest
        $this->editor->getWidget()->handleInput("\x1b[A");
        $this->assertSame('oldest prompt', $this->editor->getText());

        // Fourth Up — nothing older, stays at oldest
        $this->editor->getWidget()->handleInput("\x1b[A");
        $this->assertSame('oldest prompt', $this->editor->getText());
    }

    // ─── Down walks forward / clears at end ───────────────────

    #[Test]
    public function downAfterUpWalksForward(): void
    {
        $this->state->transcript = [
            self::userBlock('prompt 1', 1),
            self::userBlock('prompt 2', 2),
        ];

        $this->registerListener();

        // Up twice to get to oldest
        $this->editor->getWidget()->handleInput("\x1b[A"); // prompt 2
        $this->editor->getWidget()->handleInput("\x1b[A"); // prompt 1

        // Down to go forward
        $this->editor->getWidget()->handleInput("\x1b[B"); // prompt 2

        $this->assertSame('prompt 2', $this->editor->getText());
    }

    #[Test]
    public function downPastNewestClearsEditor(): void
    {
        $this->state->transcript = [
            self::userBlock('only prompt', 1),
        ];

        $this->registerListener();

        // Up to recall
        $this->editor->getWidget()->handleInput("\x1b[A");
        $this->assertSame('only prompt', $this->editor->getText());

        // Down past newest — clears
        $this->editor->getWidget()->handleInput("\x1b[B");
        $this->assertTrue($this->editor->isEmpty());
        $this->assertSame('', $this->editor->getText());
    }

    #[Test]
    public function downOnEmptyEditorWithoutHistoryDoesNothing(): void
    {
        $this->state->transcript = [];

        $this->registerListener();

        // Down on empty editor with no history — falls through to editor
        $this->editor->getWidget()->handleInput("\x1b[B");

        $this->assertTrue($this->editor->isEmpty());
        $this->assertSame('', $this->editor->getText());
    }

    // ─── Typing normal input exits history mode ───────────────

    #[Test]
    public function typingNormalInputExitsHistoryNavigation(): void
    {
        $this->state->transcript = [
            self::userBlock('previous prompt', 1),
        ];

        $this->registerListener();

        // Up to recall
        $this->editor->getWidget()->handleInput("\x1b[A");
        $this->assertSame('previous prompt', $this->editor->getText());

        // Type a character while the recalled history text is still in the
        // editor.  The onInput callback sees a non-Up/Down key and exits
        // navigation.  Then the editor widget handles the key normally,
        // inserting it.  Editor now has "previous promptx".
        $this->editor->getWidget()->handleInput('x');
        $this->assertStringContainsString('previous prompt', $this->editor->getText());

        // Now press Up again while editor is NOT empty — should NOT
        // recall history (navigation was exited).
        $beforeUp = $this->editor->getText();
        $this->editor->getWidget()->handleInput("\x1b[A");
        // Text should be unchanged because Up was handled as cursor
        // movement within the editor, not as history navigation.
        // In a real TUI, the cursor would move up a line (within the editor).
        // In this test without a real render context, the text stays the same.
        $this->assertSame($beforeUp, $this->editor->getText());
    }

    // ─── Up does not intercept when editor has content ────────

    #[Test]
    public function upDoesNotInterceptWhenEditorIsNotEmpty(): void
    {
        $this->state->transcript = [
            self::userBlock('old prompt', 1),
        ];

        $this->registerListener();

        // Type some text in the editor
        $this->editor->setText('hello');

        // Press Up — should NOT recall history (editor has content)
        $this->editor->getWidget()->handleInput("\x1b[A");

        // Text should be unchanged — the up was handled by the editor's
        // cursor movement (which in a test without real TUI may not visibly
        // change text, but it shouldn't have been consumed by history)
        $this->assertSame('hello', $this->editor->getText());
    }

    // ─── No user blocks means no history ──────────────────────

    #[Test]
    public function upDoesNothingWhenNoUserBlocks(): void
    {
        $this->state->transcript = [
            self::systemBlock('Welcome', 1),
            self::assistantBlock('reply', 2),
        ];

        $this->registerListener();

        $this->editor->getWidget()->handleInput("\x1b[A");

        $this->assertTrue($this->editor->isEmpty());
    }

    // ─── Down variant escape sequence ─────────────────────────

    #[Test]
    public function downWithAlternateEscapeSequenceAlsoWorks(): void
    {
        $this->state->transcript = [
            self::userBlock('a prompt', 1),
        ];

        $this->registerListener();

        $this->editor->getWidget()->handleInput("\x1b[A"); // recall
        $this->assertSame('a prompt', $this->editor->getText());

        // Alternate down sequence \x1bOB
        $this->editor->getWidget()->handleInput("\x1bOB");
        $this->assertTrue($this->editor->isEmpty());
    }

    // ─── Up with alternate escape sequence ────────────────────

    #[Test]
    public function upWithAlternateEscapeSequenceAlsoWorks(): void
    {
        $this->state->transcript = [
            self::userBlock('my prompt', 1),
        ];

        $this->registerListener();

        // Alternate up sequence \x1bOA
        $this->editor->getWidget()->handleInput("\x1bOA");

        $this->assertSame('my prompt', $this->editor->getText());
    }

    // ─── Up at oldest multiline prompt is consumed as no-op ──

    #[Test]
    public function upAtOldestMultilinePromptIsConsumedAsNoOp(): void
    {
        $multiline = "line 1\nline 2\nline 3";
        $this->state->transcript = [
            self::userBlock($multiline, 1),
        ];

        $this->registerListener();

        // Recall the only (and oldest) prompt
        $this->editor->getWidget()->handleInput("\x1b[A");
        $this->assertSame($multiline, $this->editor->getText());

        // Press Up again — at oldest, should be consumed as no-op
        // (not let through to editor cursor movement within multiline text)
        $this->editor->getWidget()->handleInput("\x1b[A");
        $this->assertSame($multiline, $this->editor->getText());
    }

    // ─── Multiline prompts are preserved ──────────────────────

    #[Test]
    public function multilinePromptsAreRecalledCorrectly(): void
    {
        $multiline = "line 1\nline 2\nline 3";
        $this->state->transcript = [
            self::userBlock($multiline, 1),
        ];

        $this->registerListener();

        $this->editor->getWidget()->handleInput("\x1b[A");

        $this->assertSame($multiline, $this->editor->getText());
    }

    // ─── Resume: history seeded from transcript blocks ─────────

    #[Test]
    public function historySeededFromTranscriptBlocksOnResume(): void
    {
        $this->state->transcript = [
            self::systemBlock('Welcome back', 1),
            self::userBlock('prompt from before', 2),
            self::assistantBlock('old reply', 3),
            self::userBlock('another old prompt', 4),
        ];

        $this->registerListener();

        // First Up — newest user message
        $this->editor->getWidget()->handleInput("\x1b[A");
        $this->assertSame('another old prompt', $this->editor->getText());

        // Second Up — older user message
        $this->editor->getWidget()->handleInput("\x1b[A");
        $this->assertSame('prompt from before', $this->editor->getText());
    }

    // ─── History survival across transcript updates ───────────

    #[Test]
    public function historyNavigatorWorksWithUpdatedTranscript(): void
    {
        // Start with one user message
        $this->state->transcript = [
            self::userBlock('initial prompt', 1),
        ];

        $this->registerListener();

        // Recall it
        $this->editor->getWidget()->handleInput("\x1b[A");
        $this->assertSame('initial prompt', $this->editor->getText());

        // Exit navigation
        $this->editor->clear();
        $this->editor->getWidget()->handleInput('x');

        // Add a new user message to transcript (simulates new submission)
        $this->state->transcript[] = self::userBlock('new prompt', 2);

        // Up should now start from the newest (new prompt)
        // But first, the editor needs to be empty for Up to intercept.
        $this->editor->clear();
        $this->editor->getWidget()->handleInput("\x1b[A");
        $this->assertSame('new prompt', $this->editor->getText());
    }

    // ─── Helpers ────────────────────────────────────────────────

    private function registerListener(): void
    {
        $tui = new Tui();

        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withState($this->state)
            ->withScreen($this->screen)
            ->build();

        $listener = new PromptHistoryListener();
        $listener->register($context);
    }

    private static function userBlock(string $text, int $seq = 1): TranscriptBlock
    {
        return new TranscriptBlock(
            id: 'user-'.$seq,
            kind: TranscriptBlockKindEnum::UserMessage,
            runId: 'test-run',
            seq: $seq,
            text: $text,
        );
    }

    private static function assistantBlock(string $text, int $seq = 1): TranscriptBlock
    {
        return new TranscriptBlock(
            id: 'asst-'.$seq,
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: 'test-run',
            seq: $seq,
            text: $text,
        );
    }

    private static function systemBlock(string $text, int $seq = 1): TranscriptBlock
    {
        return new TranscriptBlock(
            id: 'sys-'.$seq,
            kind: TranscriptBlockKindEnum::System,
            runId: 'test-run',
            seq: $seq,
            text: $text,
        );
    }
}
