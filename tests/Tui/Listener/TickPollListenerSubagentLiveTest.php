<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\TurnTreeProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\TickPollListener;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\RuntimeEventPoller;
use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\Tui\Runtime\TuiRuntimeEventApplier;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Runtime\TuiTickDispatcher;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\TranscriptDisplayConfig;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Tui;

/** @covers \Ineersa\Tui\Listener\TickPollListener */
final class TickPollListenerSubagentLiveTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    public function testParentPollUpdatesStateWhileLiveViewKeepsChildTranscriptOnScreen(): void
    {
        $parentRun = 'session-100';
        $client = new ParentEventClient($parentRun, new RuntimeEvent(
            RuntimeEventTypeEnum::AssistantMessageCompleted->value,
            $parentRun,
            2,
            ['text' => 'new parent block'],
        ));

        $parentProjector = new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState());
        $poller = new RuntimeEventPoller(
            new TuiRuntimeEventApplier($parentProjector),
            new TestLogger(),
            new RuntimeExceptionBoundary(new EventDispatcher()),
            $this->createStub(TurnTreeProviderInterface::class),
        );

        $state = new TuiSessionState($parentRun);
        $state->handle = new RunHandle($parentRun, 'running');
        $state->lastSeq = 1;
        $state->activity = RunActivityStateEnum::Running;
        $state->transcript = [new TranscriptBlock('p1', TranscriptBlockKindEnum::UserMessage, $parentRun, 1, 'parent line')];

        $child = new SubagentLiveChildDTO('child-200', 'art1', 'scout', SubagentLiveStatusEnum::Running, 'task', 1);
        $state->subagentLiveView->enter($child);
        $state->subagentLiveView->childTranscript = [
            new TranscriptBlock('c1', TranscriptBlockKindEnum::Progress, 'child-200', 1, 'child live'),
        ];

        $childPoller = new SubagentLiveChildViewPoller(
            new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()),
        );

        $tui = new Tui();
        $screen = new ChatScreen(new DefaultTheme(new ThemePalette('test')), $parentRun, new PromptEditor(), new TranscriptDisplayConfig(), new TranscriptDisplayState());
        $screen->setTranscriptBlocks($state->subagentLiveView->childTranscript);

        $listenerRef = new \ReflectionClass(TickPollListener::class);
        $listener = $listenerRef->newInstanceWithoutConstructor();
        $listenerRef->getProperty('poller')->setValue($listener, $poller);
        $listenerRef->getProperty('subagentLiveChildPoller')->setValue($listener, $childPoller);
        $listenerRef->getProperty('questionCoordinator')->setValue($listener, new QuestionCoordinator());
        $ctrlRef = new \ReflectionClass(QuestionController::class);
        $listenerRef->getProperty('questionController')->setValue($listener, $ctrlRef->newInstanceWithoutConstructor());

        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withClient($client)
            ->withState($state)
            ->withScreen($screen)
            ->build();
        $listener->register($context);
        $handlerRef = new \ReflectionProperty(TuiTickDispatcher::class, 'handlers');
        ($handlerRef->getValue($context->ticks)[0])();

        self::assertSame(2, $state->lastSeq, 'Parent poller must advance lastSeq while live view is active');

        $ref = new \ReflectionClass($screen);
        $widget = $ref->getProperty('transcriptRenderable')->getValue($screen);
        $blocks = (new \ReflectionClass($widget))->getProperty('blocks')->getValue($widget);
        $text = implode(' ', array_map(static fn ($b) => $b->text, $blocks));
        self::assertStringContainsString('child live', $text);
        self::assertStringNotContainsString('new parent block', $text);
    }
}

final class ParentEventClient implements AgentSessionClient
{
    private bool $yielded = false;

    public function __construct(private string $parentRun, private RuntimeEvent $event) {}

    public function start(StartRunRequest $request): RunHandle { throw new \BadMethodCallException(); }
    public function attach(string $runId): RunHandle { return new RunHandle($runId, 'attached'); }
    public function send(string $runId, UserCommand $command): void {}
    public function events(string $runId): iterable
    {
        if ($runId === $this->parentRun && !$this->yielded) {
            $this->yielded = true;
            yield $this->event;
        }
    }
    public function cancel(string $runId): void {}
    public function shellExecute(string $command, string $sessionId, string $cwd): RunHandle { throw new \BadMethodCallException(); }
    public function completeRun(string $runId): void {}
    public function compact(string $runId, ?string $customInstructions = null): void {}
}
