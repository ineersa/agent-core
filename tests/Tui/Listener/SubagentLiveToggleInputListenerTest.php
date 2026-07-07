<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\Tui\Listener\SubagentLiveToggleInputListener;
use Ineersa\Tui\Picker\SubagentLivePickerController;
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

        $picker = new SubagentLivePickerController(new SubagentLiveChildViewPoller(
            new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()),
            new NullLogger(),
        ));
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
