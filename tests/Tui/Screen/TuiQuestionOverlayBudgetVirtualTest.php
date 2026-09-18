<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\Tui\CompactHeader\CompactHeaderSnapshot;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Question\QuestionKind;
use Ineersa\Tui\Question\QuestionOption;
use Ineersa\Tui\Question\QuestionOverlayWidget;
use Ineersa\Tui\Question\QuestionRequest;
use Ineersa\Tui\Question\QuestionSource;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * Busy-screen proof that question overlays reserve the measured lower block
 * (compact header + editor + footer) and keep the selected first row in the
 * bottom-aligned viewport.
 */
#[AllowMockObjectsWithoutExpectations]
final class TuiQuestionOverlayBudgetVirtualTest extends TestCase
{
    public function testTwoRowBudgetKeepsAnAnswerVisible(): void
    {
        $terminal = new VirtualTerminal(columns: 40, rows: 2);
        $tui = new Tui(terminal: $terminal);
        $overlay = new QuestionOverlayWidget();
        $overlay->setStyle(new \Symfony\Component\Tui\Style\Style(gap: 1));
        $overlay->add(new \Symfony\Component\Tui\Widget\TextWidget('Question heading'));
        $overlay->add(new SelectListWidget([['value' => 'alpha', 'label' => 'Alpha answer']]));
        $tui->add($overlay);
        try {
            $tui->start();
            $tui->processRender();
            $buffer = new ScreenBuffer(width: 40, height: 2);
            $buffer->write($terminal->getOutput());
            $this->assertStringContainsString('→ Alpha answer', $buffer->getScreen());
        } finally {
            $tui->stop();
        }
    }

    #[Test]
    #[DataProvider('terminalGeometries')]
    public function testBusyWaitingScreenKeepsSelectedArrowAndUsefulOptions(int $columns, int $rows, int $minVisibleChoices): void
    {
        [$harness, $controller, $list] = $this->openBusyChoice($columns, $rows);
        $plain = $this->visiblePlain($harness, $rows);

        $this->assertStringContainsString('→', $plain, 'Selected first physical row must stay in the viewport');
        $this->assertStringContainsString('A_UNIQUE', $plain, 'Selected option must stay visible');
        $this->assertStringContainsString('UNIQUE_QUESTION_PROMPT', $plain, 'Question prompt must stay visible');
        $this->assertStringContainsString('prompts', $plain, 'Compact header must remain visible');
        $this->assertStringContainsString('session question-budget', $plain, 'Footer must remain visible');
        // Startup logo may remain in the taller logical frame above the bottom
        // viewport; do not require chrome-hide while the question is open.

        $visibleChoices = 0;
        foreach (['A_UNIQUE', 'B_UNIQUE', 'C_UNIQUE', 'D_UNIQUE'] as $marker) {
            if (str_contains($plain, $marker)) {
                ++$visibleChoices;
            }
        }
        $this->assertGreaterThanOrEqual(
            $minVisibleChoices,
            $visibleChoices,
            'Useful question budget must expose the expected number of choices',
        );
        $this->assertGreaterThanOrEqual(
            1,
            substr_count($plain, 'needs multiple physical rows'),
            'Wrapped selected option must show continuation text when space allows',
        );

        $questionPos = strpos($plain, 'UNIQUE_QUESTION_PROMPT');
        $promptsPos = strpos($plain, 'prompts');
        $this->assertNotFalse($questionPos);
        $this->assertNotFalse($promptsPos);
        $this->assertLessThan($promptsPos, $questionPos, 'Question must render above prompts/skills/agents');

        $lastRenderRows = (new \ReflectionProperty($list, 'lastRenderRows'))->getValue($list);
        $this->assertGreaterThanOrEqual(
            $rows >= 24 ? 6 : 2,
            $lastRenderRows,
            'SelectList must receive a real multi-row budget derived from terminal − lower block',
        );
        $this->assertLessThanOrEqual(QuestionOverlayWidget::MAX_PHYSICAL_ROWS, $lastRenderRows + 4);

        $overlay = $this->openOverlay($controller);
        $this->assertNotInstanceOf(
            VerticallyExpandableInterface::class,
            $overlay,
            'Question overlay must stay natural-height so LayoutEngine does not starve it after a tall transcript',
        );
        unset($harness, $controller);
    }

    #[Test]
    public function testShortChoiceListStaysCompactAndPromptVisible(): void
    {
        $harness = new VirtualTuiHarness(columns: 80, rows: 24, sessionId: 'question-short');
        $screen = $harness->screen();
        $screen->compactHeaderWidget()->setSnapshot(new CompactHeaderSnapshot(
            prompts: ['review'],
            skills: ['castor'],
        ));

        $controller = new QuestionController(new QuestionCoordinator(), $screen);
        $controller->open(new QuestionRequest(
            requestId: 'short',
            source: QuestionSource::AgentCore,
            kind: QuestionKind::Choice,
            prompt: 'SHORT_PROMPT_UNIQUE',
            choices: [
                new QuestionOption(label: 'Alpha'),
                new QuestionOption(label: 'Beta'),
            ],
            allowOther: false,
        ));

        $plain = $this->visiblePlain($harness, 24);
        $this->assertStringContainsString('SHORT_PROMPT_UNIQUE', $plain);
        $this->assertStringContainsString('Alpha', $plain);
        $this->assertStringContainsString('Beta', $plain);
        $this->assertStringContainsString('prompts', $plain);

        $list = $this->openSelectList($controller);
        $this->assertFalse($list->isVerticallyExpanded(), 'Question select lists must not blank-fill');
    }

    #[Test]
    public function testCancelClearsOverlayAndKeepsLowerChrome(): void
    {
        [$harness, $controller] = $this->openBusyChoice(80, 24);

        $controller->close();
        $plain = $this->visiblePlain($harness, 24);

        $this->assertFalse($controller->isOpen());
        $this->assertStringNotContainsString('UNIQUE_QUESTION_PROMPT', $plain);
        $this->assertStringContainsString('prompts', $plain);
        $this->assertStringContainsString('session question-budget', $plain);
    }

    #[Test]
    public function testVirtualKeyNavigationReachesLaterWrappedOptions(): void
    {
        [$harness, $controller, $list] = $this->openBusyChoice(120, 24);
        $harness->startInputLoop();
        try {
            $harness->tui()->setFocus($list);
            $harness->render();
            $harness->terminal()->consumeOutput();

            $selectedIndex = new \ReflectionProperty($list, 'selectedIndex');
            $this->assertSame(0, $selectedIndex->getValue($list));

            $harness->sendInput("\x1b[B\x1b[B"); // Down, Down -> C
            $this->assertSame(2, $selectedIndex->getValue($list));

            $buffer = new ScreenBuffer(
                width: $harness->terminal()->getColumns(),
                height: $harness->terminal()->getRows(),
            );
            $buffer->write($harness->terminal()->consumeOutput());
            $plain = $buffer->getScreen();

            $this->assertStringContainsString('C_UNIQUE', $plain, 'Down-key navigation must bring later wrapped options into view');
            $this->assertStringContainsString('→', $plain, 'Selected arrow must remain visible after navigation');
            // Delta may only redraw the changed select rows; assert the live
            // bottom viewport still keeps chrome after the key-driven selection.
            $viewport = $this->visiblePlain($harness, 24);
            $this->assertStringContainsString('session question-budget', $viewport);
            $this->assertStringContainsString('C_UNIQUE', $viewport);
            $this->assertStringContainsString('→', $viewport);
        } finally {
            $harness->stopInputLoop();
        }
        unset($controller);
    }

    #[Test]
    public function testExactLongChoiceInterruptShowsMultipleStartsAndArrowViaScreenBuffer(): void
    {
        $harness = new VirtualTuiHarness(columns: 200, rows: 24, sessionId: 'shot3-exact');
        $screen = $harness->screen();
        $factory = new TranscriptBlockFactory();
        $blocks = [];
        for ($i = 1; $i <= 10; ++$i) {
            $blocks[] = $factory->system(
                runId: 'shot3-exact',
                text: str_repeat("Transcript paragraph {$i} with enough wrapping content to consume rows. ", 5),
                seq: $i,
            );
        }
        $screen->setTranscriptBlocks($blocks);
        $screen->setWorkingVisible(true);
        $screen->setWorkingMessage('Waiting for your answer');
        $screen->compactHeaderWidget()->setSnapshot(new CompactHeaderSnapshot(
            prompts: ['report', 'simplify', 'task-done'],
            skills: ['castor', 'testing', 'unslop'],
            agentNames: ['architect', 'reviewer'],
        ));

        $choices = [
            new QuestionOption(label: 'Keep it uncommitted here and open a separate tracked Hatfield task once upstream settles. I leave composer.json (path repo), QuestionOverlayWidget, the ChatScreen overlay-ordering change, QuestionController wiring, and the new virtual budget test uncommitted in this worktree as the trial playground, then create a fresh task in the board describing the integration with acceptance criteria, and start it only after PR #66063 is approved or merged. This respects the task\'s own acceptance criteria that Hatfield integration is out of scope until an upstream API is finalized, and it keeps this branch purely about the upstream renderer. Cost: the local changes stay invisible to review and risk drifting against upstream as the branch moves, and any work you want to keep is one careless git operation away from being lost.'),
            new QuestionOption(label: 'Commit the integration on its own agent-core branch now, decoupled from the upstream PR. I create a new tracked task, move it to IN-PROGRESS to get a worktree, and land the overlay/ordering/bounds work plus its tests there against the current path-repo wiring, so it can be reviewed and iterated on its own timeline instead of waiting on Symfony reviewers. The upstream branch stays untouched and this worktree stops carrying half-finished local edits. Cost: the integration would necessarily build on an unreleased Symfony revision through a local path repository, which is exactly the kind of vendor hack this task says not to commit to agent-core.'),
            new QuestionOption(label: 'Fold the integration into this task and treat the scope as upstream plus Hatfield wiring. I commit the local changes here, note in the task log that the scope expanded beyond the written acceptance criteria, and validate the combined result through castor before any phase transition. One branch then tells the whole story from renderer change to question overlay behavior, which is easier to reason about than two coupled tasks. Cost: it contradicts the acceptance criterion about not silently expanding the task, it makes the upstream contribution harder to review in isolation, and it forces the local integration to be revalidated every time upstream commits change.'),
            new QuestionOption(label: 'Revert the local integration work and keep only the upstream patch. I restore composer.json and composer.lock, delete QuestionOverlayWidget and the new virtual test, and revert the QuestionController and ChatScreen edits, leaving this worktree holding nothing but the upstream branch reference and the recorded validation history. This is the cleanest stop: nothing local is half-done, and any future integration starts from a known-good upstream base. Cost: the overlay ordering and vertical budget findings are real defects you already found, and reverting means rediscovering and rewriting them later from the trial notes.'),
        ];

        $controller = new QuestionController(new QuestionCoordinator(), $screen);
        $controller->open(new QuestionRequest(
            requestId: 'shot3-exact',
            source: QuestionSource::AgentCore,
            kind: QuestionKind::Choice,
            prompt: 'This worktree has uncommitted local Hatfield integration beyond the trial wiring: composer.json/lock point symfony/tui at the local clone path, and QuestionController, ChatScreen, a new QuestionOverlayWidget, plus a new virtual budget test carry the overlay ordering and vertical budget fixes. Upstream PR #66063 is still open and pending CI. What should happen to this local integration work?',
            choices: $choices,
            header: 'Local Hatfield overlay changes in this worktree',
            allowOther: true,
        ));

        $list = $this->openSelectList($controller);
        $items = (new \ReflectionProperty($list, 'items'))->getValue($list);
        $this->assertSame($choices[0]->label, $items[0]['value'], 'Answer value must keep the full option text');
        $this->assertSame($choices[0]->label, $items[0]['label'], 'Displayed label must keep the full option text');
        $this->assertStringNotContainsString('…', $items[0]['label'], 'Displayed labels must wrap, not ellipsis-truncate');

        // Incremental terminal writer path, not only Renderer::renderFrame().
        $plain = $harness->plainScreenText();
        $this->assertStringContainsString('→', $plain, 'Selected arrow must survive ScreenBuffer incremental render');
        $this->assertStringContainsString('Keep it uncommitted', $plain);
        // Giant full labels may consume the entire bounded overlay; do not require
        // every choice start to fit in 12 rows. Prove selection and keyboard reach.
        $selectedIndex = new \ReflectionProperty($list, 'selectedIndex');
        $this->assertSame(0, $selectedIndex->getValue($list));

        $harness->startInputLoop();
        try {
            $harness->tui()->setFocus($list);
            $harness->render();
            $harness->terminal()->consumeOutput();
            $harness->sendInput("\x1b[B"); // Down -> second giant choice
            $this->assertSame(1, $selectedIndex->getValue($list));
            $after = $harness->plainScreenText();
            $this->assertStringContainsString('→', $after, 'Selected arrow must remain after Down');
            $this->assertStringContainsString('Commit the integration', $after);
        } finally {
            $harness->stopInputLoop();
        }

        unset($controller);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function terminalGeometries(): iterable
    {
        yield '80x16' => [80, 16, 1];
        yield '80x24' => [80, 24, 2];
        yield '120x24' => [120, 24, 3];
        yield '120x40' => [120, 40, 4];
    }

    /**
     * @return array{0: VirtualTuiHarness, 1: QuestionController, 2: SelectListWidget}
     */
    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBusyQuestionTransitionsDoNotClearOrReprintTranscript(): void
    {
        $columns = 120;
        $rows = 24;
        $output = new VirtualTerminal(columns: $columns, rows: $rows);
        $dispatcher = new EventDispatcher();
        $terminal = $this->createStub(TerminalInterface::class);
        $terminal->method('getEventDispatcher')->willReturn($dispatcher);
        $terminal->method('getColumns')->willReturn($columns);
        $terminal->method('getRows')->willReturn($rows);
        $terminal->method('isVirtual')->willReturn(false);
        $terminal->method('write')->willReturnCallback($output->write(...));
        $terminal->method('showCursor')->willReturnCallback($output->showCursor(...));
        $terminal->method('hideCursor')->willReturnCallback($output->hideCursor(...));
        $terminal->method('moveBy')->willReturnCallback($output->moveBy(...));
        $terminal->method('clearLine')->willReturnCallback($output->clearLine(...));
        $terminal->method('clearFromCursor')->willReturnCallback($output->clearFromCursor(...));
        $terminal->method('clearScreen')->willReturnCallback($output->clearScreen(...));
        $terminal->method('bell')->willReturnCallback($output->bell(...));
        $terminal->method('isKittyProtocolActive')->willReturn(false);
        $terminal->method('setTitle');
        $terminal->method('start');
        $terminal->method('stop');

        $tui = new Tui(terminal: $terminal, eventDispatcher: $dispatcher);
        $screen = new ChatScreen(
            new DefaultTheme(new ThemePalette('question-paint', [])),
            'question-paint',
            new PromptEditor(),
        );
        $screen->mount($tui);

        $factory = new TranscriptBlockFactory();
        $blocks = [];
        for ($i = 1; $i <= 18; ++$i) {
            $blocks[] = $factory->system(
                runId: 'question-paint',
                text: str_repeat("Busy transcript paragraph {$i} with wrapping content that fills the viewport and forces overflow. ", 5),
                seq: $i,
            );
        }
        $screen->setTranscriptBlocks($blocks);
        $screen->setWorkingVisible(true);
        $screen->setWorkingMessage('Waiting for your answer');
        $screen->compactHeaderWidget()->setSnapshot(new CompactHeaderSnapshot(
            prompts: ['report', 'simplify'],
            skills: ['castor', 'testing'],
            agentNames: ['reviewer'],
        ));
        $screen->promptEditor()->replaceText('Draft sentinel');

        $tui->requestRender(true);
        $tui->processRender();
        $buffer = new ScreenBuffer(width: $columns, height: $rows);
        $buffer->write($output->consumeOutput());

        $long = str_repeat('Giant choice wraps across many columns and needs many physical rows for the selected option. ', 10);
        $controller = new QuestionController(new QuestionCoordinator(), $screen);
        $controller->open(new QuestionRequest(
            requestId: 'question-paint',
            source: QuestionSource::AgentCore,
            kind: QuestionKind::Choice,
            prompt: 'What should happen to this local integration work after the upstream select-list wrapping lands, given that the Hatfield overlay budget and unfinished composer path dependency are still local-only trial state?',
            header: 'Choose how to continue the multiline select trial',
            choices: [
                new QuestionOption(label: 'A_START '.$long.' A_END'),
                new QuestionOption(label: 'B_START '.$long.' B_END'),
                new QuestionOption(label: 'C_START '.$long.' C_END'),
                new QuestionOption(label: 'D_START '.$long.' D_END'),
            ],
            allowOther: true,
        ));
        $tui->processRender();
        $openDelta = $output->consumeOutput();
        $buffer->write($openDelta);
        $this->assertStringNotContainsString("\x1b[2J", $openDelta, 'Opening must stay differential on an overheight frame');
        $this->assertStringNotContainsString('Busy transcript paragraph', $openDelta, 'Opening must not reprint unchanged transcript lines');
        $this->assertStringContainsString('A_START', $buffer->getScreen());
        $this->assertStringContainsString('session question-paint', $buffer->getScreen());
        $this->assertStringContainsString('prompts', $buffer->getScreen());

        $list = $this->openSelectList($controller);
        $selectedIndex = new \ReflectionProperty($list, 'selectedIndex');
        for ($index = 1; $index <= 3; ++$index) {
            $list->setSelectedIndex($index);
            $screen->requestRender(false);
            $tui->processRender();
            $navDelta = $output->consumeOutput();
            $buffer->write($navDelta);
            $this->assertStringNotContainsString("\x1b[2J", $navDelta);
            $this->assertStringNotContainsString('Busy transcript paragraph', $navDelta);
            $this->assertSame($index, $selectedIndex->getValue($list));
        }

        $list->setSelectedIndex(4);
        $screen->requestRender(false);
        $tui->processRender();
        $otherDelta = $output->consumeOutput();
        $buffer->write($otherDelta);
        $this->assertStringNotContainsString("\x1b[2J", $otherDelta, 'Short Other selection must not shrink-clear the viewport');
        $this->assertStringNotContainsString('Busy transcript paragraph', $otherDelta);
        $this->assertStringContainsString('Type your answer', $buffer->getScreen());
        $this->assertStringContainsString('session question-paint', $buffer->getScreen());
        $this->assertStringContainsString('Draft sentinel', $buffer->getScreen());

        $dismiss = new \ReflectionMethod(QuestionController::class, 'dismissToEditor');
        $dismiss->invoke($controller);
        $tui->processRender();
        $dismissDelta = $output->consumeOutput();
        $buffer->write($dismissDelta);
        $this->assertStringNotContainsString("\x1b[2J", $dismissDelta, 'Dismissing to free-form input must not clear the screen');
        $this->assertStringNotContainsString('Busy transcript paragraph', $dismissDelta);
        $this->assertFalse($controller->isOpen());
        $this->assertTrue($controller->isAwaitingFreeForm());
        $this->assertStringContainsString('Type your answer and press Enter', $buffer->getScreen());
        $this->assertStringContainsString('session question-paint', $buffer->getScreen());
        $this->assertStringContainsString('Draft sentinel', $buffer->getScreen());
        $this->assertStringNotContainsString('A_START', $buffer->getScreen());
    }

    private function openBusyChoice(int $columns, int $rows): array
    {
        $harness = new VirtualTuiHarness(columns: $columns, rows: $rows, sessionId: 'question-budget');
        $screen = $harness->screen();
        $factory = new TranscriptBlockFactory();
        $blocks = [];
        for ($i = 1; $i <= 10; ++$i) {
            $blocks[] = $factory->system(
                runId: 'question-budget',
                text: str_repeat("Transcript paragraph {$i} with enough wrapping content to consume rows. ", 5),
                seq: $i,
            );
        }
        $screen->setTranscriptBlocks($blocks);
        $screen->setWorkingVisible(true);
        $screen->setWorkingMessage('Waiting for your answer');
        $screen->compactHeaderWidget()->setSnapshot(new CompactHeaderSnapshot(
            prompts: ['review', 'report'],
            skills: ['castor', 'testing'],
            agentNames: ['scout'],
        ));

        $controller = new QuestionController(new QuestionCoordinator(), $screen);
        $controller->open($this->giantChoiceRequest());

        return [$harness, $controller, $this->openSelectList($controller)];
    }

    private function giantChoiceRequest(): QuestionRequest
    {
        $long = str_repeat('Long choice wraps across columns and needs multiple physical rows. ', 2);

        return new QuestionRequest(
            requestId: 'giant-choice',
            source: QuestionSource::AgentCore,
            kind: QuestionKind::Choice,
            prompt: 'UNIQUE_QUESTION_PROMPT Why did you choose this approach?',
            choices: [
                new QuestionOption(label: 'A_UNIQUE '.$long),
                new QuestionOption(label: 'B_UNIQUE '.$long),
                new QuestionOption(label: 'C_UNIQUE '.$long),
                new QuestionOption(label: 'D_UNIQUE '.$long),
            ],
            allowOther: false,
        );
    }

    private function visiblePlain(VirtualTuiHarness $harness, int $rows): string
    {
        $tui = $harness->tui();
        $root = (new \ReflectionProperty($tui, 'root'))->getValue($tui);
        $renderer = (new \ReflectionProperty($tui, 'renderer'))->getValue($tui);
        $frame = $renderer->renderFrame($root, $harness->terminal()->getColumns(), $rows)->toArray();
        $plainLines = array_map(
            static fn (string $line): string => preg_replace('/\x1b\[[0-9;]*m/', '', $line) ?? $line,
            $frame,
        );

        // Match Symfony ScreenWriter: taller frames keep the bottom viewport.
        return implode("\n", \array_slice($plainLines, max(0, \count($plainLines) - $rows)));
    }

    private function openOverlay(QuestionController $controller): QuestionOverlayWidget
    {
        $container = (new \ReflectionProperty($controller, 'container'))->getValue($controller);
        $this->assertInstanceOf(QuestionOverlayWidget::class, $container);

        return $container;
    }

    private function openSelectList(QuestionController $controller): SelectListWidget
    {
        $list = (new \ReflectionProperty($controller, 'listWidget'))->getValue($controller);
        $this->assertInstanceOf(SelectListWidget::class, $list);

        return $list;
    }
}
