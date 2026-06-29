<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Question;

use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Question\QuestionKind;
use Ineersa\Tui\Question\QuestionOption;
use Ineersa\Tui\Question\QuestionRequest;
use Ineersa\Tui\Question\QuestionSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
        self::assertFalse($this->controller->isOpen());
    }

    #[Test]
    public function testCloseIsSafeWhenNotOpen(): void
    {
        // Must not throw when called without an open session
        $this->controller->close();
        self::assertFalse($this->controller->isOpen());
    }

    #[Test]
    public function testCloseIsSafeAfterMultipleCalls(): void
    {
        $this->controller->close();
        $this->controller->close();
        self::assertFalse($this->controller->isOpen());
    }

    // ── Build items ──

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

        self::assertCount(3, $items); // Yes, No, Type your answer
        self::assertSame('yes', $items[0]['value']);
        self::assertSame("\u{2713} Yes", $items[0]['label']);
        self::assertSame("\u{2717} No", $items[1]['label']);
        self::assertSame('__other__', $items[2]['value']);
        self::assertSame('Type your answer', $items[2]['label']);
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

        self::assertCount(2, $items); // Yes, No only
        self::assertSame('yes', $items[0]['value']);
        self::assertSame('no', $items[1]['value']);
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
        self::assertCount(3, $items);
        self::assertSame('Alpha', $items[0]['value']);
        self::assertSame('First option', $items[0]['description']);
        self::assertSame('Beta', $items[1]['value']);
        self::assertSame('Second option', $items[1]['description']);
        self::assertSame('__other__', $items[2]['value']);
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

        self::assertCount(1, $items);
        self::assertSame('Only', $items[0]['value']);
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

        self::assertCount(1, $items);
        self::assertSame('NoDesc', $items[0]['value']);
        self::assertSame('', $items[0]['description']);
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
        self::assertCount(4, $items);
        self::assertSame('Option A', $items[0]['value']);
        self::assertSame('Option A', $items[0]['label']);
        self::assertSame('Option B', $items[1]['value']);
        self::assertSame('Option B', $items[1]['label']);
        self::assertSame('Option C', $items[2]['value']);
        self::assertSame('Option C', $items[2]['label']);
        self::assertSame('__other__', $items[3]['value']);
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
        self::assertCount(3, $items);
        self::assertSame('Allow once', $items[0]['value']);
        self::assertSame('Allow once', $items[0]['label']);
        self::assertSame('Always allow', $items[1]['value']);
        self::assertSame('Always allow', $items[1]['label']);
        self::assertSame('Deny', $items[2]['value']);
        self::assertSame('Deny', $items[2]['label']);
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

        self::assertCount(2, $items);
        self::assertSame('Allow once', $items[0]['value']);
        self::assertSame('Allow once', $items[0]['label']);
        self::assertSame('Approves one-time', $items[0]['description']);
        self::assertSame('Allow always', $items[1]['value']);
        self::assertSame('Allow always', $items[1]['label']);
        self::assertSame('Persists to policy', $items[1]['description']);
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
        self::assertCount(0, $items);
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

        self::assertCount(0, $items);
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
        self::assertCount(2, $items);
        self::assertStringContainsString("\u{2713}", $items[0]['label'], 'Confirm Yes must include checkmark icon');
        self::assertStringContainsString("\u{2717}", $items[1]['label'], 'Confirm No must include cross icon');

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
        self::assertStringContainsString("\u{2713}", $styledYes, 'Styled Yes must retain checkmark icon');
        self::assertStringContainsString('Yes', $styledYes);
        self::assertNotSame("\u{2713} Yes", $styledYes, 'Styled label must differ from plain label');

        // Verify error wraps No with the marker and color
        $styledNo = $theme->color(ThemeColorEnum::Error, "\u{2717} No");
        self::assertStringContainsString("\u{2717}", $styledNo, 'Styled No must retain cross icon');
        self::assertStringContainsString('No', $styledNo);
        self::assertNotSame("\u{2717} No", $styledNo, 'Styled label must differ from plain label');
    }

    // ── Coordinator integration ──

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

        $this->coordinator->enqueue($request, function (mixed $value) use (&$calledWith): void {
            $calledWith = $value;
        });

        $this->coordinator->answer('my answer');

        self::assertSame('my answer', $calledWith);
        self::assertFalse($this->coordinator->actionRequired());
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
        self::assertTrue($this->coordinator->actionRequired());

        $this->coordinator->cancel();

        self::assertFalse($this->coordinator->actionRequired());
        self::assertNull($this->coordinator->activeStatus(), 'After cancel with empty queue, status should be null');
    }

    #[Test]
    public function testActionRequiredFalseWhenNoQuestionQueued(): void
    {
        self::assertFalse($this->coordinator->actionRequired());
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
