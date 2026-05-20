<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Runtime;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\AssistantStreamProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Runtime\RuntimeEventPoller;
use Ineersa\Tui\Runtime\TuiSessionState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

#[CoversClass(RuntimeEventPoller::class)]
final class RuntimeEventPollerProjectionTest extends TestCase
{
    private string $tempDir = '';
    private TuiSessionState $state;
    private RuntimeEventPoller $poller;
    private HatfieldSessionStore $sessionStore;
    private string $originalCwd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/hatfield-poller-test-'.getmypid();
        if (is_dir($this->tempDir)) {
            $this->rmDir($this->tempDir);
        }
        mkdir($this->tempDir, 0777, true);
        mkdir($this->tempDir.'/.hatfield', 0777, true);
        mkdir($this->tempDir.'/config', 0777, true);
        $this->originalCwd = getcwd();
        chdir($this->tempDir);

        file_put_contents($this->tempDir.'/config/hatfield.defaults.yaml', <<<'YAML'
tui:
    theme: cyberpunk
    theme_paths:
        - '%kernel.project_dir%/config/themes'
sessions:
    path: .hatfield/sessions
YAML);
        file_put_contents($this->tempDir.'/.hatfield/settings.yaml', '');

        $appConfig = $this->createAppConfig($this->tempDir);
        $this->sessionStore = new HatfieldSessionStore($appConfig, new LockFactory(new FlockStore()));

        $this->state = new TuiSessionState('test-session');
        $this->state->handle = new RunHandle('run-1');

        // Wire a real projector with the assistant-stream subscriber.
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new AssistantStreamProjectionSubscriber());
        $projector = new TranscriptProjector(
            $dispatcher,
            new TranscriptProjectionState(),
        );

        $this->poller = new RuntimeEventPoller($this->sessionStore, $projector);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (isset($this->originalCwd)) {
            chdir($this->originalCwd);
        }

        if (is_dir($this->tempDir)) {
            $this->rmDir($this->tempDir);
        }
    }

    // ── Projection integration ──────────────────────────────

    public function testPollFeedsEventsToProjectorAndAppendsBlocks(): void
    {
        // assistant.text_started creates a streaming block;
        // assistant.text_completed finalizes it.
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('events')->willReturn([
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::AssistantTextStarted->value,
                runId: 'run-1',
                seq: 1,
                payload: [
                    'message_id' => 'msg-1',
                    'block_id' => 'text-1',
                    'text' => '',
                ],
            ),
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::AssistantTextCompleted->value,
                runId: 'run-1',
                seq: 2,
                payload: [
                    'message_id' => 'msg-1',
                    'block_id' => 'text-1',
                    'text' => 'Hello, world!',
                ],
            ),
        ]);

        $blocks = $this->poller->poll($this->state, $client);

        $this->assertNotNull($blocks);
        $this->assertCount(1, $blocks);
        $this->assertSame(TranscriptBlockKindEnum::AssistantMessage, $blocks[0]->kind);
        $this->assertStringContainsString('Hello, world!', $blocks[0]->text);
        $this->assertFalse($blocks[0]->streaming, 'Block should be finalized');
        $this->assertSame(2, $this->state->lastSeq);
    }

    public function testPollPreservesUserBlocksAndAppendsProjectedOnes(): void
    {
        // Simulate a user message already in state (from SubmitListener)
        $this->state->transcript[] = new TranscriptBlock(
            id: 'user_1',
            kind: TranscriptBlockKindEnum::UserMessage,
            runId: 'run-1',
            seq: 1,
            text: 'What is 2+2?',
        );

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('events')->willReturn([
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::AssistantTextStarted->value,
                runId: 'run-1',
                seq: 1,
                payload: [
                    'message_id' => 'msg-1',
                    'block_id' => 'text-1',
                    'text' => '',
                ],
            ),
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::AssistantTextCompleted->value,
                runId: 'run-1',
                seq: 2,
                payload: [
                    'message_id' => 'msg-1',
                    'block_id' => 'text-1',
                    'text' => 'The answer is 4.',
                ],
            ),
        ]);

        $blocks = $this->poller->poll($this->state, $client);

        $this->assertNotNull($blocks);
        \assert(1 === \count($blocks), 'Only the assistant block should be new');
        // state->transcript should have user block + projected assistant block
        $this->assertCount(2, $this->state->transcript);
        $this->assertSame(TranscriptBlockKindEnum::UserMessage, $this->state->transcript[0]->kind);
        $this->assertSame(TranscriptBlockKindEnum::AssistantMessage, $this->state->transcript[1]->kind);
    }

    public function testPollRemovesProcessingPlaceholderOnFirstEvent(): void
    {
        // Simulate "Processing..." in state from SubmitListener
        $this->state->transcript[] = new TranscriptBlock(
            id: 'processing_1',
            kind: TranscriptBlockKindEnum::System,
            runId: 'run-1',
            seq: 1,
            text: 'Processing...',
        );

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('events')->willReturn([
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::AssistantTextStarted->value,
                runId: 'run-1',
                seq: 1,
                payload: [
                    'message_id' => 'msg-1',
                    'block_id' => 'text-1',
                    'text' => '',
                ],
            ),
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::AssistantTextCompleted->value,
                runId: 'run-1',
                seq: 2,
                payload: [
                    'message_id' => 'msg-1',
                    'block_id' => 'text-1',
                    'text' => 'Done.',
                ],
            ),
        ]);

        $this->poller->poll($this->state, $client);

        // Processing... should be gone, assistant block should be present
        $this->assertCount(1, $this->state->transcript);
        $this->assertSame(TranscriptBlockKindEnum::AssistantMessage, $this->state->transcript[0]->kind);
    }

    // ── Sequence de-duplication ─────────────────────────────

    public function testPollSkipsAlreadySeenSequences(): void
    {
        $this->state->lastSeq = 2;

        // Create a fresh poller with its own projector to avoid cross-test state
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new AssistantStreamProjectionSubscriber());
        $projector = new TranscriptProjector($dispatcher, new TranscriptProjectionState());
        $poller = new RuntimeEventPoller($this->sessionStore, $projector);

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('events')->willReturn([
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::AssistantTextStarted->value,
                runId: 'run-1',
                seq: 1,   // ≤ lastSeq=2 → skipped
                payload: ['message_id' => 'old', 'block_id' => 'b-o', 'text' => 'Old (seen).'],
            ),
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::AssistantTextStarted->value,
                runId: 'run-1',
                seq: 3,   // > lastSeq=2 → accepted
                payload: ['message_id' => 'new', 'block_id' => 'b-n', 'text' => ''],
            ),
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::AssistantTextCompleted->value,
                runId: 'run-1',
                seq: 4,
                payload: ['message_id' => 'new', 'block_id' => 'b-n', 'text' => 'New!'],
            ),
        ]);

        $blocks = $poller->poll($this->state, $client);

        $this->assertNotNull($blocks);
        // Only seq=3+4 should produce a block; seq=1 is skipped
        $this->assertCount(1, $blocks);
        $this->assertStringContainsString('New!', $blocks[0]->text);
    }

    public function testPollAcceptsTransientSeqZero(): void
    {
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('events')->willReturn([
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::AssistantTextStarted->value,
                runId: 'run-1',
                seq: 0,
                payload: ['message_id' => 'transient', 'block_id' => 'b-t', 'text' => ''],
            ),
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::AssistantTextCompleted->value,
                runId: 'run-1',
                seq: 0,
                payload: ['message_id' => 'transient', 'block_id' => 'b-t', 'text' => 'Streaming...'],
            ),
        ]);

        $blocks = $this->poller->poll($this->state, $client);

        // Transient seq=0 should be accepted (not deduped)
        $this->assertNotNull($blocks);
        $this->assertCount(1, $blocks);
        // lastSeq should NOT advance (transient events don't update cursor)
        $this->assertSame(0, $this->state->lastSeq);
    }

    // ── Empty / error ───────────────────────────────────────

    public function testPollReturnsNullWhenNoHandle(): void
    {
        $this->state->handle = null;
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->never())->method('events');

        $result = $this->poller->poll($this->state, $client);
        $this->assertNull($result);
    }

    public function testPollReturnsNullWhenNoEvents(): void
    {
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('events')->willReturn([]);

        $result = $this->poller->poll($this->state, $client);
        $this->assertNull($result);
    }

    // ── Footer usage extraction (still works) ──────────────

    public function testPollAccumulatesUsageFromAssistantMessageCompleted(): void
    {
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('events')->willReturn([
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::AssistantTextStarted->value,
                runId: 'run-1',
                seq: 1,
                payload: ['message_id' => 'msg-1', 'block_id' => 'text-1', 'text' => ''],
            ),
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::AssistantTextCompleted->value,
                runId: 'run-1',
                seq: 2,
                payload: ['message_id' => 'msg-1', 'block_id' => 'text-1', 'text' => 'Hi!'],
            ),
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::AssistantMessageCompleted->value,
                runId: 'run-1',
                seq: 3,
                payload: [
                    'message_id' => 'msg-1',
                    'text' => 'Hi!',
                    'usage' => [
                        'input_tokens' => 100,
                        'output_tokens' => 50,
                        'cost' => 0.002,
                    ],
                ],
            ),
        ]);

        $this->poller->poll($this->state, $client);

        $this->assertSame(100, $this->state->inputTokens);
        $this->assertSame(50, $this->state->outputTokens);
        $this->assertSame(0.002, $this->state->totalCost);
    }

    public function testPollDoesNotExtractUsageFromOtherEventTypes(): void
    {
        $this->state->inputTokens = 42;

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('events')->willReturn([
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::RunStarted->value,
                runId: 'run-1',
                seq: 1,
                payload: [],
            ),
        ]);

        $this->poller->poll($this->state, $client);

        // Usage should NOT have changed (RunStarted has no usage)
        $this->assertSame(42, $this->state->inputTokens);
    }

    // ── Text delta sequence projection ─────────────────────

    public function testPollProjectsAssistantTextDeltaSequence(): void
    {
        $this->state->lastSeq = 0;

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('events')->willReturn([
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::AssistantTextStarted->value,
                runId: 'run-1',
                seq: 1,
                payload: [
                    'message_id' => 'msg-1',
                    'block_id' => 'text-1',
                ],
            ),
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::AssistantTextDelta->value,
                runId: 'run-1',
                seq: 2,
                payload: [
                    'message_id' => 'msg-1',
                    'block_id' => 'text-1',
                    'delta' => 'Hello ',
                ],
            ),
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::AssistantTextDelta->value,
                runId: 'run-1',
                seq: 3,
                payload: [
                    'message_id' => 'msg-1',
                    'block_id' => 'text-1',
                    'delta' => 'World',
                ],
            ),
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::AssistantTextCompleted->value,
                runId: 'run-1',
                seq: 4,
                payload: [
                    'message_id' => 'msg-1',
                    'block_id' => 'text-1',
                    'text' => 'Hello World',
                ],
            ),
        ]);

        $blocks = $this->poller->poll($this->state, $client);

        $this->assertNotNull($blocks);
        // text_started creates, deltas update same block, completed finalizes → 1 block
        $this->assertCount(1, $blocks);
        $this->assertSame('Hello World', $blocks[0]->text);
        $this->assertFalse($blocks[0]->streaming);
    }

    private function createAppConfig(string $projectDir): AppConfig
    {
        $loader = new AppConfigLoader(
            new SettingsPathResolver($projectDir),
        );
        $locator = new AppResourceLocator($projectDir);

        return AppConfig::fromContainer($loader, $locator);
    }

    private function rmDir(string $dir): void
    {
        $it = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
