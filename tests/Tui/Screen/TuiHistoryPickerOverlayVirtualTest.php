<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryEntryView;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryView;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Listener\HistoryCommandHandler;
use Ineersa\Tui\Picker\HistoryPickerController;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Native picker input selects canonical history and restores the transcript and editor.
 */
final class TuiHistoryPickerOverlayVirtualTest extends PerMethodIsolatedKernelTestCase
{
    #[DataProvider('coldHistorySelections')]
    public function testColdAttachRestoresFollowingPromptIntoANewEditor(int $turn, string $role, bool $mutate): void
    {
        $container = static::getContainer();
        $run = $container->get(\Ineersa\CodingAgent\Session\HatfieldSessionStore::class)->createSession('cold editor selection');
        $store = $container->get(\Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface::class);
        $next = str_repeat('Full canonical U2 to edit. ', 24);
        $message = static fn (string $role, string $text): array => ['role' => $role, 'content' => [['type' => 'text', 'text' => $text]]];
        \Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder::appendMany($store, [
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 0, 'run_started', ['payload' => ['messages' => [$message('user', 'U1')]]]),
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'one']),
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 1, 'llm_step_completed', ['step_id' => 'one', 'assistant_message' => $message('assistant', 'A1')]),
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 1, 'agent_end', ['reason' => 'completed']),
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 1, 'agent_command_applied', ['kind' => 'follow_up', 'message' => $message('user', $next)]),
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 2, 'turn_advanced', ['turn_no' => 2, 'step_id' => 'two']),
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 2, 'llm_step_completed', ['step_id' => 'two', 'assistant_message' => $message('assistant', 'A2')]),
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 2, 'agent_end', ['reason' => 'completed']),
        ]);
        [$oldTui, $oldState, $oldTicks, $oldClient] = $this->virtualSession($run);
        $oldState->handle = new \Ineersa\CodingAgent\Runtime\Contract\RunHandle($run, 'attached');
        $oldState->lastSeq = $store->latestSequenceFor($run);
        $oldState->sessionReady = true;
        $oldClient->send($run, new \Ineersa\CodingAgent\Runtime\Contract\UserCommand(type: 'select_history_turn', payload: ['turn_no' => $turn, 'role' => $role]));
        $this->consumeMaintenance();
        $oldTicks->dispatch(new \Symfony\Component\Tui\Event\TickEvent());
        $expected = 'user' === $role ? 'U1' : (1 === $turn ? $next : '');
        $this->assertSame($expected, $oldTui->screen()->editorText());
        if ($mutate) {
            $container->get(\Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor::class)->process('test', new \Ineersa\AgentCore\Domain\Message\ApplyCommand($run, 1, 'edit-next', 1, 'edit-next', 'follow_up', ['message' => $message('user', 'U2_EDITED')]));
            $this->assertContains('history_tail_discarded', array_column($store->allFor($run), 'type'));
            $expected = 'LOCAL_DRAFT';
        }
        // Release the old execution owner and every client/TUI object. The new
        // attach must derive editor text from canonical storage, not the reply.
        $container->get(\Ineersa\AgentCore\Contract\ActiveRunContextInterface::class)->release($run);
        iterator_to_array($container->get(\Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink::class)->drain($run));
        unset($oldTui, $oldState, $oldTicks, $oldClient);
        gc_collect_cycles();
        [$freshTui, $freshState, $freshTicks, $freshClient] = $this->virtualSession($run);
        $freshTui->screen()->promptEditor()->replaceText('LOCAL_DRAFT');
        $freshState->handle = $freshClient->attach($run);
        $freshState->beginBootstrapAttach();
        $this->consumeMaintenance();
        for ($i = 0; $i < 8 && !$freshState->sessionReady; ++$i) {
            $freshState->lastPoll = 0.0;
            $freshTicks->dispatch(new \Symfony\Component\Tui\Event\TickEvent());
        }
        $this->assertTrue($freshState->bootstrapMounted, $freshState->bootstrapError ?? $freshState->lastRuntimePollError);
        $this->assertTrue($freshState->sessionReady);
        if ('assistant' === $role) {
            $this->assertContains('A1', array_column($freshState->transcript, 'text'));
        }
        if (1 === $turn) {
            $this->assertNotContains($next, array_column($freshState->transcript, 'text'));
            $this->assertNotContains('A2', array_column($freshState->transcript, 'text'));
        }
        $this->assertSame($expected, $freshTui->screen()->editorText(), 'A fresh owner/client/editor must restore only the current canonical selection after the verified bootstrap mount.');
        $freshClient->cancelBootstrap($run);
    }

    public static function coldHistorySelections(): iterable
    {
        yield 'following prompt' => [1, 'assistant', false];
        yield 'last answer clears' => [2, 'assistant', false];
        yield 'user selection' => [1, 'user', false];
        yield 'edited branch does not resurrect old prompt' => [1, 'assistant', true];
    }

    #[DataProvider('delayedBootstrapDrafts')]
    public function testNativeInputAfterAttachKeepsDraftWhenBootstrapMounts(int $turn, string $keys, string $expectedDraft, bool $pressEnter): void
    {
        $container = static::getContainer();
        $run = $container->get(\Ineersa\CodingAgent\Session\HatfieldSessionStore::class)->createSession('delayed bootstrap draft');
        $store = $container->get(\Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface::class);
        $next = str_repeat('Full canonical U2 to edit. ', 24);
        $message = static fn (string $role, string $text): array => ['role' => $role, 'content' => [['type' => 'text', 'text' => $text]]];
        \Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder::appendMany($store, [
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 0, 'run_started', ['payload' => ['messages' => [$message('user', 'U1')]]]),
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'one']),
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 1, 'llm_step_completed', ['step_id' => 'one', 'assistant_message' => $message('assistant', 'A1')]),
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 1, 'agent_end', ['reason' => 'completed']),
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 1, 'agent_command_applied', ['kind' => 'follow_up', 'message' => $message('user', $next)]),
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 2, 'turn_advanced', ['turn_no' => 2, 'step_id' => 'two']),
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 2, 'llm_step_completed', ['step_id' => 'two', 'assistant_message' => $message('assistant', 'A2')]),
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 2, 'agent_end', ['reason' => 'completed']),
        ]);
        [$oldTui, $oldState, $oldTicks, $oldClient] = $this->virtualSession($run);
        $oldState->handle = new \Ineersa\CodingAgent\Runtime\Contract\RunHandle($run, 'attached');
        $oldState->lastSeq = $store->latestSequenceFor($run);
        $oldState->sessionReady = true;
        $oldClient->send($run, new \Ineersa\CodingAgent\Runtime\Contract\UserCommand(type: 'select_history_turn', payload: ['turn_no' => $turn, 'role' => 'assistant']));
        $this->consumeMaintenance();
        $oldTicks->dispatch(new \Symfony\Component\Tui\Event\TickEvent());
        $this->assertSame(1 === $turn ? $next : '', $oldTui->screen()->editorText());
        $container->get(\Ineersa\AgentCore\Contract\ActiveRunContextInterface::class)->release($run);
        iterator_to_array($container->get(\Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink::class)->drain($run));
        unset($oldTui, $oldState, $oldTicks, $oldClient);
        gc_collect_cycles();

        // Hold sealed bootstrap frames until after native editor input so the
        // attach start, not the first poll, is the draft baseline.
        [$freshTui, $freshState, $freshTicks, $freshClient] = $this->virtualSession($run, withSubmit: true);
        $freshTui->startInputLoop();
        $freshState->handle = $freshClient->attach($run);
        $freshState->beginBootstrapAttach();
        $this->assertFalse($freshState->sessionReady);
        $this->assertFalse($freshState->bootstrapMounted);
        $freshTui->sendInput($keys);
        if ($pressEnter) {
            $freshTui->sendInput("\r");
            $this->assertFalse($freshState->sessionReady);
            $this->assertSame($expectedDraft, $freshTui->screen()->editorText());
            $sent = $container->get('messenger.transport.run_control')->getSent();
            foreach ($sent as $envelope) {
                $this->assertNotInstanceOf(\Ineersa\AgentCore\Domain\Message\ApplyCommand::class, $envelope->getMessage());
            }
            $this->assertStringContainsString('Restoring session', $freshTui->screen()->workingMessage());
        }
        $this->assertSame($expectedDraft, $freshTui->screen()->editorText());
        $this->consumeMaintenance();
        for ($i = 0; $i < 8 && !$freshState->sessionReady; ++$i) {
            $freshState->lastPoll = 0.0;
            $freshTicks->dispatch(new \Symfony\Component\Tui\Event\TickEvent());
        }
        $this->assertTrue($freshState->bootstrapMounted, $freshState->bootstrapError ?? $freshState->lastRuntimePollError);
        $this->assertTrue($freshState->sessionReady);
        $freshState->lastPoll = 0.0;
        $freshTicks->dispatch(new \Symfony\Component\Tui\Event\TickEvent());
        $this->assertSame($expectedDraft, $freshTui->screen()->editorText(), 'Native input after attach must survive bootstrap editor restore or clear.');
        $this->assertNull($freshState->pendingEditorPromptText);
        $freshTui->stopInputLoop();
        $freshClient->cancelBootstrap($run);
    }

    public static function delayedBootstrapDrafts(): iterable
    {
        yield 'following prompt overwrite refused' => [1, 'typed-after-attach', 'typed-after-attach', false];
        yield 'last answer clear refused' => [2, 'keep-draft', 'keep-draft', false];
        yield 'enter while restoring keeps draft' => [1, 'queued-locally', 'queued-locally', true];
        yield 'typed then deleted still refuses restore' => [1, "x\x7f", '', false];
    }

    public function testAssistantSelectionPrefillsNextPromptAndRetainsAnswerThroughColdReplayAndEditing(): void
    {
        $container = static::getContainer();
        $sessions = $container->get(\Ineersa\CodingAgent\Session\HatfieldSessionStore::class);
        $run = $sessions->createSession('answer history');
        $store = $container->get(\Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface::class);
        $nextPrompt = str_repeat('U2: edit this next prompt. ', 20);
        $message = static fn (string $role, string $text): array => ['role' => $role, 'content' => [['type' => 'text', 'text' => $text]]];
        $parts = [['type' => 'thinking', 'text' => 'THINKING_NOT_A_ROW']];
        foreach (['commentary' => 'COMMENTARY_NOT_A_ROW', 'final_answer' => 'A1_FINAL_ANSWER'] as $phase => $text) {
            $signed = \Symfony\AI\Platform\Bridge\OpenAIChatGPT\MessageItem::toText(['id' => 'msg_'.$phase, 'type' => 'message', 'role' => 'assistant', 'phase' => $phase, 'content' => [['type' => 'output_text', 'text' => $text]]]);
            $parts[] = ['type' => 'text', 'text' => $text, 'text_signature' => $signed->getSignature()];
        }
        \Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder::appendMany($store, [
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 0, 'run_started', ['payload' => ['metadata' => ['model' => 'test-model'], 'messages' => [$message('user', 'U1_FIRST_PROMPT')]]]),
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 100, 'turn_advanced', ['turn_no' => 100, 'step_id' => 'one']),
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 100, 'llm_step_completed', ['step_id' => 'one', 'assistant_message' => ['role' => 'assistant', 'content' => $parts]]),
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 100, 'agent_end', ['reason' => 'completed']),
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 100, 'agent_command_applied', ['kind' => 'follow_up', 'message' => $message('user', $nextPrompt)]),
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 80, 'turn_advanced', ['turn_no' => 80, 'step_id' => 'two']),
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 80, 'llm_step_completed', ['step_id' => 'two', 'assistant_message' => $message('assistant', 'A2_LAST_ANSWER')]),
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 80, 'agent_end', ['reason' => 'completed']),
        ]);
        $harness = new VirtualTuiHarness(sessionId: $run);
        $state = new TuiSessionState($run);
        $state->handle = new \Ineersa\CodingAgent\Runtime\Contract\RunHandle($run, 'attached');
        $state->lastSeq = $store->latestSequenceFor($run);
        $state->activity = \Ineersa\Tui\Runtime\RunActivityStateEnum::Completed;
        $state->sessionReady = true;
        $client = $container->get(\Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient::class);
        $services = $container->get(\Ineersa\Tui\Application\TuiSessionCompositionFactory::class)->create($harness->tui(), $harness->screen(), $state, $client);
        $history = $container->get(HistoryProviderInterface::class);
        $ticks = new \Ineersa\Tui\Runtime\TuiTickDispatcher();
        $context = new \Ineersa\Tui\Runtime\TuiRuntimeContext($harness->tui(), $client, $state, $harness->screen(), $sessions, $ticks, $services->switch, new \Ineersa\Tui\Runtime\TuiSessionLifecycleDispatcher(), $history, $services);
        (new \Ineersa\Tui\Listener\TickPollListener(new \Ineersa\Tui\Listener\RuntimeQuestionEventHandler()))->register($context);
        $consumeSelection = function (int $turn, string $role) use ($container, $ticks, $state): void {
            $sent = $container->get('messenger.transport.run_control')->getSent();
            $envelope = $sent[array_key_last($sent)];
            $command = $envelope->getMessage();
            $this->assertInstanceOf(\Ineersa\CodingAgent\Application\Message\SelectHistoryEntry::class, $command);
            $this->assertSame($turn, $command->turnNo);
            $this->assertSame($role, $command->role);
            $handled = $container->get('agent.command.bus')->dispatch($envelope->with(new \Symfony\Component\Messenger\Stamp\ReceivedStamp('run_control')));
            $reply = $handled->last(\Symfony\Component\Messenger\Stamp\HandledStamp::class)->getResult();
            $this->assertSame('run.history_position_changed', $reply->type, json_encode($reply->payload, \JSON_THROW_ON_ERROR));
            // Drive an eligible tick without depending on the 15ms poll throttle.
            $state->lastPoll = 0.0;
            $ticks->dispatch(new \Symfony\Component\Tui\Event\TickEvent());
        };
        (new HistoryCommandHandler($services->historyPicker))->handle(new SlashCommand('history', '', '/history'));
        $pickerText = $harness->plainScreenText();
        $this->assertStringContainsString('user: U1_FIRST_PROMPT', $pickerText);
        $this->assertStringContainsString('assistant: A1_FINAL_ANSWER', $pickerText);
        $this->assertStringContainsString('◉ assistant: A2_LAST_ANSWER', $pickerText);
        $this->assertStringNotContainsString('◉ user:', $pickerText, 'Shared turn numbers must not mark both entries as the selected position.');
        $this->assertStringNotContainsString('COMMENTARY_NOT_A_ROW', $pickerText);
        $this->assertStringNotContainsString('THINKING_NOT_A_ROW', $pickerText);
        // The initial selected row is A2. Native Up, Up, Enter selects A1.
        $harness->tui()->handleInput("\x1b[A");
        $harness->tui()->handleInput("\x1b[A");
        $harness->tui()->handleInput("\n");
        $consumeSelection(100, 'assistant');
        $this->assertSame($nextPrompt, $harness->screen()->editorText(), 'Editor gets full canonical U2, not its bounded preview or A1.');
        $texts = array_column($state->transcript, 'text');
        $this->assertContains('A1_FINAL_ANSWER', $texts);
        $this->assertNotContains('A2_LAST_ANSWER', $texts);
        $this->assertNotContains($nextPrompt, $texts);
        $registry = $container->get(\Ineersa\AgentCore\Contract\ActiveRunContextInterface::class);
        $selected = $registry->requireLoaded($run);
        $this->assertSame(100, $selected->turnNo);
        $replay = $container->get(\Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface::class);
        $cold = $replay->rebuildIfStale(\Ineersa\AgentCore\Domain\Run\RunState::queued($run), $run)->rebuiltState;
        $this->assertEquals($selected->messages, $cold->messages);
        $body = json_encode(array_map(static fn ($entry): array => $entry->toArray(), $cold->messages), \JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('A1_FINAL_ANSWER', $body);
        $this->assertStringNotContainsString('U2:', $body);
        $this->assertStringNotContainsString('A2_LAST_ANSWER', $body);
        $this->assertCount(4, $history->forSession($run, after: 0)->entries, 'Forward entries remain selectable after cold resume.');
        // Redo to A2 through the same picker; no following user prompt clears the editor.
        $services->historyPicker->open(after: 0);
        $harness->tui()->handleInput("\x1b[B");
        $harness->tui()->handleInput("\n");
        $consumeSelection(80, 'assistant');
        $this->assertSame('', $harness->screen()->editorText());
        $this->assertContains('A2_LAST_ANSWER', array_column($state->transcript, 'text'));
        // Selecting U1 still positions before U1 and prefills U1.
        $services->historyPicker->open(after: 0);
        for ($i = 0; $i < 3; ++$i) {
            $harness->tui()->handleInput("\x1b[A");
        }
        $harness->tui()->handleInput("\n");
        $consumeSelection(100, 'user');
        $this->assertSame('U1_FIRST_PROMPT', $harness->screen()->editorText());
        $this->assertSame([], $state->transcript);
        // Return to A1 before editing U2. Only mutation discards the forward branch.
        $services->historyPicker->open(after: 0);
        $harness->tui()->handleInput("\x1b[B");
        $harness->tui()->handleInput("\n");
        $consumeSelection(100, 'assistant');
        $processor = $container->get(\Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor::class);
        $processor->process('test', new \Ineersa\AgentCore\Domain\Message\ApplyCommand($run, 100, 'edit-next', 1, 'edit-next', 'follow_up', ['message' => $message('user', 'U2_EDITED')]));
        $processor->process('test', new \Ineersa\AgentCore\Domain\Message\AdvanceRun($run, 100, 'advance-edited', 1, 'advance-edited'));
        $sent = $container->get('messenger.transport.llm')->getSent();
        $this->assertCount(1, $sent);
        $request = $sent[0]->getMessage();
        $body = json_encode(array_map(static fn ($entry): array => $entry->toArray(), $request->messages), \JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('A1_FINAL_ANSWER', $body);
        $this->assertStringContainsString('U2_EDITED', $body);
        $this->assertStringNotContainsString('U2: edit', $body);
        $this->assertStringNotContainsString('A2_LAST_ANSWER', $body);
        $this->assertContains('history_tail_discarded', array_column($store->allFor($run), 'type'));
    }

    #[Test]
    public function testHistoryPickerRendersUserPromptsAndFinalAnswers(): void
    {
        $sessionId = 'history-overlay-session';
        $harness = new VirtualTuiHarness(sessionId: $sessionId);
        $provider = $this->createStub(HistoryProviderInterface::class);
        $provider->method('forSession')->willReturn($this->sampleHistory());
        $picker = new HistoryPickerController($harness->tui(), $harness->screen(), new TuiSessionState($sessionId), $provider, $this->createStub(TuiSessionSwitchServiceInterface::class));

        (new HistoryCommandHandler($picker))->handle(new SlashCommand('history', '', '/history'));

        $screen = $harness->plainScreenText();
        $this->assertSame(1, substr_count($screen, 'Session history — Enter to select position (Esc to close)'));
        $this->assertSame(1, substr_count($screen, 'hello'));
        $this->assertSame(1, substr_count($screen, 'Can you create file'));
        $this->assertSame(1, substr_count($screen, 'Done! Created file'));
        $this->assertStringContainsString('assistant: Done! Created file', $screen);
    }

    #[Test]
    public function testHistoryPickerRemountDoesNotDuplicateRows(): void
    {
        $sessionId = 'history-remount-session';
        $harness = new VirtualTuiHarness(sessionId: $sessionId);
        $provider = $this->createStub(HistoryProviderInterface::class);
        $provider->method('forSession')->willReturn($this->sampleHistory());
        $picker = new HistoryPickerController($harness->tui(), $harness->screen(), new TuiSessionState($sessionId), $provider, $this->createStub(TuiSessionSwitchServiceInterface::class));

        (new HistoryCommandHandler($picker))->handle(new SlashCommand('history', '', '/history'));
        $picker->closePicker();
        $picker->open();
        $screen = $harness->plainScreenText();
        $this->assertSame(1, substr_count($screen, 'Session history — Enter to select position (Esc to close)'));
        $this->assertSame(1, substr_count($screen, 'hello'));
    }

    #[Test]
    public function testIndexedPagesNavigateBothDirectionsAndSelectSparsePromptsThroughNativeInput(): void
    {
        $directory = \Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation::createProjectTempDir('history-picker-pages');
        try {
            $sessionId = 'paged-history';
            $sessionStore = new \Ineersa\CodingAgent\Session\HatfieldSessionStore(
                new \Ineersa\CodingAgent\Config\AppConfig(tui: new \Ineersa\CodingAgent\Config\TuiConfig(theme: 'default'), logging: new \Ineersa\CodingAgent\Config\LoggingConfig(), cwd: $directory),
                $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
                new \Symfony\Component\EventDispatcher\EventDispatcher(), lockFactory: new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\FlockStore()));
            $events = [];
            for ($i = 70; $i >= 1; --$i) {
                $events[] = \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($sessionId, $i, 'agent_command_applied', ['kind' => 'follow_up', 'text' => 'Prompt number '.$i]);
                $events[] = \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($sessionId, $i, 'turn_advanced', ['turn_no' => $i]);
            }
            $store = \Ineersa\CodingAgent\Tests\Support\HistoryEventStoreFactory::create($sessionStore, $events);
            $provider = new \Ineersa\CodingAgent\Session\SessionHistoryProvider($store, new \Ineersa\CodingAgent\Session\RunHistoryIndex(new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\FlockStore()), new \Psr\Log\NullLogger()));
            $harness = new VirtualTuiHarness(sessionId: $sessionId);
            $switcher = $this->createMock(TuiSessionSwitchServiceInterface::class);
            $switcher->expects($this->once())->method('selectHistoryTurn')->with(33);
            $picker = new HistoryPickerController($harness->tui(), $harness->screen(), new TuiSessionState($sessionId), $provider, $switcher);
            (new HistoryCommandHandler($picker))->handle(new SlashCommand('history', '', '/history'));
            $this->assertStringContainsString('Prompt number 1', $harness->plainScreenText());
            for ($i = 0; $i < 32; ++$i) {
                $harness->tui()->handleInput("\x1b[A");
            }
            $this->assertStringContainsString('Older entries...', $harness->plainScreenText());
            $harness->tui()->handleInput("\n");
            $this->assertStringContainsString('Prompt number 33', $harness->plainScreenText());
            $this->assertStringContainsString('Newer entries...', $harness->plainScreenText());
            $harness->tui()->handleInput("\x1b[B");
            $harness->tui()->handleInput("\n");
            $this->assertStringContainsString('Prompt number 1', $harness->plainScreenText());
            for ($i = 0; $i < 32; ++$i) {
                $harness->tui()->handleInput("\x1b[A");
            }
            $harness->tui()->handleInput("\n");
            $harness->tui()->handleInput("\n");
            $this->assertStringNotContainsString('Session history', $harness->plainScreenText());
        } finally {
            \Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    protected function afterKernelBoot(): void
    {
        $container = static::getContainer();
        // This test drives the real in-process client. Maintenance replies must use
        // its in-memory sink rather than the controller consumer's stdout sink.
        $container->set(\Ineersa\CodingAgent\Application\Pipeline\SessionMaintenanceHandler::class, new \Ineersa\CodingAgent\Application\Pipeline\SessionMaintenanceHandler(
            $container->get(\Ineersa\AgentCore\Contract\History\HistorySelectionServiceInterface::class),
            $container->get(\Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface::class),
            $container->get(\Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink::class),
            $container->get(\Ineersa\CodingAgent\Runtime\Stream\StdoutRuntimeEventSink::class),
            false,
            new \Psr\Log\NullLogger(),
            $container->get(\Ineersa\AgentCore\Contract\ActiveRunContextInterface::class),
            $container->get(\Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor::class),
            $container->get(\Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware::class),
            $container->get(\Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery::class),
            $container->get(\Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository::class),
            $container->get(\Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapProducer::class),
            $container->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class),
        ));
    }

    /** @return array{VirtualTuiHarness, TuiSessionState, \Ineersa\Tui\Runtime\TuiTickDispatcher, \Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient} */
    private function virtualSession(string $run, bool $withSubmit = false): array
    {
        $container = static::getContainer();
        $harness = new VirtualTuiHarness(sessionId: $run);
        $state = new TuiSessionState($run);
        $client = clone $container->get(\Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient::class);
        $services = $container->get(\Ineersa\Tui\Application\TuiSessionCompositionFactory::class)->create($harness->tui(), $harness->screen(), $state, $client);
        $ticks = new \Ineersa\Tui\Runtime\TuiTickDispatcher();
        $context = new \Ineersa\Tui\Runtime\TuiRuntimeContext($harness->tui(), $client, $state, $harness->screen(), $container->get(\Ineersa\CodingAgent\Session\HatfieldSessionStore::class), $ticks, $services->switch, new \Ineersa\Tui\Runtime\TuiSessionLifecycleDispatcher(), $container->get(HistoryProviderInterface::class), $services);
        (new \Ineersa\Tui\Listener\TickPollListener(new \Ineersa\Tui\Listener\RuntimeQuestionEventHandler()))->register($context);
        if ($withSubmit) {
            (new \Ineersa\Tui\Listener\SubmitListener(
                sessionStore: $container->get(\Ineersa\CodingAgent\Session\HatfieldSessionStore::class),
                blockFactory: new \Ineersa\Tui\Transcript\TranscriptBlockFactory(),
                subagentLiveInputPolicy: new \Ineersa\Tui\Command\SubagentLiveInputPolicy(),
                logger: new \Psr\Log\NullLogger(),
                pastedImageSubmissionService: new \Ineersa\Tui\ImagePaste\PastedImageSubmissionService(
                    new \Ineersa\Tui\ImagePaste\PastedImageValidationService(new \Ineersa\CodingAgent\Config\ImageToolConfig(), new \Ineersa\AgentCore\Tests\Support\TestLogger()),
                    $container->get(\Ineersa\CodingAgent\Session\HatfieldSessionStore::class),
                    new \Ineersa\CodingAgent\Config\AppConfig(
                        tui: new \Ineersa\CodingAgent\Config\TuiConfig(theme: 'default'),
                        logging: new \Ineersa\CodingAgent\Config\LoggingConfig(),
                        sessions: new \Ineersa\CodingAgent\Config\SessionsConfig(),
                        cwd: getcwd() ?: '/tmp',
                    ),
                    new \Ineersa\Tui\Transcript\TranscriptBlockFactory(),
                    new \Ineersa\AgentCore\Tests\Support\TestLogger(),
                ),
            ))->register($context);
        }

        return [$harness, $state, $ticks, $client];
    }

    private function consumeMaintenance(): void
    {
        $container = static::getContainer();
        $sent = $container->get('messenger.transport.run_control')->getSent();
        $handled = $container->get('agent.command.bus')->dispatch($sent[array_key_last($sent)]->with(new \Symfony\Component\Messenger\Stamp\ReceivedStamp('run_control')));
        $reply = $handled->last(\Symfony\Component\Messenger\Stamp\HandledStamp::class)->getResult();
        if ($sent[array_key_last($sent)]->getMessage() instanceof \Ineersa\CodingAgent\Application\Message\AttachRun) {
            $this->assertNull($reply);
        } else {
            $this->assertSame('run.history_position_changed', $reply->type, json_encode($reply->payload, \JSON_THROW_ON_ERROR));
        }
    }

    private function sampleHistory(): HistoryView
    {
        return new HistoryView(
            entries: [
                new HistoryEntryView(1, 'hello', 1),
                new HistoryEntryView(2, 'Can you create file', 2),
                new HistoryEntryView(2, 'Done! Created file', 2, 'assistant'),
            ],
            selectedAnchor: 2,
        );
    }
}
