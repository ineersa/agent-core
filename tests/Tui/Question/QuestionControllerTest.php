<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Question;

use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Question\QuestionKind;
use Ineersa\Tui\Question\QuestionOption;
use Ineersa\Tui\Question\QuestionRequest;
use Ineersa\Tui\Question\QuestionSource;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\EditorWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Tests for the QuestionController.
 *
 * Does not require a running TUI or Symfony widget tree — the unit-testable
 * surface is the buildItems method and isOpen/close lifecycle compatibility.
 * Full TUI interaction tests require Symfony Tui infrastructure.
 */
class QuestionControllerTest extends TestCase
{
    private QuestionCoordinator $coordinator;
    private QuestionController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coordinator = new QuestionCoordinator();
        $this->controller = new QuestionController($this->coordinator);
    }

    // ── Lifecycle ──

    #[Test]
    public function testIsOpenDefaultsToFalse(): void
    {
        $this->assertFalse($this->controller->isOpen());
    }

    #[Test]
    public function testCloseIsSafeWhenNotOpen(): void
    {
        // Must not throw when called without an open session
        $this->controller->close();
        $this->assertFalse($this->controller->isOpen());
    }

    #[Test]
    public function testCloseIsSafeAfterMultipleCalls(): void
    {
        $this->controller->close();
        $this->controller->close();
        $this->assertFalse($this->controller->isOpen());
    }

    // ── Focus restoration (__other__ escape hatch) ──

    #[Test]
    public function testEditorWidgetIsAccessibleWhenScreenInjected(): void
    {
        // The __other__ escape hatch calls screen->setFocus(screen->editorWidget())
        // after close(). This test verifies that editorWidget() is accessible
        // through a ChatScreen with a PromptEditor injected.
        //
        // Full SelectListWidget event dispatch requires Symfony Tui
        // infrastructure (tui->mount(), insertOverlayBeforeEditor) and is
        // not unit-testable without it. This test at minimum proves the
        // editorWidget() call path does not crash.
        $chatRef = new \ReflectionClass(ChatScreen::class);
        /** @var ChatScreen $screen */
        $screen = $chatRef->newInstanceWithoutConstructor();

        $promptEditor = new PromptEditor();
        $promptEditorProp = $chatRef->getProperty('promptEditor');
        $promptEditorProp->setValue($screen, $promptEditor);

        // Inject a theme so styleConfirmItems can access it
        $palette = new ThemePalette(
            name: 'test',
            colors: [
                ThemeColorEnum::Success->value => 'green',
                ThemeColorEnum::Error->value => 'red',
            ],
        );
        $theme = new DefaultTheme($palette);
        $themeProp = $chatRef->getProperty('theme');
        $themeProp->setValue($screen, $theme);

        // Inject the screen into the controller
        $ctrlRef = new \ReflectionClass($this->controller);
        $screenProp = $ctrlRef->getProperty('screen');
        $screenProp->setValue($this->controller, $screen);

        // Verify editorWidget() path (called by __other__ handler)
        $editorWidget = $screen->editorWidget();
        $this->assertInstanceOf(EditorWidget::class, $editorWidget);

        // Close lifecycle is tested by other tests (testCloseIsSafeWhenNotOpen);
        // calling close() here requires a full set of ChatScreen dependencies
        // (registry, tui, footerDataProvider, etc.) that are beyond the scope
        // of this unit-level editorWidget() accessibility proof.
    }

    // ── Build items ──

    #[Test]
    public function testTextKindOverlayIncludesWrappedPromptWithoutEllipsisTruncation(): void
    {
        $longPrompt = str_repeat('Describe your workflow. ', 20);
        $request = new QuestionRequest(
            requestId: 'text-banner',
            source: QuestionSource::AgentCore,
            kind: QuestionKind::Text,
            prompt: $longPrompt,
        );

        $container = new ContainerWidget();
        $ctrlRef = new \ReflectionClass($this->controller);

        $chatRef = new \ReflectionClass(ChatScreen::class);
        /** @var ChatScreen $screen */
        $screen = $chatRef->newInstanceWithoutConstructor();
        $palette = new ThemePalette(
            name: 'test',
            colors: [
                ThemeColorEnum::Accent->value => 'cyan',
                ThemeColorEnum::Muted->value => 'gray',
            ],
        );
        $themeProp = $chatRef->getProperty('theme');
        $themeProp->setValue($screen, new DefaultTheme($palette));
        $screenProp = $ctrlRef->getProperty('screen');
        $screenProp->setValue($this->controller, $screen);

        $containerProp = $ctrlRef->getProperty('container');
        $containerProp->setValue($this->controller, $container);

        $addHeader = new \ReflectionMethod($this->controller, 'addHeader');
        $addHeader->invoke($this->controller, $request);

        $addBanner = new \ReflectionMethod($this->controller, 'addTextBanner');
        $addBanner->invoke($this->controller, $request);

        $childrenProp = new \ReflectionProperty(ContainerWidget::class, 'children');
        $children = $childrenProp->getValue($container);

        $this->assertCount(3, $children, 'Text overlay should be header + prompt + hint');
        $this->assertInstanceOf(TextWidget::class, $children[0]);
        $this->assertInstanceOf(MarkdownWidget::class, $children[1]);
        $this->assertInstanceOf(TextWidget::class, $children[2]);

        $headerLines = $children[0]->render(new \Symfony\Component\Tui\Render\RenderContext(80, 24));
        $promptLines = $children[1]->render(new \Symfony\Component\Tui\Render\RenderContext(80, 24));
        $hintLines = $children[2]->render(new \Symfony\Component\Tui\Render\RenderContext(80, 24));
        $header = implode('', $headerLines);
        $prompt = implode("\n", $promptLines);
        $hint = implode('', $hintLines);

        $this->assertStringContainsString('Human input required', $header);
        $this->assertStringContainsString('Describe your workflow.', $prompt);
        $this->assertStringNotContainsString('…', $prompt, 'Prompt should wrap, not ellipsis-truncate');
        $this->assertStringContainsString('[type answer and press Enter]', preg_replace('/\x1b\[[0-9;]*m/', '', $hint));
    }

    /**
     * Build items for a Confirm question.
     */
    #[Test]
    public function testConfirmItemsDefault(): void
    {
        $request = new QuestionRequest(
            requestId: 'confirm-1',
            source: QuestionSource::Tui,
            kind: QuestionKind::Confirm,
            prompt: 'Are you sure?',
        );

        $items = $this->invokeBuildItems($request);

        // Confirm is binary (Yes/No) — the escape hatch only renders for
        // Choice (free-form text would be silently coerced to boolean).
        $this->assertCount(2, $items);
        $this->assertSame('yes', $items[0]['value']);
        $this->assertSame("\u{2713} Yes", $items[0]['label']);
        $this->assertSame("\u{2717} No", $items[1]['label']);
    }

    #[Test]
    public function testConfirmItemsWithoutOther(): void
    {
        $request = new QuestionRequest(
            requestId: 'confirm-2',
            source: QuestionSource::Tui,
            kind: QuestionKind::Confirm,
            prompt: 'Proceed?',
            allowOther: false,
        );

        $items = $this->invokeBuildItems($request);

        $this->assertCount(2, $items); // Yes, No only
        $this->assertSame('yes', $items[0]['value']);
        $this->assertSame('no', $items[1]['value']);
    }

    #[Test]
    public function testChoiceItemsIncludesOptionsWithDescriptions(): void
    {
        $request = new QuestionRequest(
            requestId: 'choice-1',
            source: QuestionSource::Tui,
            kind: QuestionKind::Choice,
            prompt: 'Pick one:',
            choices: [
                new QuestionOption(label: 'Alpha', description: 'First option'),
                new QuestionOption(label: 'Beta', description: 'Second option'),
            ],
        );

        $items = $this->invokeBuildItems($request);

        // 2 options + "Type your answer"
        $this->assertCount(3, $items);
        $this->assertSame('Alpha', $items[0]['value']);
        $this->assertSame('First option', $items[0]['description']);
        $this->assertSame('Beta', $items[1]['value']);
        $this->assertSame('Second option', $items[1]['description']);
        $this->assertSame('__other__', $items[2]['value']);
    }

    #[Test]
    public function testChoiceItemsWithoutOther(): void
    {
        $request = new QuestionRequest(
            requestId: 'choice-2',
            source: QuestionSource::Tui,
            kind: QuestionKind::Choice,
            prompt: 'Pick:',
            choices: [
                new QuestionOption(label: 'Only'),
            ],
            allowOther: false,
        );

        $items = $this->invokeBuildItems($request);

        $this->assertCount(1, $items);
        $this->assertSame('Only', $items[0]['value']);
    }

    #[Test]
    public function testChoiceItemsLabelOnlyNoDescription(): void
    {
        $request = new QuestionRequest(
            requestId: 'choice-3',
            source: QuestionSource::Tui,
            kind: QuestionKind::Choice,
            prompt: 'Pick:',
            choices: [
                new QuestionOption(label: 'NoDesc'),
            ],
            allowOther: false,
        );

        $items = $this->invokeBuildItems($request);

        $this->assertCount(1, $items);
        $this->assertSame('NoDesc', $items[0]['value']);
        $this->assertSame('', $items[0]['description']);
    }

    #[Test]
    public function testChoiceItemsFromChoices(): void
    {
        $request = new QuestionRequest(
            requestId: 'choice-1',
            source: QuestionSource::Tui,
            kind: QuestionKind::Choice,
            prompt: 'Choose an option:',
            choices: [
                new QuestionOption('Option A'),
                new QuestionOption('Option B'),
                new QuestionOption('Option C'),
            ],
        );

        $items = $this->invokeBuildItems($request);

        // Choice items = choices + 'Type your answer' (allowOther defaults to true)
        $this->assertCount(4, $items);
        $this->assertSame('Option A', $items[0]['value']);
        $this->assertSame('Option A', $items[0]['label']);
        $this->assertSame('Option B', $items[1]['value']);
        $this->assertSame('Option B', $items[1]['label']);
        $this->assertSame('Option C', $items[2]['value']);
        $this->assertSame('Option C', $items[2]['label']);
        $this->assertSame('__other__', $items[3]['value']);
    }

    #[Test]
    public function testChoiceItemsWithSafeGuardVocabulary(): void
    {
        $request = new QuestionRequest(
            requestId: 'choice-sg',
            source: QuestionSource::Tui,
            kind: QuestionKind::Choice,
            prompt: 'Pick one:',
            choices: [
                new QuestionOption('Allow once'),
                new QuestionOption('Always allow'),
                new QuestionOption('Deny'),
            ],
            allowOther: false,
        );

        $items = $this->invokeBuildItems($request);

        // Choice without allowOther = choices only
        $this->assertCount(3, $items);
        $this->assertSame('Allow once', $items[0]['value']);
        $this->assertSame('Allow once', $items[0]['label']);
        $this->assertSame('Always allow', $items[1]['value']);
        $this->assertSame('Always allow', $items[1]['label']);
        $this->assertSame('Deny', $items[2]['value']);
        $this->assertSame('Deny', $items[2]['label']);
    }

    #[Test]
    public function testChoiceItemsWithDescription(): void
    {
        $request = new QuestionRequest(
            requestId: 'choice-3',
            source: QuestionSource::AgentCore,
            kind: QuestionKind::Choice,
            prompt: 'Allow destructive command?',
            choices: [
                new QuestionOption('Allow once', 'Approves one-time'),
                new QuestionOption('Allow always', 'Persists to policy'),
            ],
            allowOther: false,
        );

        $items = $this->invokeBuildItems($request);

        $this->assertCount(2, $items);
        $this->assertSame('Allow once', $items[0]['value']);
        $this->assertSame('Allow once', $items[0]['label']);
        $this->assertSame('Approves one-time', $items[0]['description']);
        $this->assertSame('Allow always', $items[1]['value']);
        $this->assertSame('Allow always', $items[1]['label']);
        $this->assertSame('Persists to policy', $items[1]['description']);
    }

    #[Test]
    public function testChoiceItemsEmptyChoicesFallsBack(): void
    {
        // Empty choices list with allowOther=false → fall back to generic
        $request = new QuestionRequest(
            requestId: 'choice-empty',
            source: QuestionSource::Tui,
            kind: QuestionKind::Choice,
            prompt: 'Pick?',
            choices: [],
            allowOther: false,
        );

        $items = $this->invokeBuildItems($request);

        // No choices, no Type your answer (allowOther=false) → empty
        $this->assertCount(0, $items);
    }

    #[Test]
    public function testTextKindDoesNotUseListItems(): void
    {
        $request = new QuestionRequest(
            requestId: 'text-1',
            source: QuestionSource::Tui,
            kind: QuestionKind::Text,
            prompt: 'Enter your name:',
        );

        // Text kind never builds list items; the controller uses a TextWidget banner
        // instead of SelectListWidget. This test verifies the buildItems path
        // returns empty for Text kind.
        $items = $this->invokeBuildItems($request);

        $this->assertCount(0, $items);
    }

    // ── Confirm styling ──

    #[Test]
    public function testConfirmItemsIconsAndThemeColoring(): void
    {
        // Verify buildItems includes icon markers for confirm labels.
        $request = new QuestionRequest(
            requestId: 'confirm-icons',
            source: QuestionSource::Tui,
            kind: QuestionKind::Confirm,
            prompt: 'Test?',
            allowOther: false,
        );

        $items = $this->invokeBuildItems($request);
        $this->assertCount(2, $items);
        $this->assertStringContainsString("\u{2713}", $items[0]['label'], 'Confirm Yes must include checkmark icon');
        $this->assertStringContainsString("\u{2717}", $items[1]['label'], 'Confirm No must include cross icon');

        // Create a real DefaultTheme with a test palette to verify the
        // theme color integration works for confirm items.
        $palette = new ThemePalette(
            name: 'test',
            colors: [
                ThemeColorEnum::Success->value => 'green',
                ThemeColorEnum::Error->value => 'red',
            ],
        );
        $theme = new DefaultTheme($palette);

        // Verify success wraps Yes with the marker and color
        $styledYes = $theme->color(ThemeColorEnum::Success, "\u{2713} Yes");
        $this->assertStringContainsString("\u{2713}", $styledYes, 'Styled Yes must retain checkmark icon');
        $this->assertStringContainsString('Yes', $styledYes);
        $this->assertNotSame("\u{2713} Yes", $styledYes, 'Styled label must differ from plain label');

        // Verify error wraps No with the marker and color
        $styledNo = $theme->color(ThemeColorEnum::Error, "\u{2717} No");
        $this->assertStringContainsString("\u{2717}", $styledNo, 'Styled No must retain cross icon');
        $this->assertStringContainsString('No', $styledNo);
        $this->assertNotSame("\u{2717} No", $styledNo, 'Styled label must differ from plain label');
    }

    // ── Coordinator integration ──

    #[Test]
    public function testStyleConfirmItemsOnlyAppliesToConfirmKind(): void
    {
        $palette = new ThemePalette(
            name: 'test',
            colors: [
                ThemeColorEnum::Success->value => 'green',
                ThemeColorEnum::Error->value => 'red',
            ],
        );
        $theme = new DefaultTheme($palette);

        // Create a ChatScreen without constructor and inject the test theme
        $chatRef = new \ReflectionClass(ChatScreen::class);
        /** @var ChatScreen $screen */
        $screen = $chatRef->newInstanceWithoutConstructor();
        $themeProp = $chatRef->getProperty('theme');
        $themeProp->setValue($screen, $theme);

        // Inject the screen into the controller
        $ctrlRef = new \ReflectionClass($this->controller);
        $screenProp = $ctrlRef->getProperty('screen');
        $screenProp->setValue($this->controller, $screen);

        $invokeStyle = new \ReflectionMethod($this->controller, 'styleConfirmItems');

        // Thesis 1: Confirm kind items get styled
        // Even with icon markers already in labels, actual styling via
        // theme->color() wraps the label text with ANSI color codes.
        $confirmItems = [
            ['value' => 'yes', 'label' => "\u{2713} Yes"],
            ['value' => 'no', 'label' => "\u{2717} No"],
        ];
        $styled = $invokeStyle->invoke($this->controller, $confirmItems, QuestionKind::Confirm);
        $this->assertNotSame("\u{2713} Yes", $styled[0]['label'], 'Confirm Yes must be styled with Success color');
        $this->assertNotSame("\u{2717} No", $styled[1]['label'], 'Confirm No must be styled with Error color');

        // Thesis 2: Choice kind items with the same values are NOT styled
        // This proves the kind guard prevents accidental coloring of
        // Choice options whose labels happen to be 'yes' or 'no'.
        $choiceItems = [
            ['value' => 'yes', 'label' => 'yes'],
            ['value' => 'no', 'label' => 'no'],
            ['value' => 'other', 'label' => 'other'],
        ];
        $unstyled = $invokeStyle->invoke($this->controller, $choiceItems, QuestionKind::Choice);
        $this->assertSame($choiceItems, $unstyled, 'Choice items must be returned unchanged even when values are yes/no');
    }

    #[Test]
    public function testAnswerInvokesCoordinatorCallback(): void
    {
        $calledWith = null;
        $request = new QuestionRequest(
            requestId: 'cb-test',
            source: QuestionSource::Tui,
            kind: QuestionKind::Text,
            prompt: 'Test:',
        );

        $this->coordinator->enqueue($request, static function (mixed $value) use (&$calledWith): void {
            $calledWith = $value;
        });

        $this->coordinator->answer('my answer');

        $this->assertSame('my answer', $calledWith);
        $this->assertFalse($this->coordinator->actionRequired());
    }

    #[Test]
    public function testCancelClearsActiveRequest(): void
    {
        $request = new QuestionRequest(
            requestId: 'cancel-test',
            source: QuestionSource::Tui,
            kind: QuestionKind::Confirm,
            prompt: 'Cancel test?',
        );

        $this->coordinator->enqueue($request);
        $this->assertTrue($this->coordinator->actionRequired());

        $this->coordinator->cancel();

        $this->assertFalse($this->coordinator->actionRequired());
        $this->assertNull($this->coordinator->activeStatus(), 'After cancel with empty queue, status should be null');
    }

    #[Test]
    public function testActionRequiredFalseWhenNoQuestionQueued(): void
    {
        $this->assertFalse($this->coordinator->actionRequired());
    }

    // ── AwaitingFreeForm lifecycle (__other__ escape hatch) ──

    #[Test]
    public function testAwaitingFreeFormLifecycleAfterDismiss(): void
    {
        // The awaitingFreeForm flag protects TickPollListener's per-tick
        // re-open guard: after __other__ dismiss, actionRequired() is
        // still true but isAwaitingFreeForm() is also true, so the guard
        // does NOT re-open the overlay. After coordinator->answer() (which
        // calls close()), the flag is reset to false.
        //
        // The dismissToEditor() path cannot call open() first because
        // QuestionController::open() requires a fully mounted ChatScreen
        // (Symfony Tui tree) to call insertOverlayBeforeEditor().
        // Instead we set isOpen=true via reflection to prove the flag
        // lifecycle contract.

        $ctrlRef = new \ReflectionClass($this->controller);

        // Thesis 1: Default state
        $this->assertFalse($this->controller->isAwaitingFreeForm());
        $this->assertFalse($this->controller->isOpen());

        // Set isOpen=true to simulate an open overlay
        $isOpenProp = $ctrlRef->getProperty('isOpen');
        $isOpenProp->setValue($this->controller, true);
        $this->assertTrue($this->controller->isOpen());

        // Invoke dismissToEditor via reflection (the __other__ escape hatch)
        $dismiss = new \ReflectionMethod($this->controller, 'dismissToEditor');
        $dismiss->invoke($this->controller);

        // Thesis 2: After dismiss, isAwaitingFreeForm=true, isOpen=false
        $this->assertTrue($this->controller->isAwaitingFreeForm(), 'After __other__ dismiss, awaitingFreeForm must be true');
        $this->assertFalse($this->controller->isOpen(), 'After __other__ dismiss, overlay must be closed');

        // Simulate what happens when SubmitListener answers: close() is called
        $this->controller->close();

        // Thesis 3: After close(), isAwaitingFreeForm=false (reset by close)
        $this->assertFalse($this->controller->isAwaitingFreeForm(), 'After close(), awaitingFreeForm must be reset to false');
    }

    #[Test]
    public function testCloseResetsAwaitingFreeFormFlagIdempotently(): void
    {
        // Regression: close() resets awaitingFreeForm to false even when
        // the overlay is already closed (no-op safety net). This validates
        // that the reset is idempotent across repeated close() calls,
        // so that close() from any code path (answer, reject, cancel,
        // self-heal, reset) leaves the flag clean regardless of state.
        //
        // Both open() and close() defensively assign $this->awaitingFreeForm
        // = false. This test proves close()'s reset contract (the assignment
        // is shared code); open() cannot be called at this layer because it
        // requires a mounted ChatScreen (Symfony Tui tree).

        $ctrlRef = new \ReflectionClass($this->controller);

        // Set awaitingFreeForm=true via reflection
        $awaitProp = $ctrlRef->getProperty('awaitingFreeForm');
        $awaitProp->setValue($this->controller, true);
        $this->assertTrue($this->controller->isAwaitingFreeForm());

        // close() also resets awaitingFreeForm. Verify that too.
        // Then manually set it back to test close() reset independently.
        $this->controller->close();
        $this->assertFalse($this->controller->isAwaitingFreeForm(), 'close() must reset awaitingFreeForm');

        // Set awaitingFreeForm=true again and verify reset via the isOpen
        // property pathway — the same reset happens in close() and open().
        $awaitProp->setValue($this->controller, true);
        $this->assertTrue($this->controller->isAwaitingFreeForm());

        // Verify close() resets it again
        $this->controller->close();
        $this->assertFalse($this->controller->isAwaitingFreeForm(), 'close() resets awaitingFreeForm on second call');
    }

    // ── Restore from free-form (Fix B: ESC returns to options) ──

    #[Test]
    public function testRestoreFromFreeFormResetsFlag(): void
    {
        // restoreFromFreeForm() must always reset awaitingFreeForm to false,
        // even when it cannot re-open the overlay (no screen or no active
        // request). CancelListener's ESC guard depends on this contract —
        // it calls restoreFromFreeForm() when awaitingFreeForm is true.

        $ctrlRef = new \ReflectionClass($this->controller);
        $awaitProp = $ctrlRef->getProperty('awaitingFreeForm');
        $awaitProp->setValue($this->controller, true);
        $this->assertTrue($this->controller->isAwaitingFreeForm());

        $this->controller->restoreFromFreeForm();

        $this->assertFalse($this->controller->isAwaitingFreeForm(), 'restoreFromFreeForm must reset awaitingFreeForm flag');
    }

    #[Test]
    public function testRestoreFromFreeFormNoopWhenNotAwaiting(): void
    {
        // restoreFromFreeForm() is a safe no-op when not awaiting free-form.
        // The flag must remain false and no exception thrown.

        $this->assertFalse($this->controller->isAwaitingFreeForm());

        // Should not throw
        $this->controller->restoreFromFreeForm();

        $this->assertFalse($this->controller->isAwaitingFreeForm());
    }

    // ── Helpers ──

    /**
     * Invoke the private buildItems method via reflection.
     *
     * @return list<array{value: string, label: string, description?: string}>
     */
    private function invokeBuildItems(QuestionRequest $request): array
    {
        $ref = new \ReflectionMethod($this->controller, 'buildItems');

        /** @var list<array{value: string, label: string, description?: string}> $result */
        $result = $ref->invoke($this->controller, $request);

        return $result;
    }
}
