<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotProviderInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\Tui\Listener\RuntimeQuestionEventHandler;
use Ineersa\Tui\Listener\SubagentLiveToggleInputListener;
use Ineersa\Tui\Picker\SubagentLivePickerController;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class SubagentLiveToggleInputListenerTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    #[Test]
    public function testCtrlBackslashReturnsFromLiveView(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'toggle-live');
        $state = new TuiSessionState('toggle-live');
        $state->subagentLiveView->active = true;

        $picker = new SubagentLivePickerController(
            new SubagentLiveChildViewPoller(
                new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()),
                new NullLogger(),
            ),
            $this->createStub(ChildRunTranscriptSnapshotProviderInterface::class),
            new RuntimeQuestionEventHandler(),
            new QuestionCoordinator(),
            (new \ReflectionClass(QuestionController::class))->newInstanceWithoutConstructor(),
        );
        $picker->setRuntimeRefs($harness->tui(), $harness->screen(), $state);

        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();

        (new SubagentLiveToggleInputListener($picker))->register($context);
        $harness->startInputLoop();
        $harness->sendInput("\x1c");

        $this->assertFalse($state->subagentLiveView->active);
    }
}
