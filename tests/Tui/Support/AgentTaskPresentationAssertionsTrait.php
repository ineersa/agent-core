<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Support;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\UserMessageProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\Tui\Listener\PreviewExpansionInputListener;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use Ineersa\Tui\Transcript\TranscriptGlyphs;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** Producer tests use their canonical events, not manually tagged transcript blocks. */
trait AgentTaskPresentationAssertionsTrait
{
    use TuiRuntimeContextBuilderTrait;

    /** @param list<RunEvent> $events */
    private function assertAgentTaskPresentation(array $events, string $artifactId, string $taskBody, string $taskExcerpt, string $inheritedHuman): void
    {
        $serializer = AttributeSerializerValidatorTestFactory::serializer();
        $translator = new RuntimeEventTranslator(new EventDispatcher(), new ToolExecutionEndPayloadCodec($serializer), $serializer);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new UserMessageProjectionSubscriber());
        $projector = new TranscriptProjector($dispatcher, new TranscriptProjectionState());
        foreach ($events as $canonical) {
            $event = $translator->translate($canonical);
            if (null !== $event) {
                $projector->accept($event);
            }
        }
        $blocks = $projector->blocks();
        $task = array_find($blocks, static fn (TranscriptBlock $block): bool => $taskBody === $block->text);
        $human = array_find($blocks, static fn (TranscriptBlock $block): bool => $inheritedHuman === $block->text);
        $this->assertNotNull($task);
        $this->assertNotNull($human);
        $this->assertSame(['agent_task' => ['artifact_id' => $artifactId]], $task->meta['producer_metadata']);
        $this->assertArrayNotHasKey('producer_metadata', $human->meta);

        $display = new TranscriptDisplayState();
        $harness = new VirtualTuiHarness(columns: 80, rows: 100, displayState: $display);
        $state = new TuiSessionState($events[0]->runId);
        $state->transcriptDisplayState = $display;
        $state->replaceTranscript($blocks);
        $harness->screen()->setWorkingVisible(false);
        $harness->screen()->setTranscriptBlocks($blocks);
        (new PreviewExpansionInputListener())->register($this->buildTuiContext()->withTui($harness->tui())->withState($state)->withScreen($harness->screen())->build());
        $harness->startInputLoop();
        try {
            $collapsed = $harness->plainScreenText();
            $this->assertStringContainsString(trim(TranscriptGlyphs::GLYPH_SYSTEM_INFO).' Agent task:', $collapsed);
            $this->assertStringContainsString(trim(TranscriptGlyphs::GLYPH_USER_MESSAGE).' '.explode("\n", $inheritedHuman)[0], $collapsed);
            $this->assertStringContainsString('Inherited human final line.', $collapsed);
            $this->assertStringNotContainsString($artifactId, $collapsed);
            $harness->sendInput("\x0f");
            $this->assertTrue($display->previewableBlocksExpanded);
            $expanded = $harness->plainScreenText();
            $this->assertStringContainsString($artifactId, $expanded);
            $this->assertStringContainsString('Inherited human final line.', $expanded);
            $this->assertStringContainsString($taskExcerpt, $expanded);
            $harness->sendInput("\x0f");
            $this->assertFalse($display->previewableBlocksExpanded);
            $this->assertStringNotContainsString($artifactId, $harness->plainScreenText());
            $this->assertStringContainsString('Inherited human final line.', $harness->plainScreenText());
            $this->assertSame($taskBody, $task->text);
        } finally {
            $harness->stopInputLoop();
        }
    }
}
