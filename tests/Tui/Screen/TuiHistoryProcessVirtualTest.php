<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\ChildInvocationBackgroundService;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplatesRuntimeConfig;
use Ineersa\CodingAgent\Runtime\BackgroundWorkSnapshotProvider;
use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator;
use Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\Replay\ControllerReplaySourceMessengerConsole;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\CodingAgent\Tool\ToolFilterRuntimeConfig;
use Ineersa\Tui\Application\TuiSessionCompositionFactory;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Listener\HistoryCommandHandler;
use Ineersa\Tui\Listener\RuntimeQuestionEventHandler;
use Ineersa\Tui\Listener\TickPollListener;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionLifecycleDispatcher;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Runtime\TuiTickDispatcher;
use Ineersa\Tui\Tests\E2E\Support\TuiE2eSessionCatalogSeeder;
use Ineersa\Tui\Tests\E2E\TuiE2eDatabaseEnv;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Symfony\Component\Tui\Terminal\ScreenBuffer;

/**
 * Actual controller JSONL and native scheduled rendering, without a terminal process.
 * This cross-boundary history journey complements the picker unit/virtual tests:
 * neither in-process selection nor direct controller commands exercise the client's
 * command serialization. The five-second pipe deadline is only a safety cap;
 * readiness comes from runtime state after readable-pipe events, never a delay.
 */
final class TuiHistoryProcessVirtualTest extends PerMethodIsolatedKernelTestCase
{
    public function testCompletedAndReattachedHistorySelectionsRepaintThroughProcessTransport(): void
    {
        $container = static::getContainer();
        $directory = realpath((string) getcwd());
        $this->assertIsString($directory);
        $this->assertStringStartsWith(ProjectDir::get().'/var/tmp/', $directory.'/');
        $this->assertStringStartsWith('hatfield-test-', basename($directory));
        $this->assertFalse(is_link($directory.'/.hatfield'));
        $this->assertSame($directory.'/.hatfield', realpath($directory.'/.hatfield'));
        $this->assertFalse(is_link($directory.'/.hatfield/settings.yaml'));
        TuiE2eDatabaseEnv::writeReplaySettings($directory, TuiE2eDatabaseEnv::replayBaseSettings());
        $paths = TuiE2eDatabaseEnv::allocateIsolatedPaths(ProjectDir::get(), $directory, 'history-process');
        $run = TuiE2eSessionCatalogSeeder::createSession($directory, $paths['appEnv'], 'FIRST_PROMPT');
        $fixtures = [];
        foreach (['FIRST_ANSWER', 'SECOND_ANSWER'] as $index => $answer) {
            $path = $directory.'/replay-'.$index.'.json';
            file_put_contents($path, json_encode([
                'fixture_source' => 'synthetic', 'model' => 'llama_cpp_test/test',
                'provider_id' => 'llama_cpp_test', 'reasoning' => 'off', 'stop_reason' => 'stop',
                'deltas' => [['type' => 'text', 'content' => $answer]],
            ], \JSON_THROW_ON_ERROR));
            $fixtures[] = $path;
        }
        $messenger = ControllerReplaySourceMessengerConsole::install($directory, ProjectDir::get().'/bin/console');
        $locator = new class($paths['appEnv'], implode(';', $fixtures), $messenger) implements AppExecutableLocator {
            public function __construct(private string $database, private string $fixtures, private string $messenger)
            {
            }

            public function command(): array
            {
                return ['env', 'HATFIELD_TEST_DATABASE_PATH='.$this->database, 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.$this->fixtures,
                    'HATFIELD_BINARY_PATH='.$this->messenger, \PHP_BINARY, $this->path()];
            }

            public function path(): string
            {
                return ProjectDir::get().'/bin/console';
            }
        };
        $client = new JsonlProcessAgentSessionClient(
            new RuntimeProcessConfig($locator, $directory),
            $container->get(PromptTemplatesRuntimeConfig::class),
            $container->get(ToolFilterRuntimeConfig::class),
            new TestLogger(),
            $container->get(HatfieldSessionStore::class),
            $container->get(BackgroundProcessManager::class),
            $container->get(ChildInvocationBackgroundService::class),
            $container->get(BackgroundWorkSnapshotProvider::class),
        );
        try {
            $harness = new VirtualTuiHarness(sessionId: $run);
            $state = new TuiSessionState($run);
            $services = $container->get(TuiSessionCompositionFactory::class)->create($harness->tui(), $harness->screen(), $state, $client);
            $history = $container->get(HistoryProviderInterface::class);
            $ticks = new TuiTickDispatcher();
            $context = new TuiRuntimeContext($harness->tui(), $client, $state, $harness->screen(), $container->get(HatfieldSessionStore::class), $ticks, $services->switch, new TuiSessionLifecycleDispatcher(), $history, $services);
            (new TickPollListener(new RuntimeQuestionEventHandler()))->register($context);
            $harness->tui()->onTick($ticks->dispatch(...));
            $state->handle = $client->start(new StartRunRequest('FIRST_PROMPT', runId: $run, model: 'llama_cpp_test/test'));
            $state->sessionReady = true;
            $this->awaitState($client, $harness, $state, static fn (): bool => RunActivityStateEnum::Completed === $state->activity);
            $client->send($run, new UserCommand('follow_up', text: 'SECOND_PROMPT'));
            $this->awaitState($client, $harness, $state, static fn (): bool => RunActivityStateEnum::Completed === $state->activity && \in_array('SECOND_ANSWER', array_column($state->transcript, 'text'), true));
            $this->assertStringContainsString('SECOND_ANSWER', $this->visible($harness));

            for ($attachment = 0; $attachment < 2; ++$attachment) {
                if (1 === $attachment) {
                    $state->handle = $client->attach($run);
                    $state->beginBootstrapAttach();
                    $this->awaitState($client, $harness, $state, static fn (): bool => $state->sessionReady);
                    $this->assertStringContainsString('SECOND_ANSWER', $this->visible($harness));
                }
                $entries = $history->forSession($run, after: 0)->entries;
                $this->assertCount(4, $entries);
                $cursor = $state->lastSeq;
                (new HistoryCommandHandler($services->historyPicker))->handle(new SlashCommand('history', '', '/history'));
                $harness->tui()->processRender();
                $this->assertStringContainsString('→ ◉ assistant: SECOND_ANSWER', $this->visible($harness));
                $harness->tui()->handleInput("\x1b[A");
                $harness->tui()->handleInput("\x1b[A");
                $harness->tui()->handleInput("\n");
                $this->awaitState($client, $harness, $state, static fn (): bool => $state->lastSeq > $cursor);
                $this->assertSame('SECOND_PROMPT', $harness->screen()->editorText());
                $this->assertContains('FIRST_ANSWER', array_column($state->transcript, 'text'));
                $this->assertNotContains('SECOND_ANSWER', array_column($state->transcript, 'text'));
                $this->assertStringContainsString('FIRST_ANSWER', $this->visible($harness));
                $this->assertStringNotContainsString('SECOND_ANSWER', $this->visible($harness));
                $this->assertCount(4, $history->forSession($run, after: 0)->entries);
                $this->assertSame($entries[1]->anchor, $history->forSession($run, after: 0)->selectedAnchor);
                $cursor = $state->lastSeq;
                $services->historyPicker->open(after: 0);
                $harness->tui()->processRender();
                $harness->tui()->handleInput("\x1b[B");
                $harness->tui()->requestRender();
                $harness->tui()->processRender();
                $this->assertStringContainsString('→ ○ assistant: SECOND_ANSWER', $this->visible($harness));
                $harness->tui()->handleInput("\n");
                $this->awaitState($client, $harness, $state, static fn (): bool => $state->lastSeq > $cursor);
                $this->assertContains('SECOND_ANSWER', array_column($state->transcript, 'text'));
                $this->assertSame('', $harness->screen()->editorText());
                $this->assertStringContainsString('SECOND_ANSWER', $this->visible($harness));
                $this->assertSame($entries[3]->anchor, $history->forSession($run, after: 0)->selectedAnchor);
            }
        } finally {
            $client->shutdown();
        }
    }

    /** Block on the owned controller pipe, not elapsed-time polling. */
    private function awaitState(JsonlProcessAgentSessionClient $client, VirtualTuiHarness $harness, TuiSessionState $state, callable $ready): void
    {
        $deadline = microtime(true) + 5;
        while (true) {
            $state->lastPoll = 0.0;
            $harness->tui()->tick();
            $harness->tui()->processRender();
            $this->assertSame('', $state->lastRuntimePollError);
            if ($ready()) {
                return;
            }
            $pipes = (new \ReflectionProperty($client, 'pipes'))->getValue($client);
            $read = [$pipes[1]];
            $write = $except = [];
            $remaining = max(0, (int) ceil($deadline - microtime(true)));
            $this->assertGreaterThan(0, $remaining, 'Controller state did not reach the expected event boundary.');
            if (0 === stream_select($read, $write, $except, $remaining)) {
                $this->fail('Controller produced no expected event: '.json_encode([
                    'seq' => $state->lastSeq, 'activity' => $state->activity->value,
                    'editor' => $harness->screen()->editorText(),
                    'transcript' => array_column($state->transcript, 'text'),
                ], \JSON_THROW_ON_ERROR));
            }
            $this->assertFalse(feof($pipes[1]), 'Controller exited before the expected event.');
        }
    }

    private function visible(VirtualTuiHarness $harness): string
    {
        $buffer = new ScreenBuffer(width: 120, height: 40);
        $buffer->write($harness->terminal()->getOutput());

        return $buffer->getScreen();
    }
}
