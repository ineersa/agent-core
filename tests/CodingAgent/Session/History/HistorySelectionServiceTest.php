<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\History;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Application\Dto\RunStateReplayResult;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\RunStateDuplicateSequenceReplayException;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Tests\Support\TestActiveRunContext;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\AgentCore\Tests\Support\TestTransitionFinalizerFactory;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\History\HistorySelectionService;
use Ineersa\CodingAgent\Session\RunHistoryIndex;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Tests\Support\HistoryEventStoreFactory;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class HistorySelectionServiceTest extends TestCase
{
    private string $directory;
    private SessionRunEventStore $store;
    private TestActiveRunContext $context;
    private TestMessageBus $commands;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = TestDirectoryIsolation::createProjectTempDir('history-selection');
        $sessionStore = new HatfieldSessionStore(new AppConfig(tui: new TuiConfig(theme: 'default'), logging: new LoggingConfig(), cwd: $this->directory), $this->createStub(EntityManagerInterface::class), new EventDispatcher());
        $this->store = HistoryEventStoreFactory::create($sessionStore, [
            RunEvent::forAppend('selection', 0, 'run_started', ['payload' => ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'First prompt']]]]]]),
            RunEvent::forAppend('selection', 100, 'turn_advanced', ['turn_no' => 100]),
            RunEvent::forAppend('selection', 80, 'turn_advanced', ['turn_no' => 80]),
            RunEvent::forAppend('selection', 80, 'agent_command_applied', ['kind' => 'follow_up', 'text' => str_repeat('Middle prompt\n', 100)]),
            RunEvent::forAppend('selection', 4, 'turn_advanced', ['turn_no' => 4]),
            RunEvent::forAppend('selection', 4, 'agent_command_applied', ['kind' => 'steer', 'text' => 'Last prompt']),
            RunEvent::forAppend('selection', 3, 'turn_advanced', ['turn_no' => 3]),
        ]);
        $this->context = new TestActiveRunContext();
        $this->context->loadRecovered(new RunState(runId: 'selection', status: RunStatus::Running, version: 1, turnNo: 3, lastSeq: 7, model: 'test-model'));
        $this->commands = new TestMessageBus();
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->directory);
        parent::tearDown();
    }

    public function testSelectFirstPromptPositionsBeforeItAndReturnsEditorText(): void
    {
        $this->assertSelection(100, 0, 'First prompt');
    }

    public function testSelectMiddlePromptPositionsAtInternalPredecessorAndReturnsFullEditorText(): void
    {
        $this->assertSelection(4, 80, str_repeat('Middle prompt\n', 100));
    }

    public function testSelectInternalRetainedTurnWithoutPromptIsRejected(): void
    {
        $rebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $rebuilder->expects($this->never())->method('rebuildAtPosition');
        try {
            $this->service($rebuilder)->selectPrompt('selection', 80, 'command');
            $this->fail('Internal anchors must not be selectable.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('not a selectable human prompt', $exception->getMessage());
        }
        $this->assertSame(7, $this->store->latestSequenceFor('selection'));
    }

    public function testSelectPromptRejectsDuplicateSequencesWithoutMutation(): void
    {
        $source = $this->store->historySource('selection');
        file_put_contents($source->path, json_encode((new EventPayloadNormalizer())->normalize('selection', 7, 3, 'history_position_set', ['position_turn_no' => 3]), \JSON_THROW_ON_ERROR)."\n", \FILE_APPEND);
        $before = file_get_contents($source->path);
        $rebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $rebuilder->expects($this->never())->method('rebuildAtPosition');
        try {
            $this->service($rebuilder)->selectPrompt('selection', 100, 'command');
            $this->fail('Duplicate sequence must refuse selection.');
        } catch (RunStateDuplicateSequenceReplayException $exception) {
            $this->assertStringContainsString('duplicate sequence', $exception->getMessage());
        }
        $this->assertSame($before, file_get_contents($source->path));
        $this->assertSame(3, $this->context->requireLoaded('selection')->turnNo);
        $this->assertSame([], $this->commands->messages);
    }

    private function assertSelection(int $target, int $predecessor, string $text): void
    {
        $rebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $rebuilder->expects($this->once())->method('rebuildAtPosition')->with($this->anything(), 'selection', $predecessor)
            ->willReturn(RunStateReplayResult::rebuilt(new RunState(runId: 'selection', status: RunStatus::Running)));
        $result = $this->service($rebuilder)->selectPrompt('selection', $target, 'command');
        $this->assertSame($predecessor, $result['rebuiltState']->turnNo);
        $this->assertSame($target, $result['selectedPromptTurnNo']);
        $this->assertSame($text, $result['editorPromptText']);
        $events = iterator_to_array($this->store->rangeFor('selection', 8, 8));
        $this->assertCount(1, $events);
        $this->assertSame('history_position_set', $events[0]->type);
        $this->assertSame($predecessor, $events[0]->payload['position_turn_no']);
        $this->assertSame(3, $events[0]->payload['previous_position_turn_no']);
        $this->assertSame($target, $events[0]->payload['selected_prompt_turn_no']);
        $this->assertSame('history_select', $events[0]->payload['reason']);
        $this->assertSame($result['rebuiltState'], $this->context->requireLoaded('selection'));
        $this->assertSame([], $this->commands->messages);
        $source = $this->store->historySource('selection');
        $page = (new RunHistoryIndex(new LockFactory(new InMemoryStore()), new NullLogger()))->promptPage($source->log, $source->path, 'selection', after: 0);
        $this->assertSame([100, 4, 3], array_column($page['rows'], 'turn_no'), 'Selection retains forward history.');
    }

    private function service(RunStateRebuilderInterface $rebuilder): HistorySelectionService
    {
        $locks = new LockFactory(new InMemoryStore());
        $logger = new NullLogger();

        return new HistorySelectionService($this->store, $rebuilder, $this->context, new RunLockManager($locks), $logger, new RunHistoryIndex($locks, $logger), new RunCommit(
            activeRunContext: $this->context,
            eventStore: $this->store,
            logger: $logger,
            finalizer: TestTransitionFinalizerFactory::create($this->store, new StepDispatcher($this->commands, new TestMessageBus(), new TestLogger(), events: new EventDispatcher())),
        ));
    }
}
