<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\CompactionProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjectionEvent;
use Ineersa\Tui\Command\CommandParser;
use Ineersa\Tui\Command\DispatchRuntime;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\SubmissionRouter;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Listener\CompactCommandRegistrar;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TuiCompactCommandVirtualTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    #[Test]
    public function testCompactProgressMessageRendersOnVirtualScreen(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'compact-virtual');
        $state = new TuiSessionState('compact-virtual');
        $state->handle = new RunHandle('run-virtual');
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->withClient($this->createStub(AgentSessionClient::class))
            ->build();

        $registry = new SlashCommandRegistry();
        (new CompactCommandRegistrar($registry))->register($context);

        $router = new SubmissionRouter(new CommandParser(), $registry);
        $result = $router->route('/compact');

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertNotInstanceOf(DispatchRuntime::class, $result);
        $this->assertSame('Compacting conversation...', $result->text);

        $factory = new TranscriptBlockFactory();
        $harness->screen()->setTranscriptBlocks([
            $factory->system(runId: 'compact-virtual', text: $result->text, seq: 1, style: $result->style),
        ]);

        $screen = $harness->plainScreenText();
        $this->assertStringContainsString('Compacting conversation', $screen);
    }

    #[Test]
    public function testCompactionCompletedBlockRendersOnVirtualScreen(): void
    {
        $projectionState = new TranscriptProjectionState();
        $event = new TranscriptProjectionEvent(
            rawEvent: [
                'type' => 'compaction.completed',
                'runId' => 'run-virtual',
                'seq' => 1,
                'payload' => [
                    'estimated_tokens_before' => 1200,
                    'estimated_tokens_after' => 700,
                ],
            ],
            state: $projectionState,
        );

        (new CompactionProjectionSubscriber())->onCompactionCompleted($event);

        $blocks = $projectionState->blocks();
        $this->assertCount(1, $blocks);
        $this->assertSame(TranscriptBlockKindEnum::System, $blocks[0]->kind);
        $this->assertStringContainsString('Conversation compacted', $blocks[0]->text);

        $harness = new VirtualTuiHarness(sessionId: 'compact-result-virtual');
        $harness->screen()->setTranscriptBlocks($blocks);

        $screen = $harness->plainScreenText();
        $this->assertStringContainsString('Conversation compacted', $screen);
    }
}
