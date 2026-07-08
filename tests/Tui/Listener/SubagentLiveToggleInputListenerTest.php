<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Export\SessionEventsExportService;
use Ineersa\Tui\Listener\SubagentLiveToggleInputListener;
use Ineersa\Tui\Picker\SubagentLivePickerController;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\ContextUsageTestAppConfig;
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
            $this->sessionStore(),
            new SessionEventsExportService(),
            ContextUsageTestAppConfig::withContextWindow(),
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

    private function sessionStore(): HatfieldSessionStore
    {
        return new HatfieldSessionStore(
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                sessions: new SessionsConfig(),
                cwd: '/tmp',
            ),
            entityManager: $this->createStub(EntityManagerInterface::class),
        );
    }
}
