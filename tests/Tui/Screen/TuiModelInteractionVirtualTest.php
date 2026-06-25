<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\AssistantStreamProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\CancellationProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\HitlProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\ModelNotificationProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\RunLifecycleProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\ToolProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\UserMessageProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\Tui\Listener\FooterStateSegmentProvider;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Runtime\UsageProjection;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Deterministic model-replay assistant/footer proof without tmux.
 *
 * Test thesis: assistant text projected from replay-style runtime events
 * renders as a ◇ block with fixture text, session id appears in the footer,
 * cache telemetry renders ↻ 78%, and working spinner is absent after completion.
 *
 * Dispatch/poller coverage remains in SubmitListenerDispatchRuntimeTest and
 * RuntimeEventPollerTest; this test bridges transcript + usage + screen render.
 */
final class TuiModelInteractionVirtualTest extends TestCase
{
    private const string SESSION_ID = 'virtual-model-replay-session';
    private const string RUN_ID = 'virtual-model-replay-run';
    private const string ASSISTANT_TEXT = 'The sky is blue.';

    #[Test]
    public function testReplayStyleAssistantResponseRendersBlockFooterAndCacheHit(): void
    {
        $projector = $this->createTranscriptProjector();
        $this->projectAssistantTextResponse($projector);

        $blocks = $projector->blocks();
        self::assertCount(1, $blocks);
        self::assertSame(TranscriptBlockKindEnum::AssistantMessage, $blocks[0]->kind);
        self::assertSame(self::ASSISTANT_TEXT, $blocks[0]->text);
        self::assertFalse($blocks[0]->streaming);

        $state = new TuiSessionState(self::SESSION_ID);
        $state->footerModel = 'llama_cpp_test/test';
        $state->usage->accumulate($this->makeAssistantMessageCompletedWithCacheTelemetry());

        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks($blocks);
        $harness->screen()->addFooterProvider(new FooterStateSegmentProvider($state));
        $harness->screen()->setWorkingVisible(false);
        $harness->screen()->setWorkingMessage(null);

        $screen = $harness->plainScreenText();

        self::assertStringContainsString('◇', $screen, 'Assistant block glyph missing');
        self::assertStringContainsString(self::ASSISTANT_TEXT, $screen, 'Fixture assistant text missing');
        self::assertStringContainsString('session '.self::SESSION_ID, $screen, 'Session label missing in footer');
        self::assertStringContainsString('↻ 78%', $screen, 'Cache-hit footer segment missing');
        self::assertStringNotContainsString('◐ Working...', $screen, 'Working spinner should be hidden after completion');
    }

    private function createTranscriptProjector(): TranscriptProjector
    {
        $dispatcher = new EventDispatcher();
        $state = new TranscriptProjectionState();

        $dispatcher->addSubscriber(new UserMessageProjectionSubscriber());
        $dispatcher->addSubscriber(new AssistantStreamProjectionSubscriber());
        $dispatcher->addSubscriber(new ToolProjectionSubscriber());
        $dispatcher->addSubscriber(new HitlProjectionSubscriber());
        $dispatcher->addSubscriber(new CancellationProjectionSubscriber());
        $dispatcher->addSubscriber(new RunLifecycleProjectionSubscriber());
        $dispatcher->addSubscriber(new ModelNotificationProjectionSubscriber());

        return new TranscriptProjector($dispatcher, $state);
    }

    private function projectAssistantTextResponse(TranscriptProjector $projector): void
    {
        $seq = 0;
        $accept = static function (string $type, array $payload) use ($projector, &$seq): void {
            ++$seq;
            $projector->accept([
                'type' => $type,
                'runId' => self::RUN_ID,
                'seq' => $seq,
                'payload' => $payload,
            ]);
        };

        $accept('assistant.text_started', [
            'message_id' => 'a1',
            'content_index' => 0,
            'block_id' => 'a1_t0',
        ]);
        $accept('assistant.text_delta', [
            'block_id' => 'a1_t0',
            'text' => self::ASSISTANT_TEXT,
        ]);
        $accept('assistant.text_completed', [
            'block_id' => 'a1_t0',
            'text' => self::ASSISTANT_TEXT,
        ]);
    }

    private function makeAssistantMessageCompletedWithCacheTelemetry(): RuntimeEvent
    {
        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::AssistantMessageCompleted->value,
            runId: self::RUN_ID,
            seq: 4,
            payload: [
                'usage' => [
                    'input_tokens' => 100,
                    'output_tokens' => 541,
                    'cache_read_tokens' => 78,
                ],
            ],
        );
    }
}
