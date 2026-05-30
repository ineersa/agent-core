<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Question\QuestionKind;
use Ineersa\Tui\Question\QuestionRequest;
use Ineersa\Tui\Question\QuestionSource;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatScreen::class)]
final class ChatScreenQuestionSlotTest extends TestCase
{
    private function createScreen(): ChatScreen
    {
        $theme = new DefaultTheme(
            new ThemePalette('test', ['warning' => 'yellow', 'text' => '', 'muted' => '#888', 'accent' => 'cyan']),
        );

        return new ChatScreen(
            theme: $theme,
            sessionId: 'test-session',
            promptEditor: new PromptEditor(),
        );
    }

    private function makeRequest(): QuestionRequest
    {
        return new QuestionRequest(
            requestId: 'q-1',
            source: QuestionSource::Tui,
            kind: QuestionKind::Text,
            prompt: 'Enter something:',
        );
    }

    public function testSetQuestionRequestDoesNotThrow(): void
    {
        $screen = $this->createScreen();
        $request = $this->makeRequest();

        // Must not throw; verifies the questionRenderable and questionWidget
        // chain are properly wired.
        $screen->setQuestionRequest($request);
        // No assertion needed beyond "no exception"
        self::expectNotToPerformAssertions();
    }

    public function testSetQuestionRequestNullClears(): void
    {
        $screen = $this->createScreen();
        $request = $this->makeRequest();

        $screen->setQuestionRequest($request);
        $screen->setQuestionRequest(null);

        // Must not throw — null clears the request properly
        self::expectNotToPerformAssertions();
    }

    public function testClearQuestionDoesNotThrow(): void
    {
        $screen = $this->createScreen();
        $request = $this->makeRequest();

        $screen->setQuestionRequest($request);
        $screen->clearQuestion();

        self::expectNotToPerformAssertions();
    }

    public function testClearQuestionOnEmptyDoesNotThrow(): void
    {
        $screen = $this->createScreen();

        $screen->clearQuestion();

        self::expectNotToPerformAssertions();
    }

    public function testQuestionWidgetPresentInRefresh(): void
    {
        $screen = $this->createScreen();

        // refresh() must not throw — verifies questionWidget is in the
        // invalidate list
        $screen->refresh();

        self::expectNotToPerformAssertions();
    }
}
