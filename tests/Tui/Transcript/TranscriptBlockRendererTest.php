<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Transcript;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\AssistantStreamProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Stream\AssistantTextStreamSubscriber;
use Ineersa\CodingAgent\Runtime\Stream\AssistantThinkingStreamSubscriber;
use Ineersa\CodingAgent\Runtime\Stream\LlmStreamDispatchObserver;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\TranscriptDisplayConfig;
use Ineersa\Tui\Transcript\TranscriptGlyphs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\MessageItem;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Result\Stream\Delta\MessageComplete;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Result\Stream\Delta\MessageStart;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingStart;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Terminal\ScreenBuffer;

final class TranscriptBlockRendererTest extends TestCase
{
    #[DataProvider('messageDeliveryModes')]
    public function testCommentaryAndAnswerRenderSeparatelyLiveAndAfterReplayWithThinkingHidden(bool $terminalOnly, bool $phaseOnComplete): void
    {
        $harness = new VirtualTuiHarness(
            palette: new ThemePalette('commentary-test', [ThemeColorEnum::AssistantMessage->value => 'green', ThemeColorEnum::Muted->value => 'red']),
            displayConfig: new TranscriptDisplayConfig(thinkingVisible: false),
        );
        $harness->screen()->setWorkingVisible(false);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new AssistantStreamProjectionSubscriber());
        $projector = new TranscriptProjector($dispatcher, new TranscriptProjectionState());
        $sink = new class($projector) implements RuntimeEventSinkInterface {
            public function __construct(private readonly TranscriptProjector $projector)
            {
            }

            public function emit(RuntimeEvent $event): void
            {
                $this->projector->accept($event);
            }
        };
        $streamDispatcher = new EventDispatcher();
        $streamDispatcher->addSubscriber(new AssistantTextStreamSubscriber($sink));
        $streamDispatcher->addSubscriber(new AssistantThinkingStreamSubscriber($sink));
        $observer = new LlmStreamDispatchObserver($streamDispatcher);
        $observer->onStreamStart('run', 'step');
        $observer->onDelta('run', 'step', new ThinkingStart());
        $observer->onDelta('run', 'step', new ThinkingDelta('Hidden reasoning'));
        $observer->onDelta('run', 'step', new ThinkingComplete('Hidden reasoning'));
        $harness->screen()->applyTranscriptChangeSet($projector->drainChanges());
        $content = [];
        foreach ([['commentary', "**Checking files** and *planning* the next step.\n\nNarration continues."], ['final_answer', 'The answer is **42**.']] as $index => [$phase, $text]) {
            $item = ['type' => 'message', 'id' => 'msg_'.$index, 'role' => 'assistant', 'phase' => $phase, 'content' => [['type' => 'output_text', 'text' => $text]]];
            if (!$terminalOnly) {
                $header = $item;
                if ($phaseOnComplete) {
                    unset($header['phase']);
                }
                $observer->onDelta('run', 'step', new MessageStart($header, $index));
                $observer->onDelta('run', 'step', new TextDelta($text));
                $harness->screen()->applyTranscriptChangeSet($projector->drainChanges());
                if (0 === $index) {
                    $this->assertStringContainsString('Checking files', $harness->plainScreenText());
                    $this->assertRenderedTextStyle($harness, 'Checking files', !$phaseOnComplete);
                    $this->assertStringNotContainsString('The answer is', $harness->plainScreenText());
                }
            }
            $signed = MessageItem::toText($item);
            $observer->onDelta('run', 'step', new MessageComplete($signed, $index));
            $harness->screen()->applyTranscriptChangeSet($projector->drainChanges());
            $content[] = ['type' => 'text', 'text' => $text, 'text_signature' => $signed->getSignature()];
        }
        $live = $harness->plainScreenText();
        $this->assertStringContainsString(trim(TranscriptGlyphs::GLYPH_ASSISTANT_MESSAGE).' Checking files', $live);
        $this->assertStringNotContainsString('[commentary]', $live);
        $this->assertRenderedTextStyle($harness, 'Checking files', true);
        $this->assertRenderedTextStyle($harness, 'planning the next step.', true);
        $this->assertRenderedTextStyle($harness, 'Narration continues.', true);
        $this->assertRenderedTextStyle($harness, 'The answer is 42.', false);
        $this->assertStringContainsString(trim(TranscriptGlyphs::GLYPH_ASSISTANT_MESSAGE).' The answer is 42.', $live);
        $this->assertSame(1, substr_count($live, 'Checking files'));
        $this->assertSame(1, substr_count($live, 'The answer is 42.'));
        $this->assertStringNotContainsString('**', $live, 'Both channels use the real Markdown widget.');

        $translator = new RuntimeEventTranslator(new EventDispatcher(), new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer()));
        $completion = $translator->translate(new RunEvent('run', 5, 1, 'llm_step_completed', ['step_id' => 'step', 'assistant_message' => ['content' => $content, 'details' => ['thinking' => 'Hidden reasoning']]]));
        $this->assertNotNull($completion);
        $projector->accept($completion);
        $harness->screen()->applyTranscriptChangeSet($projector->drainChanges());
        $this->assertSame($live, $harness->plainScreenText(), 'Canonical completion must not duplicate streamed messages.');
        $this->assertRenderedTextStyle($harness, 'Checking files', true);
        $this->assertRenderedTextStyle($harness, 'The answer is 42.', false);
        $projector->reset();
        $projector->accept($completion);
        $harness->screen()->setTranscriptBlocks($projector->blocks());
        $this->assertSame($live, $harness->plainScreenText(), 'Cold canonical replay renders the same phase-separated text.');
        $this->assertRenderedTextStyle($harness, 'Checking files', true);
        $this->assertRenderedTextStyle($harness, 'planning the next step.', true);
        $this->assertRenderedTextStyle($harness, 'Narration continues.', true);
        $this->assertRenderedTextStyle($harness, 'The answer is 42.', false);
        $this->assertStringNotContainsString('Hidden reasoning', $harness->plainScreenText());
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function messageDeliveryModes(): iterable
    {
        yield 'streamed' => [false, false];
        yield 'terminal-only' => [true, false];
        yield 'phase on completion' => [false, true];
    }

    public function testLateNarrationReordersMountedTextAndToolWidgetsWithoutDroppingLocalUi(): void
    {
        $harness = new VirtualTuiHarness();
        $harness->screen()->setWorkingVisible(false);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new AssistantStreamProjectionSubscriber());
        $projector = new TranscriptProjector($dispatcher, new TranscriptProjectionState());
        $projector->accept(new RuntimeEvent(RuntimeEventTypeEnum::AssistantMessageCompleted->value, 'run', 0, ['message_id' => 'step', 'tool_calls' => [['id' => 'call', 'name' => 'read', 'arguments' => []]]]));
        $projector->accept(new RuntimeEvent(RuntimeEventTypeEnum::AssistantTextCompleted->value, 'run', 0, ['step_id' => 'step', 'block_id' => 'final', 'output_index' => 2, 'phase' => 'final_answer', 'text' => 'Answer']));
        $harness->screen()->applyTranscriptChangeSet($projector->drainChanges());
        $harness->screen()->setTranscriptBlocks([new TranscriptBlock('local', TranscriptBlockKindEnum::System, 'run', 0, 'Local notification'), ...$projector->blocks()]);

        $projector->accept(new RuntimeEvent(RuntimeEventTypeEnum::AssistantMessageCompleted->value, 'run', 1, ['message_id' => 'step', 'content' => [
            ['type' => 'text', 'block_id' => 'comment', 'phase' => 'commentary', 'output_index' => 0, 'text' => 'Narration'],
            ['type' => 'tool_call', 'id' => 'call', 'name' => 'read', 'arguments' => []],
            ['type' => 'text', 'block_id' => 'final', 'phase' => 'final_answer', 'output_index' => 2, 'text' => 'Answer'],
        ]]));
        $harness->screen()->applyTranscriptChangeSet($projector->drainChanges());
        $text = $harness->plainScreenText();
        $this->assertStringContainsString('Local notification', $text);
        $this->assertStringContainsString('Narration', $text);
        $this->assertStringNotContainsString('[commentary]', $text);
        $this->assertSame(1, substr_count($text, 'Narration'));
        $this->assertSame(1, substr_count($text, 'Answer'));
        $this->assertLessThan(strpos($text, 'read'), strpos($text, 'Narration'));
        $this->assertLessThan(strpos($text, 'Answer'), strpos($text, 'read'));
    }

    #[DataProvider('questionStatuses')]
    public function testQuestionCardShowsItsStatus(string $status, string $header): void
    {
        $harness = new VirtualTuiHarness();
        $harness->screen()->setWorkingVisible(false);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'question',
                kind: TranscriptBlockKindEnum::Question,
                runId: 'virtual-startup-session',
                seq: 1,
                text: 'Continue deployment? ('.$status.')',
                meta: ['prompt' => 'Continue deployment?', 'status' => $status],
            ),
        ]);
        $text = $harness->plainScreenText();
        $this->assertStringContainsString($header, $text);
        $this->assertStringContainsString('Continue deployment?', $text);
        if ('pending' === $status) {
            $this->assertStringContainsString('awaiting answer', $text);
        } else {
            $this->assertStringNotContainsString('Human input required', $text);
            $this->assertStringNotContainsString('awaiting answer', $text);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function questionStatuses(): iterable
    {
        yield 'cancelled' => ['cancelled', 'Human input cancelled'];
        yield 'pending' => ['pending', 'Human input required'];
        yield 'answered' => ['answered', 'Human input answered'];
        yield 'rejected' => ['rejected', 'Human input rejected'];
    }

    private function assertRenderedTextStyle(VirtualTuiHarness $harness, string $text, bool $italic): void
    {
        $buffer = new ScreenBuffer($harness->terminal()->getColumns(), $harness->terminal()->getRows());
        $buffer->write($harness->ansiOutput());
        foreach ($buffer->getCells() as $cells) {
            $line = implode('', array_column($cells, 'char'));
            $start = strpos($line, $text);
            if (false === $start) {
                continue;
            }
            $offset = 0;
            foreach ($cells as $cell) {
                if ($offset >= $start && $offset < $start + \strlen($text) && '' !== $cell['char']) {
                    $this->assertMatchesRegularExpression('/(?:\x1b\[|;)32(?:;|m)/', $cell['style'], 'Rendered text retains the normal assistant palette color.');
                    $this->assertSame($italic, 1 === preg_match('/(?:\x1b\[|;)3(?:;|m)/', $cell['style']), 'Italic must reach every rendered text cell, including Markdown spans.');
                }
                $offset += \strlen($cell['char']);
            }

            return;
        }
        $this->fail('Rendered text was not found: '.$text);
    }
}
