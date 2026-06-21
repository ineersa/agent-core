<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Listener\ExportCommandHandler;
use Ineersa\Tui\Runtime\TuiSessionState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExportCommandHandler::class)]
final class ExportCommandHandlerTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/export-handler-test-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectDir);
    }

    // ── Path parsing ──────────────────────────────────────────────────────

    #[Test]
    public function parsesEmptyArgsAsNull(): void
    {
        $handler = $this->createHandler('test-session');
        $result = $handler->handle(new SlashCommand('export', '', '/export'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('no events', $result->text);
    }

    #[Test]
    public function parsesUnquotedPathStoppingAtWhitespace(): void
    {
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'user_messages' => [['role' => 'user', 'content' => 'Hi']],
            ]),
        ]);

        $handler = $this->createHandler('test-session');
        $result = $handler->handle(new SlashCommand('export', 'my-export.html extra ignored', '/export my-export.html extra ignored'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('my-export.html', $result->text);

        $cwd = getcwd();
        $this->assertFileExists($cwd.'/my-export.html');
        @unlink($cwd.'/my-export.html');
    }

    #[Test]
    public function parsesDoubleQuotedPathWithSpaces(): void
    {
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'user_messages' => [['role' => 'user', 'content' => 'Hi']],
            ]),
        ]);

        $handler = $this->createHandler('test-session');
        $path = $this->projectDir.'/my path with spaces.html';
        $result = $handler->handle(new SlashCommand('export', '"'.$path.'"', '/export "'.$path.'"'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString($path, $result->text);
        $this->assertFileExists($path);
    }

    #[Test]
    public function parsesSingleQuotedPathWithSpaces(): void
    {
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'user_messages' => [['role' => 'user', 'content' => 'Hi']],
            ]),
        ]);

        $handler = $this->createHandler('test-session');
        $path = $this->projectDir.'/single quoted path.html';
        $result = $handler->handle(new SlashCommand('export', "'".$path."'", "/export '".$path."'"));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString($path, $result->text);
        $this->assertFileExists($path);
    }

    #[Test]
    public function returnsErrorForMalformedQuotes(): void
    {
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'user_messages' => [['role' => 'user', 'content' => 'Hi']],
            ]),
        ]);

        $handler = $this->createHandler('test-session');
        $result = $handler->handle(new SlashCommand('export', '"unclosed', '/export "unclosed'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('error', $result->role);
        $this->assertStringContainsString('Malformed path', $result->text);
    }

    // ── Missing/empty session ──────────────────────────────────────────────

    #[Test]
    public function returnsErrorForEmptySessionId(): void
    {
        $handler = $this->createHandler('');
        $result = $handler->handle(new SlashCommand('export', '', '/export'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('No active session', $result->text);
    }

    #[Test]
    public function returnsErrorWhenEventsFileDoesNotExist(): void
    {
        // No events.jsonl file created — session dir is empty.
        $handler = $this->createHandler('test-session');
        $result = $handler->handle(new SlashCommand('export', '', '/export'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('no events', $result->text);
    }

    #[Test]
    public function returnsErrorWhenEventsFileIsEmpty(): void
    {
        // Create the sessions dir and an empty events.jsonl.
        $this->setupEmptyEventsFile('test-session');

        $handler = $this->createHandler('test-session');
        $result = $handler->handle(new SlashCommand('export', '', '/export'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('no events', $result->text);
    }

    // ── HTML export with populated session ─────────────────────────────────

    #[Test]
    public function exportsHtmlToDefaultPath(): void
    {
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'user_messages' => [['role' => 'user', 'content' => 'Hello world']],
            ]),
            $this->makeEvent(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'text' => 'The sky is blue.',
                'stop_reason' => 'end_turn',
            ]),
            $this->makeEvent(3, 1, 'agent_end', [
                'reason' => 'completed',
            ]),
        ]);

        $cwd = getcwd();
        $handler = $this->createHandler('test-session');
        $result = $handler->handle(new SlashCommand('export', '', '/export'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Session exported', $result->text);

        $expectedPath = $cwd.'/hatfield-session-test-session.html';
        $this->assertFileExists($expectedPath);

        $html = file_get_contents($expectedPath);
        $this->assertStringContainsString('Hello world', $html);
        $this->assertStringContainsString('The sky is blue.', $html);

        // Cleanup.
        @unlink($expectedPath);
    }

    #[Test]
    public function exportsHtmlToGivenPath(): void
    {
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'user_messages' => [['role' => 'user', 'content' => 'Hello']],
            ]),
            $this->makeEvent(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'text' => 'Response text.',
                'stop_reason' => 'end_turn',
            ]),
        ]);

        $path = $this->projectDir.'/custom-export.html';
        $handler = $this->createHandler('test-session');
        $result = $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('custom-export.html', $result->text);
        $this->assertFileExists($path);

        $html = file_get_contents($path);
        $this->assertStringContainsString('Response text.', $html);
    }

    // ── JSONL export ───────────────────────────────────────────────────────

    #[Test]
    public function exportsJsonlCopyingCanonicalEvents(): void
    {
        $eventsData = [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'user_messages' => [['role' => 'user', 'content' => 'Hello']],
            ]),
            $this->makeEvent(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'text' => 'Response.',
                'stop_reason' => 'end_turn',
            ]),
        ];
        $this->setupEventsFile('test-session', $eventsData);

        $path = $this->projectDir.'/export.jsonl';
        $handler = $this->createHandler('test-session');
        $result = $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('export.jsonl', $result->text);
        $this->assertFileExists($path);

        // Verify JSONL content matches (2 lines, one per event).
        $lines = file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        $this->assertCount(2, $lines);

        // Source events.jsonl is not mutated.
        $sourcePath = $this->getEventsPath('test-session');
        $sourceLines = file($sourcePath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        $this->assertCount(2, $sourceLines);
    }

    // ── HTML content escaping ──────────────────────────────────────────────

    #[Test]
    public function htmlExportEscapesScriptTags(): void
    {
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'user_messages' => [
                    ['role' => 'user', 'content' => '<script>alert("xss")</script>'],
                ],
            ]),
            $this->makeEvent(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'text' => '<img src=x onerror=alert(1)>',
                'stop_reason' => 'end_turn',
            ]),
        ]);

        $path = $this->projectDir.'/escaped-export.html';
        $handler = $this->createHandler('test-session');
        $result = $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $this->assertInstanceOf(TranscriptMessage::class, $result);

        $html = file_get_contents($path);
        // The raw script tag must NOT appear; it should be escaped.
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('alert("xss")', $html);
        // The escaped version should appear.
        $this->assertStringContainsString('&lt;script&gt;', $html);
        // The img tag angle brackets must be escaped; onerror=alert inside
        // &lt;...&gt; is harmless and may still appear as plain text.
        $this->assertStringNotContainsString('<img src=x onerror', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    #[Test]
    public function htmlExportEscapesHtmlEntitiesInToolOutput(): void
    {
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'user_messages' => [['role' => 'user', 'content' => 'Run tool']],
            ]),
            $this->makeEvent(2, 1, 'tool_execution_start', [
                'tool_call_id' => 'tc1',
                'tool_name' => 'bash',
                'order_index' => 0,
            ]),
            $this->makeEvent(3, 1, 'tool_execution_end', [
                'tool_call_id' => 'tc1',
                'order_index' => 0,
                'is_error' => false,
                'result' => '<div>Injected HTML</div>',
            ]),
        ]);

        $path = $this->projectDir.'/tool-escaped.html';
        $handler = $this->createHandler('test-session');
        $result = $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $this->assertInstanceOf(TranscriptMessage::class, $result);

        $html = file_get_contents($path);
        // Raw HTML in tool output must be escaped.
        $this->assertStringNotContainsString('<div>Injected HTML</div>', $html);
        $this->assertStringContainsString('&lt;div&gt;Injected HTML&lt;/div&gt;', $html);
    }

    // ── Tool event rendering ───────────────────────────────────────────────

    #[Test]
    public function rendersToolCallsWithStartAndEnd(): void
    {
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'user_messages' => [['role' => 'user', 'content' => 'List files']],
            ]),
            $this->makeEvent(2, 1, 'tool_execution_start', [
                'tool_call_id' => 'tc-ls',
                'tool_name' => 'bash',
                'order_index' => 0,
            ]),
            $this->makeEvent(3, 1, 'tool_execution_end', [
                'tool_call_id' => 'tc-ls',
                'order_index' => 0,
                'is_error' => false,
                'result' => 'file1.txt\nfile2.txt',
            ]),
        ]);

        $path = $this->projectDir.'/tool-render.html';
        $handler = $this->createHandler('test-session');
        $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $html = file_get_contents($path);
        $this->assertStringContainsString('bash', $html, 'Tool name should appear in output');
        $this->assertStringContainsString('file1.txt', $html);
        $this->assertStringContainsString('List files', $html);
    }

    // ── Complete event representation ─────────────────────────────────────

    #[Test]
    public function htmlIncludesRawEventJsonForEveryEvent(): void
    {
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'user_messages' => [['role' => 'user', 'content' => 'Hello']],
            ]),
            $this->makeEvent(2, 1, 'turn_advanced', ['turn_no' => 1]),
            $this->makeEvent(3, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'text' => 'Response.',
                'stop_reason' => 'end_turn',
            ]),
            $this->makeEvent(4, 1, 'agent_end', ['reason' => 'completed']),
        ]);

        $path = $this->projectDir.'/raw-json-events.html';
        $handler = $this->createHandler('test-session');
        $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $html = file_get_contents($path);

        // Every event must produce a "Raw event" details block.
        $rawEventCount = substr_count($html, '<summary>Raw event</summary>');
        $this->assertSame(4, $rawEventCount, 'Every JSONL line must produce a Raw event block');

        // Each event card must include the type in metadata and the full event JSON.
        $this->assertStringContainsString('<span class="event-type">run_started</span>', $html);
        $this->assertStringContainsString('<span class="event-type">turn_advanced</span>', $html);
        $this->assertStringContainsString('<span class="event-type">llm_step_completed</span>', $html);
        $this->assertStringContainsString('<span class="event-type">agent_end</span>', $html);

        // Full JSON must be present in escaped <pre> blocks.
        $this->assertStringContainsString('<pre class="event-json">', $html);
    }

    #[Test]
    public function htmlIncludesUserMessagesContent(): void
    {
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'user_messages' => [
                    ['role' => 'user', 'content' => 'What is the capital of France?'],
                ],
            ]),
            $this->makeEvent(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'text' => 'The capital of France is Paris.',
                'stop_reason' => 'end_turn',
            ]),
        ]);

        $path = $this->projectDir.'/user-messages-content.html';
        $handler = $this->createHandler('test-session');
        $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $html = file_get_contents($path);

        // User message content must appear in both the friendly rendering
        // and the raw JSON block.
        $this->assertStringContainsString('What is the capital of France?', $html);
        // Assistant text must appear.
        $this->assertStringContainsString('The capital of France is Paris.', $html);
    }

    #[Test]
    public function htmlIncludesSystemInstructionContent(): void
    {
        $instructionText = '## AGENTS.md instructions

You are a helpful assistant.

### Skills registry
- testing: Run tests
- castor: Task runner';

        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'user_messages' => [
                    ['role' => 'system', 'content' => $instructionText],
                    ['role' => 'user', 'content' => 'Hello'],
                ],
            ]),
        ]);

        $path = $this->projectDir.'/instruction-content.html';
        $handler = $this->createHandler('test-session');
        $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $html = file_get_contents($path);

        // Instruction/AGENTS.md/skills registry content must appear in the HTML.
        // It appears in the friendly rendering (run_started extracts all user_messages
        // regardless of role) and in the full JSON block.
        $this->assertStringContainsString('AGENTS.md instructions', $html);
        $this->assertStringContainsString('Skills registry', $html);
        $this->assertStringContainsString('You are a helpful assistant.', $html);
    }

    #[Test]
    public function htmlIncludesNonStandardEventAsRawJson(): void
    {
        // Events with types not explicitly handled by the friendly renderer
        // must still appear as event cards with full JSON.
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'context_compacted', [
                'compacted_entries' => 5,
                'summary' => 'Previous conversation compacted.',
            ]),
            $this->makeEvent(2, 1, 'model_notification', [
                'message' => 'Rate limit approaching.',
            ]),
        ]);

        $path = $this->projectDir.'/non-standard-events.html';
        $handler = $this->createHandler('test-session');
        $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $html = file_get_contents($path);

        // Every event must get a Raw event block.
        $rawEventCount = substr_count($html, '<summary>Raw event</summary>');
        $this->assertSame(2, $rawEventCount, 'Even non-standard events must produce Raw event blocks');

        // Event cards with the correct type in metadata.
        $this->assertStringContainsString('<span class="event-type">context_compacted</span>', $html);
        $this->assertStringContainsString('<span class="event-type">model_notification</span>', $html);

        // The full JSON for the non-standard event must be present (escaped).
        $this->assertStringContainsString('Previous conversation compacted.', $html);
        $this->assertStringContainsString('Rate limit approaching.', $html);
    }

    // ── Thinking block rendering ───────────────────────────────────────────

    #[Test]
    public function rendersThinkingBlockWhenPresent(): void
    {
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'user_messages' => [['role' => 'user', 'content' => 'Think about it']],
            ]),
            $this->makeEvent(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'text' => 'Here is the answer.',
                'stop_reason' => 'end_turn',
                'details' => ['thinking' => 'Hmm, let me reason about this...'],
            ]),
        ]);

        $path = $this->projectDir.'/thinking-render.html';
        $handler = $this->createHandler('test-session');
        $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $html = file_get_contents($path);
        $this->assertStringContainsString('Thinking', $html);
        $this->assertStringContainsString('Hmm, let me reason about this', $html);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Create the events.jsonl file for a session in the test project dir.
     *
     * @param array<int, array<string, mixed>> $events
     */
    private function setupEventsFile(string $sessionId, array $events = []): void
    {
        $sessionDir = $this->getSessionsDir().'/'.$sessionId;
        @mkdir($sessionDir, 0777, true);

        $lines = '';
        foreach ($events as $event) {
            $lines .= json_encode($event, \JSON_THROW_ON_ERROR)."\n";
        }
        file_put_contents($sessionDir.'/events.jsonl', $lines);
    }

    private function setupEmptyEventsFile(string $sessionId): void
    {
        $sessionDir = $this->getSessionsDir().'/'.$sessionId;
        @mkdir($sessionDir, 0777, true);
        file_put_contents($sessionDir.'/events.jsonl', '');
    }

    private function getSessionsDir(): string
    {
        return $this->projectDir.'/.hatfield/sessions';
    }

    private function getEventsPath(string $sessionId): string
    {
        return $this->getSessionsDir().'/'.$sessionId.'/events.jsonl';
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function makeEvent(int $seq, int $turnNo, string $type, array $payload = []): array
    {
        return [
            'schema_version' => '1.0',
            'run_id' => 'test-session',
            'seq' => $seq,
            'turn_no' => $turnNo,
            'type' => $type,
            'payload' => $payload,
            'ts' => '2026-01-01T00:00:00+00:00',
        ];
    }

    private function createHandler(string $sessionId): ExportCommandHandler
    {
        $state = new TuiSessionState($sessionId);

        // Construct real HatfieldSessionStore with cwd pointing to our temp dir,
        // so resolveSessionsBasePath() returns <projectDir>/.hatfield/sessions.
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
            sessions: new SessionsConfig(path: '.hatfield/sessions'),
        );
        $sessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(EntityManagerInterface::class),
        );

        return new ExportCommandHandler($state, $sessionStore);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                @chmod($file->getPathname(), 0644);
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }
}
