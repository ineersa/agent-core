<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Runtime;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapDescriptorDTO;
use Ineersa\CodingAgent\Session\Replay\SessionResumeMetadataProjection;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\RuntimeEventPoller;
use Ineersa\Tui\Runtime\TuiRuntimeEventApplier;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

#[AllowMockObjectsWithoutExpectations]
final class TuiBootstrapAssemblerTest extends IsolatedKernelTestCase
{
    use \Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;

    private TestLogger $logger;

    // This fixture owns run()/stop(). Other TUI tests suspend the shared driver,
    // which remains running after their UI returns and cannot be swapped safely.
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStoredToolQuestionSurvivesBootstrapUntilLiveUiDelivery(): void
    {
        $container = static::getContainer();
        $run = $container->get(\Ineersa\CodingAgent\Session\HatfieldSessionStore::class)->createSession('Operational question bootstrap');
        $events = $container->get(\Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface::class);
        \Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder::appendMany($events, [
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 0, 'run_started', ['payload' => [
                'metadata' => ['model' => 'llama_cpp_test/test', 'session' => []],
                'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Original conversation']]]],
            ]]),
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 1, 'turn_advanced', ['turn_no' => 1]),
            \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 1, 'agent_end', ['reason' => 'completed']),
        ]);
        [$state, $harness, $uiPoller] = $this->scope($run);
        $questions = $container->get(\Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface::class);
        $emitter = new \Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter($this->logger);
        [$writer, $reader] = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        (new \ReflectionProperty($emitter, 'stdout'))->setValue($emitter, $writer);
        stream_set_blocking($reader, false);
        $delivery = new \Ineersa\CodingAgent\Runtime\Controller\SessionBootstrapDelivery(
            $container->get(\Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapSpoolStore::class),
            $container->get(\Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapTransfer::class), $emitter, $this->logger);
        $poller = new \Ineersa\CodingAgent\Runtime\Controller\ToolQuestionPoller($questions, $emitter, $this->logger);
        $poll = new \ReflectionMethod($poller, 'poll');
        $watchers = [];
        $hits = [];
        try {
            $delivery->begin($run, 'cancelled-request');
            $questions->create(\Ineersa\CodingAgent\Entity\ToolQuestion::create('bootstrap-question', $run, 'bash-call', 'bash',
                12345, '/tmp/owned-question.log', 'echo test', 'Move this command to the background?'));
            $assertPending = function () use ($questions, $poll, $poller, &$hits): void {
                $poll->invoke($poller);
                $pending = $questions->findUnemittedPendingQuestions();
                $this->assertCount(1, $pending);
                $this->assertSame('bootstrap-question', $pending[0]->requestId);
                $this->assertNull($pending[0]->emittedAt);
                $this->assertNull($questions->pollAnswer('bootstrap-question'));
                $this->assertSame([], $hits);
            };
            $assertPending();
            $delivery->cancel();
            $assertPending();
            $delivery->begin($run, 'failed-request');
            $delivery->expect('failed-request');
            // A malformed descriptor exercises the real failure/detach filter.
            $emitter->emit(new RuntimeEvent('bootstrap.available', $run, 0, ['request_id' => 'failed-request']));
            $assertPending();
            $delivery->begin($run, 'superseded-request');
            $delivery->begin($run, 'request');
            $delivery->expect('request');
            $assertPending();

            $producer = $container->get(\Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapProducer::class);
            $producer->prepare($run);
            $cut = $producer->seal();
            $this->assertSame(3, $cut->canonicalSeq, 'Creating the operational question did not append to JSONL.');
            $emitter->emit(new RuntimeEvent('bootstrap.available', $run, 0, $cut->toArray() + ['request_id' => 'request']));
            $emitter->emit(new RuntimeEvent('assistant.text_delta', $run, 0, ['text' => 'Drop transient text']));
            $assertPending();
            \Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder::appendMany($events, [
                \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 1, 'llm_step_completed', [
                    'step_id' => 'step', 'stop_reason' => 'stop',
                    'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Committed while transferring']]],
                ]),
            ]);
            $suffix = iterator_to_array($container->get(\Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapTransfer::class)->catchUp($cut));
            $this->assertNotContains('tool_question.requested', array_column($suffix, 'type'), 'Operational questions have no canonical suffix event.');
            $this->assertGreaterThan($cut->canonicalSeq, $suffix[array_key_last($suffix)]->payload['canonical_seq']);

            $incoming = [];
            $client = $this->createMock(AgentSessionClient::class);
            $client->method('events')->willReturnCallback(static function () use (&$incoming): array {
                $batch = $incoming;
                $incoming = [];

                return $batch;
            });
            $client->expects($this->once())->method('acknowledgeBootstrap')->with($cut->toArray())->willReturnCallback(
                static function (array $ack) use ($delivery, $assertPending): void {
                    $assertPending();
                    $delivery->acknowledge(SessionBootstrapDescriptorDTO::fromArray($ack));
                    $assertPending();
                });
            $buffer = '';
            $types = [];
            $watchers[] = \Revolt\EventLoop::onReadable($reader, function () use ($reader, &$buffer, &$incoming, &$types, &$hits, $state, $client, $uiPoller, $harness): void {
                $buffer .= fread($reader, 65536);
                while (false !== ($newline = strpos($buffer, "\n"))) {
                    $event = \Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec::decodeEvent(substr($buffer, 0, $newline));
                    $buffer = substr($buffer, $newline + 1);
                    $types[] = $event->type;
                    if ('bootstrap.suffix' === $event->type) {
                        // This controlled sink unwraps the fixture's single-frame
                        // record before invoking the ordinary TUI polling boundary.
                        $this->assertTrue($event->payload['last']);
                        $event = \Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec::decodeEvent(base64_decode($event->payload['data'], true));
                    }
                    $incoming[] = $event;
                }
                $state->lastPoll = 0;
                $changes = $uiPoller->poll($state, $client,
                    onToolQuestionRequested: function (RuntimeEvent $question) use ($state, &$hits): void {
                        $this->assertTrue($state->sessionReady);
                        $hits[] = $question;
                    },
                    onBootstrapMounted: function () use ($state, $harness): void {
                        $this->assertFalse($state->sessionReady);
                        $harness->screen()->setTranscriptBlocks($state->transcript);
                    });
                if (null !== $changes) {
                    $harness->screen()->applyTranscriptChangeSet($changes);
                }
                $this->assertNull($state->bootstrapError);
                if ([] !== $hits) {
                    \Revolt\EventLoop::getDriver()->stop();
                }
            });
            $watchers[] = \Revolt\EventLoop::delay(3, static function (): void { throw new \RuntimeException('Question bootstrap never reached live delivery.'); });
            $before = \Revolt\EventLoop::getIdentifiers();
            $poller->startPollLoop();
            $watchers = array_merge($watchers, array_diff(\Revolt\EventLoop::getIdentifiers(), $before));
            \Revolt\EventLoop::run();

            $this->assertTrue($state->sessionReady);
            $this->assertCount(1, $hits);
            $this->assertSame('bootstrap-question', $hits[0]->payload['request_id']);
            $this->assertSame(0, $hits[0]->seq);
            $this->assertNotContains('assistant.text_delta', $types);
            $this->assertLessThan(array_search('tool_question.requested', $types, true), array_search('session.ready', $types, true));
            $this->assertContains('bootstrap.suffix', $types);
            $this->assertStringContainsString('Committed while transferring', $harness->plainScreenText());
            $this->assertGreaterThan($cut->canonicalSeq, $state->lastSeq);
            $pending = $questions->findPendingQuestionsForRun($run);
            $this->assertNotNull($pending[0]->emittedAt);
            $poll->invoke($poller);
            $poll->invoke($poller);
            $this->assertSame('', stream_get_contents($reader), 'Subsequent polls cannot redeliver the acknowledged question.');
            $container->get(\Ineersa\CodingAgent\Runtime\Controller\CommandHandler\AnswerToolQuestionHandler::class)(
                new \Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent(
                    new \Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand('answer', 'answer_tool_question', $run,
                        ['request_id' => 'bootstrap-question', 'answer' => true]), $emitter->emit(...)));
            $this->assertTrue($questions->pollAnswer('bootstrap-question'), 'The existing worker wait predicate now resolves.');
        } finally {
            foreach ($watchers as $watcher) {
                \Revolt\EventLoop::cancel($watcher);
            }
            $delivery->cancel();
            $emitter->shutdown();
            fclose($writer);
            fclose($reader);
        }
    }

    #[DataProvider('recoveryOrigins')]
    public function testOwnedPipeRestartRemountsThroughProductionPolling(bool $attached): void
    {
        [$state, $harness, $poller] = $this->scope();
        [$client, $directory] = $this->protocolClient($state->sessionId);
        $mounts = 0;
        $localInput = [];
        $mount = static function () use ($state, $harness, &$mounts, &$localInput): void {
            ++$mounts;
            self::assertFalse($state->sessionReady);
            self::assertSame($localInput, $state->queuedFollowUps);
            $harness->screen()->setTranscriptBlocks($state->transcript);
            $harness->screen()->syncQueuedUserMessages($state->queuedUserMessages);
        };
        try {
            if ($attached) {
                $state->handle = $client->attach('42');
                $this->pumpUntil($client, $state, $harness, $poller, $mount, static fn (): bool => $state->sessionReady);
                $this->assertExactAck($directory);
            } else {
                $state->resuming = false;
                $state->replaceTranscript([]);
                $harness->screen()->setTranscriptBlocks([]);
                $state->handle = $client->start(new \Ineersa\CodingAgent\Runtime\Contract\StartRunRequest('Original prompt', '42'));
                $state->sessionReady = true;
                $this->assertNull($state->handle->bootstrapRequestId);
                $this->pumpUntil($client, $state, $harness, $poller, $mount,
                    static fn (): bool => str_contains($harness->plainScreenText(), 'Original prompt'));
            }
            $oldRequest = $state->handle->bootstrapRequestId;
            $oldMounts = $mounts;
            $localInput = $state->queuedFollowUps = ['Locally queued input'];
            $harness->screen()->promptEditor()->replaceText('Preserved editor draft');
            $client->send('42', new \Ineersa\CodingAgent\Runtime\Contract\UserCommand('follow_up', 'exit-controller'));
            $this->awaitPeerExit($client);
            // No explicit attach, fake client, or recovery callback: ordinary
            // production polling restarts the owned pipe and adopts its identity.
            $this->pumpUntil($client, $state, $harness, $poller, $mount,
                static fn (): bool => $state->sessionReady && $state->handle->bootstrapRequestId !== $oldRequest);
            $this->assertSame($oldMounts + 1, $mounts);
            $this->assertNotSame('unsolicited-request', $state->handle->bootstrapRequestId);
            $this->assertStringContainsString('Owner-projected answer generation 2', $harness->plainScreenText());
            $this->assertStringContainsString('Caught-up input generation 2', $harness->plainScreenText());
            $this->assertStringContainsString('Preserved editor draft', $harness->plainScreenText());
            $this->assertSame(['17' => 'Restored pending input'], $state->queuedUserMessages);
            $this->assertExactAck($directory);
            $client->send('42', new \Ineersa\CodingAgent\Runtime\Contract\UserCommand('follow_up', 'Live after recovery'));
            $this->pumpUntil($client, $state, $harness, $poller, $mount,
                static fn (): bool => str_contains($harness->plainScreenText(), 'Live after recovery'));
            $this->assertNull($state->bootstrapError);
        } finally {
            $this->closePeer($client);
            \Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public static function recoveryOrigins(): iterable
    {
        yield 'initially started handle has no attach identity' => [false];
        yield 'attached handle has the previous attach identity' => [true];
    }

    public function testResumeStartupPromptAttachesThenUsesOrdinarySubmissionOnceAfterReadiness(): void
    {
        $directory = \Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation::createProjectTempDir('resume-prompt');
        $client = null;
        try {
            $store = new \Ineersa\CodingAgent\Session\HatfieldSessionStore(
                new \Ineersa\CodingAgent\Config\AppConfig(new \Ineersa\CodingAgent\Config\TuiConfig(theme: 'default'), new \Ineersa\CodingAgent\Config\LoggingConfig(), cwd: $directory),
                static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class), new \Symfony\Component\EventDispatcher\EventDispatcher());
            $runId = $store->createSession('Original catalog prompt');
            $initializer = new \Ineersa\Tui\Application\SessionInitializer($store, new \Ineersa\Tui\Transcript\TranscriptBlockFactory());
            $state = $initializer->initialize($runId, new \Ineersa\CodingAgent\Runtime\Contract\StartRunRequest('Startup follow-up', cwd: $directory));
            $state->replaceTranscript($initializer->buildInitialTranscript($state));
            [, $harness, $poller] = $this->scope($runId);
            [$client] = $this->protocolClient($runId, $directory);
            // Exercise the production startup branch without running a real TTY.
            (new \ReflectionMethod(\Ineersa\Tui\Application\InteractiveMode::class, 'startOrResumeRun'))->invoke(
                static::getContainer()->get(\Ineersa\Tui\Application\InteractiveMode::class), $client, $state, $harness->screen());
            $this->assertFalse($state->sessionReady);
            $this->assertNotNull($state->handle->bootstrapRequestId);
            $services = $this->createSessionServices(tui: $harness->tui(), screen: $harness->screen(), state: $state, client: $client);
            $context = $this->buildTuiContext()->withTui($harness->tui())->withScreen($harness->screen())->withState($state)->withClient($client)->withSessionStore($store)->withSessionServices($services)->build();
            static::getContainer()->get(\Ineersa\Tui\Listener\SubmitListener::class)->register($context);
            $tick = static function () use ($context): void { $context->ticks->dispatch(new \Symfony\Component\Tui\Event\TickEvent()); };
            $tick();
            $this->assertSame('Startup follow-up', $state->pendingInitialPrompt);
            $mount = function () use ($state, $harness, $tick, $directory): void {
                $harness->screen()->setTranscriptBlocks($state->transcript);
                $this->assertFalse($state->sessionReady);
                $tick();
                $this->assertSame([], $this->submittedTexts($directory));
            };
            $this->pumpUntil($client, $state, $harness, $poller, $mount, static fn (): bool => $state->sessionReady);
            $this->assertSame('resume', $this->commands($directory)[0]['type']);
            $this->assertSame([], $this->submittedTexts($directory));
            $tick();
            $tick();
            $this->pumpUntil($client, $state, $harness, $poller, $mount,
                static fn (): bool => str_contains($harness->plainScreenText(), 'Startup follow-up'));
            $this->assertSame(['Startup follow-up'], $this->submittedTexts($directory));
            $this->assertNull($state->pendingInitialPrompt);
            $this->assertSame('Original catalog prompt', $store->findSession($runId)->prompt);
            $oldRequest = $state->handle->bootstrapRequestId;
            $client->send($runId, new \Ineersa\CodingAgent\Runtime\Contract\UserCommand('follow_up', 'exit-controller'));
            $this->awaitPeerExit($client);
            $this->pumpUntil($client, $state, $harness, $poller,
                static function () use ($state, $harness): void { $harness->screen()->setTranscriptBlocks($state->transcript); },
                static fn (): bool => $state->sessionReady && $state->handle->bootstrapRequestId !== $oldRequest);
            $tick();
            $tick();
            $this->assertSame(1, \count(array_filter($this->submittedTexts($directory), static fn (string $text): bool => 'Startup follow-up' === $text)));
            $harness->screen()->promptEditor()->replaceText('Ordinary user input');
            $harness->tui()->setFocus($harness->screen()->editorWidget());
            $harness->tui()->handleInput("\r");
            $this->pumpUntil($client, $state, $harness, $poller,
                static function () use ($state, $harness): void { $harness->screen()->setTranscriptBlocks($state->transcript); },
                static fn (): bool => str_contains($harness->plainScreenText(), 'Ordinary user input'));
            $this->assertContains('Ordinary user input', $this->submittedTexts($directory));
            $this->assertSame('Original catalog prompt', $store->findSession($runId)->prompt);
        } finally {
            if (null !== $client) {
                $this->closePeer($client);
            }
            \Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testValidatedMountPrecedesExactAcknowledgementAndDurableSuffixReadiness(): void
    {
        [$state, $harness, $poller] = $this->scope();
        [$cut, $available, $frame, $end] = $this->transfer();
        $events = [$available, $frame];
        $client = $this->createMock(AgentSessionClient::class);
        $client->method('events')->willReturnCallback(static function () use (&$events): array { return $events; });
        $client->expects($this->once())->method('acknowledgeBootstrap')->with($cut->toArray())->willReturnCallback(static function () use ($harness, $state): void {
            self::assertStringContainsString('Owner-projected answer', $harness->plainScreenText());
            self::assertStringContainsString('Restored pending input', $harness->plainScreenText());
            self::assertSame('Restored pending input', $state->queuedUserMessages['17']);
            self::assertSame(13, $state->lastSeq);
            self::assertFalse($state->sessionReady);
        });
        $mount = static function () use ($harness, $state): void {
            $harness->screen()->setTranscriptBlocks($state->transcript);
            $harness->screen()->syncQueuedUserMessages($state->queuedUserMessages);
        };
        $poller->poll($state, $client, onBootstrapMounted: $mount);
        $this->assertSame(0, $state->lastSeq);
        $this->assertStringContainsString('Previous mounted view', $harness->plainScreenText());
        $this->assertStringNotContainsString('Owner-projected answer', $harness->plainScreenText());
        $events = [$end];
        $state->lastPoll = 0;
        $poller->poll($state, $client, onBootstrapMounted: $mount);
        $this->assertTrue($state->bootstrapMounted, ($this->logger->records[0]['context']['exception'] ?? null)?->getPrevious()?->getMessage() ?? $state->lastRuntimePollError);
        $this->assertFalse($state->sessionReady);
        $this->assertSame(RunActivityStateEnum::Completed, $state->activity);
        $this->assertSame(41, $state->usage->inputTokens);
        $events = [$available, $frame, $end];
        $state->lastPoll = 0;
        $poller->poll($state, $client, onBootstrapMounted: $mount);
        $this->assertNull($state->bootstrapError, 'A repeated completed transfer cannot remount or acknowledge twice.');
        $this->assertSame(13, $state->lastSeq);
        $events = [new RuntimeEvent('user.message_submitted', '42', 19, ['text' => 'Canonical suffix', 'idempotency_key' => 'suffix']),
            new RuntimeEvent('session.ready', '42', 0, ['bootstrap_id' => $cut->bootstrapId, 'view_epoch' => $cut->viewEpoch, 'canonical_seq' => 23, 'end_offset' => 999])];
        $state->lastPoll = 0;
        $changes = $poller->poll($state, $client, onBootstrapMounted: $mount);
        if (null !== $changes) {
            $harness->screen()->applyTranscriptChangeSet($changes);
        }
        $this->assertTrue($state->sessionReady);
        $this->assertSame(23, $state->lastSeq, 'Actual committed cut includes unmapped events and sequence holes.');
        $this->assertStringContainsString('Canonical suffix', $harness->plainScreenText());
    }

    #[DataProvider('invalidTransfers')]
    public function testInvalidOrDisconnectedTransferNeverMountsAndCancels(string $fault): void
    {
        [$state, $harness, $poller] = $this->scope();
        [$cut, $available, $frame, $end] = $this->transfer();
        if ('checksum' === $fault) {
            $frame = new RuntimeEvent($frame->type, '42', 0, $frame->payload + []);
            $frame = new RuntimeEvent($frame->type, '42', 0, array_replace($frame->payload, ['data' => base64_encode(str_replace('answer', 'ANSWER', base64_decode($frame->payload['data'], true)))]));
        } elseif ('order' === $fault) {
            $frame = new RuntimeEvent($frame->type, '42', 0, array_replace($frame->payload, ['index' => 1]));
        } elseif ('cut' === $fault) {
            $end = new RuntimeEvent($end->type, '42', 0, array_replace($end->payload, ['canonical_seq' => 14]));
        } elseif ('metadata' === $fault) {
            $lines = explode("\n", base64_decode($frame->payload['data'], true));
            $resume = json_decode($lines[0], true, 512, \JSON_THROW_ON_ERROR);
            $resume['data']['queued_messages'] = array_fill_keys(array_map(static fn (int $index): string => 'q'.$index, range(0, 2000)), 'Input');
            $bytes = json_encode($resume, \JSON_THROW_ON_ERROR)."\n".$lines[1]."\n";
            $this->assertLessThanOrEqual(\Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapSpoolStore::FRAME_BYTES, \strlen($bytes));
            $cut = SessionBootstrapDescriptorDTO::fromArray(array_replace($cut->toArray(), ['bytes' => \strlen($bytes), 'checksum' => hash('sha256', $bytes)]));
            $available = new RuntimeEvent($available->type, '42', 0, $cut->toArray() + ['command_id' => 'request']);
            $frame = new RuntimeEvent($frame->type, '42', 0, array_replace($frame->payload, ['data' => base64_encode($bytes)]));
            $end = new RuntimeEvent($end->type, '42', 0, $cut->toArray() + ['frames' => 1]);
        }
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->never())->method('acknowledgeBootstrap');
        $client->expects($this->once())->method('cancelBootstrap')->with('42');
        if ('disconnect' === $fault) {
            $client->method('events')->willThrowException(new \Ineersa\CodingAgent\Runtime\Contract\RuntimeTransportException('Disconnected controller.'));
        } else {
            $client->method('events')->willReturn([$available, $frame, $end]);
        }
        $changes = $poller->poll($state, $client, onBootstrapMounted: static function (): void { self::fail('Invalid transfer mounted.'); });
        if (null !== $changes) {
            $harness->screen()->applyTranscriptChangeSet($changes);
        }
        $this->assertFalse($state->sessionReady);
        $this->assertFalse($state->bootstrapMounted);
        $this->assertSame(0, $state->lastSeq);
        $this->assertStringContainsString('Previous mounted view', $harness->plainScreenText());
        $this->assertNotNull($state->bootstrapError);
    }

    public static function invalidTransfers(): iterable
    {
        yield 'checksum' => ['checksum'];
        yield 'frame order' => ['order'];
        yield 'mismatching cut' => ['cut'];
        yield 'oversized metadata' => ['metadata'];
        yield 'disconnected' => ['disconnect'];
    }

    public function testSupersedingEpochReleasesPartialFramesAndIgnoresOldEnds(): void
    {
        [$state, $harness, $poller] = $this->scope();
        [$cut, $available, $frame, $end] = $this->transfer();
        $bytes = base64_decode($frame->payload['data'], true);
        $partial = new RuntimeEvent('bootstrap.frame', '42', 0, array_replace($frame->payload, ['data' => base64_encode(substr($bytes, 0, 27))]));
        $next = array_replace($cut->toArray(), ['bootstrap_id' => str_repeat('b', 32), 'view_epoch' => 4]);
        $events = [$available, $partial];
        $client = $this->createMock(AgentSessionClient::class);
        $client->method('events')->willReturnCallback(static function () use (&$events): array { return $events; });
        $client->expects($this->once())->method('acknowledgeBootstrap')->with($next);
        $mount = static function () use ($harness, $state): void { $harness->screen()->setTranscriptBlocks($state->transcript); };
        $poller->poll($state, $client, onBootstrapMounted: $mount);
        $events = [new RuntimeEvent('bootstrap.available', '42', 0, $next + ['command_id' => 'request']), $end, $frame];
        $state->lastPoll = 0;
        $poller->poll($state, $client, onBootstrapMounted: $mount);
        $this->assertSame(0, $state->lastSeq);
        $this->assertStringContainsString('Previous mounted view', $harness->plainScreenText());
        $events = [new RuntimeEvent('bootstrap.frame', '42', 0, ['bootstrap_id' => $next['bootstrap_id'], 'view_epoch' => 4, 'index' => 0, 'data' => base64_encode(substr($bytes, 0, 27))]),
            new RuntimeEvent('bootstrap.frame', '42', 0, ['bootstrap_id' => $next['bootstrap_id'], 'view_epoch' => 4, 'index' => 1, 'data' => base64_encode(substr($bytes, 27))]),
            new RuntimeEvent('bootstrap.end', '42', 0, $next + ['frames' => 2])];
        $state->lastPoll = 0;
        $poller->poll($state, $client, onBootstrapMounted: $mount);
        $this->assertTrue($state->bootstrapMounted);
        $this->assertStringContainsString('Owner-projected answer', $harness->plainScreenText());
        $events = [$available, $frame, $end];
        $state->lastPoll = 0;
        $poller->poll($state, $client, onBootstrapMounted: $mount);
        $this->assertSame(13, $state->lastSeq);
        $this->assertNull($state->bootstrapError);
    }

    public function testSameProcessSwitchDropsPartialAssemblyAndAcceptsTheNewRequestEpoch(): void
    {
        [$state, $harness, $poller] = $this->scope();
        [, $available, $frame, $end] = $this->transfer();
        $events = [$available, new RuntimeEvent('bootstrap.frame', '42', 0, array_replace($frame->payload, ['data' => base64_encode(substr(base64_decode($frame->payload['data'], true), 0, 27))]))];
        $client = $this->createMock(AgentSessionClient::class);
        $client->method('events')->willReturnCallback(static function () use (&$events): array { return $events; });
        [$next, $nextAvailable, $nextFrame, $nextEnd] = $this->transfer('43', 'next-request', 1);
        $client->expects($this->once())->method('acknowledgeBootstrap')->with($next->toArray());
        $mount = static function () use ($harness, $state): void { $harness->screen()->setTranscriptBlocks($state->transcript); };
        $poller->poll($state, $client, onBootstrapMounted: $mount);
        $state->sessionId = '43';
        $state->handle = new RunHandle('43', 'bootstrapping', 'next-request');
        $state->lastPoll = 0;
        $events = [$end, $frame, $nextAvailable, $nextFrame, $nextEnd];
        $poller->poll($state, $client, onBootstrapMounted: $mount);
        $this->assertTrue($state->bootstrapMounted);
        $this->assertNull($state->bootstrapError);
        $this->assertSame(13, $state->lastSeq);
        $this->assertStringContainsString('Owner-projected answer', $harness->plainScreenText());
    }

    public function testTimeoutReleasesTheTransferWithoutAUsableEmptySession(): void
    {
        [$state, $harness, $poller] = $this->scope();
        $state->bootstrapStartedAt = microtime(true) - 61;
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->never())->method('events');
        $client->expects($this->once())->method('cancelBootstrap')->with('42');
        $poller->poll($state, $client);
        $this->assertFalse($state->sessionReady);
        $this->assertNotNull($state->bootstrapError);
        $this->assertSame(0, $state->lastSeq);
        $this->assertStringContainsString('Previous mounted view', $harness->plainScreenText());
    }

    public function testStaleRequestIdAndSupersededEpochDoNotChangeMountedView(): void
    {
        [$state, $harness, $poller] = $this->scope();
        [$cut, $available, $frame, $end] = $this->transfer();
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->never())->method('acknowledgeBootstrap');
        $stale = new RuntimeEvent('bootstrap.available', '42', 0, array_replace($available->payload, ['command_id' => 'previous-request']));
        $client->method('events')->willReturn([$stale, $frame, $end]);
        $poller->poll($state, $client, onBootstrapMounted: static function (): void { self::fail('Stale view mounted.'); });
        $this->assertSame(0, $state->lastSeq);
        $this->assertFalse($state->bootstrapMounted);
        $this->assertStringContainsString('Previous mounted view', $harness->plainScreenText());
    }

    #[DataProvider('mountedFailures')]
    public function testReloadAfterMountedFailureDoesNotDispatchRestoredInputAndFreshAttachRejectsOldFrames(bool $ackFails): void
    {
        [$state, $harness, $poller] = $this->scope();
        [$cut, $available, $frame, $end] = $this->transfer();
        $events = [$available, $frame, $end];
        $client = $this->createMock(AgentSessionClient::class);
        $client->method('events')->willReturnCallback(static function () use (&$events): array { return $events; });
        $client->expects($this->never())->method('send');
        $client->expects($this->never())->method('start');
        $client->expects($this->never())->method('cancel');
        $client->expects($this->exactly(2))->method('cancelBootstrap')->with('42');
        $ack = $client->expects($this->once())->method('acknowledgeBootstrap')->with($cut->toArray());
        if ($ackFails) {
            $ack->willThrowException(new \Ineersa\CodingAgent\Runtime\Contract\RuntimeTransportException('Acknowledgement connection failed.'));
        }
        $mount = static function () use ($harness, $state): void {
            $harness->screen()->setTranscriptBlocks($state->transcript);
            $harness->screen()->syncQueuedUserMessages($state->queuedUserMessages);
        };
        $poller->poll($state, $client, onBootstrapMounted: $mount);
        $this->assertSame(['17' => 'Restored pending input'], $state->queuedUserMessages);
        $this->assertStringContainsString('Restored pending input', $harness->plainScreenText());
        if (!$ackFails) {
            $events = [new RuntimeEvent('protocol.error', '42', 0, ['message' => 'Suffix transfer disconnected.'])];
            $state->lastPoll = 0;
            $poller->poll($state, $client, onBootstrapMounted: $mount);
        }
        $this->assertFalse($state->sessionReady);
        $this->assertFalse($state->bootstrapMounted);
        $this->assertNotNull($state->bootstrapError);

        $switch = new \Ineersa\Tui\Application\TuiSessionSwitchService($harness->tui(), $client, $state, $this->logger);
        $catalog = new \Ineersa\Tui\Command\SlashCommandCatalog();
        $questions = new \Ineersa\Tui\Question\QuestionCoordinator();
        $catalog->register(new \Ineersa\Tui\Command\CommandMetadata('reload'), new \Ineersa\Tui\Listener\ReloadCommandHandler($switch, $state, $harness->screen(), $questions));
        $services = $this->createSessionServices(tui: $harness->tui(), screen: $harness->screen(), state: $state, client: $client, switch: $switch, catalog: $catalog, questionCoordinator: $questions);
        $context = $this->buildTuiContext()->withTui($harness->tui())->withScreen($harness->screen())->withState($state)->withClient($client)->withSessionServices($services)->build();
        static::getContainer()->get(\Ineersa\Tui\Listener\SubmitListener::class)->register($context);
        $harness->screen()->promptEditor()->replaceText('/reload');
        $harness->tui()->setFocus($harness->screen()->editorWidget());
        $harness->tui()->handleInput("\r");
        $intent = $switch->consumePendingReload();
        $this->assertNotNull($intent, 'Restored owner queue cannot strand /reload before readiness: '.$harness->plainScreenText());
        $this->assertSame('42', $intent->sessionId);

        // The outer reload loop drops the old iteration. The new iteration owns
        // fresh pending input and protocol identity, never the old restored queue.
        [$fresh, $freshHarness, $freshPoller] = $this->scope();
        $this->assertSame([], $fresh->queuedUserMessages);
        $this->assertSame([], $fresh->queuedFollowUps);
        $freshClient = $this->createMock(AgentSessionClient::class);
        $freshClient->expects($this->never())->method('send');
        $freshClient->expects($this->never())->method('start');
        $freshClient->expects($this->once())->method('attach')->with('42')->willReturn(new RunHandle('42', 'bootstrapping', 'fresh-request'));
        $fresh->handle = $freshClient->attach($intent->sessionId);
        [$nextCut, $nextAvailable, $nextFrame, $nextEnd] = $this->transfer('42', 'fresh-request', 1);
        $nextCut = SessionBootstrapDescriptorDTO::fromArray(array_replace($nextCut->toArray(), ['bootstrap_id' => str_repeat('b', 32)]));
        $nextAvailable = new RuntimeEvent($nextAvailable->type, '42', 0, array_replace($nextAvailable->payload, ['bootstrap_id' => $nextCut->bootstrapId]));
        $nextFrame = new RuntimeEvent($nextFrame->type, '42', 0, array_replace($nextFrame->payload, ['bootstrap_id' => $nextCut->bootstrapId]));
        $nextEnd = new RuntimeEvent($nextEnd->type, '42', 0, array_replace($nextEnd->payload, ['bootstrap_id' => $nextCut->bootstrapId]));
        $freshEvents = [$available, $frame, $end];
        $freshClient->method('events')->willReturnCallback(static function () use (&$freshEvents): array { return $freshEvents; });
        $freshClient->expects($this->once())->method('acknowledgeBootstrap')->with($nextCut->toArray());
        $freshMount = static function () use ($freshHarness, $fresh): void { $freshHarness->screen()->setTranscriptBlocks($fresh->transcript); };
        $freshPoller->poll($fresh, $freshClient, onBootstrapMounted: $freshMount);
        $this->assertFalse($fresh->bootstrapMounted);
        $this->assertSame([], $fresh->queuedUserMessages);
        $freshEvents = [$nextAvailable, $nextFrame, $nextEnd];
        $fresh->lastPoll = 0;
        $freshPoller->poll($fresh, $freshClient, onBootstrapMounted: $freshMount);
        $this->assertTrue($fresh->bootstrapMounted);
        $this->assertFalse($fresh->sessionReady);
        $this->assertNull($fresh->bootstrapError);
        $freshEvents = [new RuntimeEvent('session.ready', '42', 0, ['bootstrap_id' => $nextCut->bootstrapId, 'view_epoch' => 1, 'canonical_seq' => 13, 'end_offset' => 500])];
        $fresh->lastPoll = 0;
        $freshPoller->poll($fresh, $freshClient, onBootstrapMounted: $freshMount);
        $this->assertTrue($fresh->sessionReady);
    }

    public static function mountedFailures(): iterable
    {
        yield 'ack fails after atomic mount' => [true];
        yield 'suffix readiness fails after acknowledgement' => [false];
    }

    /** @return array{TuiSessionState, VirtualTuiHarness, RuntimeEventPoller} */
    private function scope(string $runId = '42'): array
    {
        $state = new TuiSessionState($runId, true);
        $state->handle = new RunHandle($runId, 'bootstrapping', 'request');
        $state->sessionReady = false;
        $harness = new VirtualTuiHarness(sessionId: $runId);
        $state->replaceTranscript([new TranscriptBlock('previous', TranscriptBlockKindEnum::System, $runId, 0, 'Previous mounted view')]);
        $harness->screen()->setTranscriptBlocks($state->transcript);
        /** @var TranscriptProjectorInterface $projector */
        $projector = static::getContainer()->get('tui.session.parent_transcript_projector');
        $logger = new TestLogger();
        $this->logger = $logger;
        $poller = new RuntimeEventPoller(new TuiRuntimeEventApplier($projector, static::getContainer()->get('serializer')), $logger,
            static::getContainer()->get(RuntimeExceptionBoundary::class), $this->createStub(SessionTranscriptProviderInterface::class));

        return [$state, $harness, $poller];
    }

    /** @return array{\Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient, string} */
    private function protocolClient(string $runId, ?string $directory = null): array
    {
        $directory ??= \Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation::createProjectTempDir('bootstrap-recovery');
        [$cut, , $frame] = $this->transfer($runId);
        (new \Symfony\Component\Filesystem\Filesystem())->dumpFile($directory.'/transfer.json', json_encode(
            ['cut' => $cut->toArray(), 'bytes' => base64_decode($frame->payload['data'], true)], \JSON_THROW_ON_ERROR));
        $locator = new class implements \Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator {
            public function command(): array
            {
                return [\PHP_BINARY, $this->path()];
            }

            public function path(): string
            {
                return \dirname(__DIR__).'/Support/BootstrapRecoveryProtocol.php';
            }
        };
        $client = new \Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient(
            new \Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig($locator, $directory),
            new \Ineersa\CodingAgent\PromptTemplate\PromptTemplatesRuntimeConfig(),
            new \Ineersa\CodingAgent\Tool\ToolFilterRuntimeConfig(), $this->logger);

        return [$client, $directory];
    }

    private function pumpUntil(\Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient $client, TuiSessionState $state, VirtualTuiHarness $harness, RuntimeEventPoller $poller, callable $mount, callable $ready): void
    {
        $deadline = microtime(true) + 3;
        do {
            $state->lastPoll = 0;
            $changes = $poller->poll($state, $client, onBootstrapMounted: $mount);
            if (null !== $changes) {
                $harness->screen()->applyTranscriptChangeSet($changes);
            }
            $this->assertTrue($this->peerRunning($client), 'Owned protocol peer died: '.$state->lastRuntimePollError);
            $this->assertNull($state->bootstrapError, $state->lastRuntimePollError);
            if ($ready()) {
                return;
            }
            usleep(1_000); // Yield inside the bounded, liveness-coupled readiness predicate.
        } while (microtime(true) < $deadline);
        $this->fail('Bootstrap did not become usable: '.$harness->plainScreenText());
    }

    private function peerRunning(\Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient $client): bool
    {
        $process = (new \ReflectionProperty($client, 'process'))->getValue($client);

        return \is_resource($process) && proc_get_status($process)['running'];
    }

    private function awaitPeerExit(\Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient $client): void
    {
        $deadline = microtime(true) + 3;
        while ($this->peerRunning($client) && microtime(true) < $deadline) {
            usleep(1_000);
        }
        $this->assertFalse($this->peerRunning($client), 'Controlled exit/EOF must stop the owned peer without a signal.');
    }

    private function closePeer(\Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient $client): void
    {
        $pipes = (new \ReflectionProperty($client, 'pipes'))->getValue($client);
        if (isset($pipes[0]) && \is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $this->awaitPeerExit($client);
        $client->shutdown(); // Already exited: no root-owned or session-tagged process is signalled.
    }

    /** @return list<array<string, mixed>> */
    private function commands(string $directory): array
    {
        if (!is_file($directory.'/commands.jsonl')) {
            return [];
        }

        return array_map(static fn (string $line): array => json_decode($line, true, 512, \JSON_THROW_ON_ERROR), file($directory.'/commands.jsonl', \FILE_IGNORE_NEW_LINES));
    }

    /** @return list<string> */
    private function submittedTexts(string $directory): array
    {
        return array_values(array_map(static fn (array $command): string => $command['payload']['text'], array_filter(
            $this->commands($directory), static fn (array $command): bool => \in_array($command['type'], ['follow_up', 'user_message'], true))));
    }

    private function assertExactAck(string $directory): void
    {
        $cut = json_decode(file_get_contents($directory.'/previous.json'), true, 512, \JSON_THROW_ON_ERROR);
        unset($cut['command_id']);
        $acks = array_values(array_filter($this->commands($directory), static fn (array $command): bool => 'bootstrap.applied' === $command['type']));
        $this->assertSame($cut, $acks[array_key_last($acks)]['payload']);
    }

    /** @return array{SessionBootstrapDescriptorDTO, RuntimeEvent, RuntimeEvent, RuntimeEvent} */
    private function transfer(string $runId = '42', string $request = 'request', int $epoch = 3): array
    {
        $resume = (new SessionResumeMetadataProjection())->toArray();
        $resume['usage']['inputTokens'] = 41;
        $resume['queued_messages'] = ['17' => 'Restored pending input'];
        $resume += ['status' => 'completed', 'model' => 'provider/model', 'turn_no' => 2];
        $block = new TranscriptBlock('answer', TranscriptBlockKindEnum::AssistantMessage, $runId, 13, 'Owner-projected answer');
        $serializer = static::getContainer()->get('serializer');
        $bytes = $serializer->serialize(['kind' => 'resume', 'data' => $resume], 'json')."\n";
        $bytes .= '{"kind":"block","data":'.$serializer->serialize($block, 'json')."}\n";
        $cut = new SessionBootstrapDescriptorDTO($runId, str_repeat('a', 32), $epoch, 13, 500, 7, 2, \strlen($bytes), hash('sha256', $bytes));

        return [$cut, new RuntimeEvent('bootstrap.available', $runId, 0, $cut->toArray() + ['request_id' => 'owner-request', 'command_id' => $request]),
            new RuntimeEvent('bootstrap.frame', $runId, 0, ['bootstrap_id' => $cut->bootstrapId, 'view_epoch' => $epoch, 'index' => 0, 'data' => base64_encode($bytes)]),
            new RuntimeEvent('bootstrap.end', $runId, 0, $cut->toArray() + ['frames' => 1])];
    }
}
