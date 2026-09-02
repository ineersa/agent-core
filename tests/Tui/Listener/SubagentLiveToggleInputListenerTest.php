<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\ChildAgentEventsPathResolverInterface;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotProviderInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
use Ineersa\Tui\Export\SessionEventsExportService;
use Ineersa\Tui\Tests\Support\SessionEventsExportServiceFactory;
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

        $picker = new SubagentLivePickerController(
            $harness->tui(),
            $harness->screen(),
            $state,
            $this->createStub(\Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient::class),
            new SubagentLiveChildViewPoller(new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()), new NullLogger(), SubagentProgressSerializerTestSupport::denormalizer()),
            $this->createStub(ChildRunTranscriptSnapshotProviderInterface::class),
            $this->createStub(ChildAgentEventsPathResolverInterface::class),
            SessionEventsExportServiceFactory::create(),
        );

        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->withSessionServices($this->createSessionServices(
                tui: $harness->tui(),
                state: $state,
                screen: $harness->screen(),
                subagentLivePicker: $picker,
            ))
            ->build();

        (new SubagentLiveToggleInputListener())->register($context);
        $harness->startInputLoop();
        $harness->sendInput("\x1c");

        $this->assertFalse($state->subagentLiveView->active);
    }
}
