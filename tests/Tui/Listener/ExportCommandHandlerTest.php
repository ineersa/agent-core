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
use Ineersa\Tui\Tests\Support\SessionEventsExportServiceFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

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
                'payload' => ['messages' => [['role' => 'user', 'content' => 'Hi']]],
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
                'payload' => ['messages' => [['role' => 'user', 'content' => 'Hi']]],
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
                'payload' => ['messages' => [['role' => 'user', 'content' => 'Hi']]],
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
                'payload' => ['messages' => [['role' => 'user', 'content' => 'Hi']]],
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
                'payload' => ['messages' => [['role' => 'user', 'content' => 'Hello world']]],
            ]),
            $this->makeEvent(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'The sky is blue.']],
                ],
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
    public function exportsAvailableToolsSectionOutsideRawEvent(): void
    {
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => [['role' => 'user', 'content' => 'List tools']]],
            ]),
            $this->makeEvent(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Tools are available.']],
                ],
                'stop_reason' => 'end_turn',
                'available_tools' => [
                    'read',
                    'websearch_search',
                    'evil<script>',
                ],
                'available_tools_schema_tokens_estimate' => 1234,
            ]),
            $this->makeEvent(3, 1, 'agent_end', [
                'reason' => 'completed',
            ]),
        ]);

        $path = $this->projectDir.'/available-tools-export.html';
        $handler = $this->createHandler('test-session');
        $result = $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Session exported', $result->text);
        $this->assertFileExists($path);

        $html = (string) file_get_contents($path);
        $this->assertStringContainsString('Available tools', $html);
        $this->assertStringContainsString('class="available-tools"', $html);
        $this->assertStringContainsString('~1,234 schema tokens', $html);
        $this->assertStringContainsString('<li>read</li>', $html);
        $this->assertStringContainsString('<li>websearch_search</li>', $html);
        $this->assertStringContainsString('<li>evil&lt;script&gt;</li>', $html);
        $this->assertStringNotContainsString('MCP server:', $html);
        $this->assertStringNotContainsString('tool-server', $html);
        $this->assertStringContainsString('Effective model context', $html);
        $this->assertStringNotContainsString('<summary>Raw event</summary>', $html);

        // Old-event absence path remains unchanged: events without snapshot still export.
        $this->setupEventsFile('old-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => [['role' => 'user', 'content' => 'Hi']]],
            ]),
            $this->makeEvent(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'No tools snapshot.']],
                ],
                'stop_reason' => 'end_turn',
            ]),
        ]);
        $oldPath = $this->projectDir.'/old-session-export.html';
        $oldResult = $this->createHandler('old-session')->handle(new SlashCommand('export', $oldPath, '/export '.$oldPath));
        $this->assertInstanceOf(TranscriptMessage::class, $oldResult);
        $oldHtml = (string) file_get_contents($oldPath);
        $this->assertStringNotContainsString('class="available-tools"', $oldHtml);
        $this->assertStringContainsString('No tools snapshot.', $oldHtml);
    }

    #[Test]
    public function htmlExportRendersLiveToolDefinitionsOnceAfterSystemInstructions(): void
    {
        $instructionText = str_repeat('You are an assistant. Follow the rules. ', 15);
        $schema = [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'File <path>'],
            ],
            'required' => ['path'],
            'additionalProperties' => false,
        ];
        $tools = [
            new Tool(
                new ExecutionReference(self::class),
                'read',
                'Read a text file <carefully>',
                $schema,
            ),
            new Tool(
                new ExecutionReference(self::class),
                'bash',
                'Run shell commands',
                null,
            ),
        ];

        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => [
                    'messages' => [
                        ['role' => 'system', 'content' => [['type' => 'text', 'text' => $instructionText]]],
                        ['role' => 'user-context', 'content' => [['type' => 'text', 'text' => 'Context info']]],
                        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello']]],
                    ],
                ],
            ]),
            $this->makeEvent(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Response.']],
                ],
                'stop_reason' => 'end_turn',
                'available_tools' => ['read', 'bash'],
                'available_tools_schema_tokens_estimate' => 42,
            ]),
            $this->makeEvent(3, 2, 'llm_step_completed', [
                'step_id' => 's3',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Second turn.']],
                ],
                'stop_reason' => 'end_turn',
                'available_tools' => ['read', 'bash'],
                'available_tools_schema_tokens_estimate' => 42,
            ]),
        ]);

        $path = $this->projectDir.'/live-tool-definitions.html';
        $handler = $this->createHandler('test-session', $this->createToolbox($tools));
        $result = $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $html = (string) file_get_contents($path);

        $this->assertSame(1, substr_count($html, 'class="tool-definitions"'));
        $this->assertStringContainsString('Tool definitions (2)', $html);
        $this->assertStringContainsString('class="tool-definition-name">read</div>', $html);
        $this->assertStringContainsString('class="tool-definition-name">bash</div>', $html);
        $this->assertStringContainsString('Read a text file &lt;carefully&gt;', $html);
        $this->assertStringContainsString('Run shell commands', $html);
        $this->assertStringContainsString('File &lt;path&gt;', $html);
        $this->assertStringContainsString('&quot;additionalProperties&quot;: false', $html);
        // null parameters render as empty object, not null/empty section.
        $this->assertMatchesRegularExpression(
            '/tool-definition-name">bash<\/div>\s*<div class="tool-definition-description">Run shell commands<\/div>\s*<pre class="pretty-json">\{\}<\/pre>/s',
            $html,
        );

        $systemPos = strpos($html, 'System instructions');
        $toolsPos = strpos($html, 'class="tool-definitions"');
        $contextPos = strpos($html, 'Context info');
        $userPos = strpos($html, 'Hello');
        $this->assertNotFalse($systemPos);
        $this->assertNotFalse($toolsPos);
        $this->assertNotFalse($contextPos);
        $this->assertNotFalse($userPos);
        $this->assertLessThan($toolsPos, $systemPos);
        $this->assertLessThan($contextPos, $toolsPos);
        $this->assertLessThan($userPos, $toolsPos);

        // Latest available_tools snapshot only (effective context, not per-event cards).
        $this->assertSame(1, substr_count($html, 'class="available-tools"'));
    }

    #[Test]
    public function htmlExportOmitsToolDefinitionsWhenToolboxEmpty(): void
    {
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => [
                    'messages' => [
                        ['role' => 'system', 'content' => [['type' => 'text', 'text' => str_repeat('System. ', 80)]]],
                        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]],
                    ],
                ],
            ]),
        ]);

        $path = $this->projectDir.'/no-tool-definitions.html';
        $handler = $this->createHandler('test-session', $this->createToolbox([]));
        $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $html = (string) file_get_contents($path);
        $this->assertStringNotContainsString('class="tool-definitions"', $html);
        $this->assertStringNotContainsString('Tool definitions (', $html);
        $this->assertStringContainsString('System instructions', $html);
    }

    #[Test]
    public function jsonlExportDoesNotInjectLiveToolDefinitions(): void
    {
        $tools = [
            new Tool(
                new ExecutionReference(self::class),
                'read',
                'Read a text file',
                ['type' => 'object'],
            ),
        ];

        $eventsData = [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => [['role' => 'user', 'content' => 'Hello']]],
            ]),
            $this->makeEvent(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Response.']],
                ],
                'stop_reason' => 'end_turn',
            ]),
        ];
        $this->setupEventsFile('test-session', $eventsData, false);

        $sourcePath = $this->getEventsPath('test-session');
        $source = (string) file_get_contents($sourcePath);

        $path = $this->projectDir.'/export-live-tools.jsonl';
        $handler = $this->createHandler('test-session', $this->createToolbox($tools));
        $result = $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame($source, (string) file_get_contents($path));
        $this->assertStringNotContainsString('Tool definitions', (string) file_get_contents($path));
        $this->assertStringNotContainsString('Read a text file', (string) file_get_contents($path));
    }

    #[Test]
    public function exportsHtmlToGivenPath(): void
    {
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => [['role' => 'user', 'content' => 'Hello']]],
            ]),
            $this->makeEvent(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Response text.']],
                ],
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
                'payload' => ['messages' => [['role' => 'user', 'content' => 'Hello']]],
            ]),
            $this->makeEvent(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Response.']],
                ],
                'stop_reason' => 'end_turn',
            ]),
        ];
        $this->setupEventsFile('test-session', $eventsData, false);

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

    #[Test]
    public function jsonlExportDoesNotLoadSessionMetadataFromDatabase(): void
    {
        $sessionId = '4242';
        $this->setupEventsFile($sessionId, [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => [['role' => 'user', 'content' => 'Hello']]],
            ]),
        ]);

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
            sessions: new SessionsConfig(path: '.hatfield/sessions'),
        );
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('find');
        $sessionStore = new HatfieldSessionStore($appConfig, $entityManager, new \Symfony\Component\EventDispatcher\EventDispatcher());

        $handler = new ExportCommandHandler(
            new TuiSessionState($sessionId),
            $sessionStore,
            SessionEventsExportServiceFactory::create(),
        );

        $path = $this->projectDir.'/no-db-metadata.jsonl';
        $result = $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('no-db-metadata.jsonl', $result->text);
        $this->assertFileExists($path);
    }

    // ── HTML content escaping ──────────────────────────────────────────────

    #[Test]
    public function htmlExportEscapesScriptTags(): void
    {
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => [
                    ['role' => 'user', 'content' => '<script>alert("xss")</script>'],
                ]],
            ]),
            $this->makeEvent(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => '<img src=x onerror=alert(1)>']],
                ],
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
                'payload' => ['messages' => [['role' => 'user', 'content' => 'Run tool']]],
            ]),
            $this->makeEvent(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'stop_reason' => 'tool_call',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [],
                    'tool_calls' => [
                        ['id' => 'tc1', 'name' => 'bash', 'arguments' => '{"command":"echo"}'],
                    ],
                ],
            ]),
            $this->makeEvent(3, 1, 'tool_execution_start', [
                'tool_call_id' => 'tc1',
                'tool_name' => 'bash',
                'order_index' => 0,
            ]),
            $this->makeEvent(4, 1, 'tool_execution_end', $this->toolEndPayload(
                toolCallId: 'tc1',
                toolName: 'bash',
                text: '<div>Injected HTML</div>',
            )),
            $this->makeEvent(5, 1, 'tool_batch_committed', [
                'step_id' => 's2',
                'turn_no' => 1,
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
                'payload' => ['messages' => [['role' => 'user', 'content' => 'List files']]],
            ]),
            $this->makeEvent(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'stop_reason' => 'tool_call',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [],
                    'tool_calls' => [
                        ['id' => 'tc-ls', 'name' => 'bash', 'arguments' => '{"command":"ls"}'],
                    ],
                ],
            ]),
            $this->makeEvent(3, 1, 'tool_execution_start', [
                'tool_call_id' => 'tc-ls',
                'tool_name' => 'bash',
                'order_index' => 0,
            ]),
            $this->makeEvent(4, 1, 'tool_execution_end', $this->toolEndPayload(
                toolCallId: 'tc-ls',
                toolName: 'bash',
                text: "file1.txt\nfile2.txt",
            )),
            $this->makeEvent(5, 1, 'tool_batch_committed', [
                'step_id' => 's2',
                'turn_no' => 1,
            ]),
        ]);

        $path = $this->projectDir.'/tool-render.html';
        $handler = $this->createHandler('test-session');
        $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $html = (string) file_get_contents($path);
        $this->assertStringContainsString('bash', $html, 'Tool name should appear in output');
        $this->assertStringContainsString('file1.txt', $html);
        $this->assertStringContainsString('List files', $html);
        $this->assertStringContainsString('message-tool', $html);
    }

    // ── Complete event representation ─────────────────────────────────────

    #[Test]
    public function htmlRendersEffectiveContextNotRawEventCards(): void
    {
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => [['role' => 'user', 'content' => 'Hello']]],
            ]),
            $this->makeEvent(2, 1, 'turn_advanced', ['turn_no' => 1]),
            $this->makeEvent(3, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Response.']],
                ],
                'stop_reason' => 'end_turn',
            ]),
            $this->makeEvent(4, 1, 'agent_end', ['reason' => 'completed']),
        ]);

        $path = $this->projectDir.'/effective-context.html';
        $handler = $this->createHandler('test-session');
        $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $html = (string) file_get_contents($path);
        $this->assertStringContainsString('Effective model context', $html);
        $this->assertStringContainsString('Hello', $html);
        $this->assertStringContainsString('Response.', $html);
        $this->assertStringNotContainsString('<summary>Raw event</summary>', $html);
        $this->assertStringNotContainsString('class="event event-', $html);
    }

    #[Test]
    public function htmlIncludesUserMessagesContent(): void
    {
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => [
                    ['role' => 'user', 'content' => 'What is the capital of France?'],
                ]],
            ]),
            $this->makeEvent(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'The capital of France is Paris.']],
                ],
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
                'payload' => ['messages' => [
                    ['role' => 'system', 'content' => $instructionText],
                    ['role' => 'user', 'content' => 'Hello'],
                ]],
            ]),
        ]);

        $path = $this->projectDir.'/instruction-content.html';
        $handler = $this->createHandler('test-session');
        $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $html = file_get_contents($path);

        // Instruction/AGENTS.md/skills registry content must appear in the HTML.
        // It appears in the friendly rendering (run_started extracts all canonical
        // payload.payload.messages regardless of role) and in the full JSON block.
        $this->assertStringContainsString('AGENTS.md instructions', $html);
        $this->assertStringContainsString('Skills registry', $html);
        $this->assertStringContainsString('You are a helpful assistant.', $html);
    }

    #[Test]
    public function htmlRendersCompactionSummaryAndOmMetadata(): void
    {
        $summary = "These are condensed memories from earlier in this session.\n\n- Reflections: keep shipping.";
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => [
                    ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'System prompt']]],
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'OLD_HISTORY_SHOULD_NOT_APPEAR']]],
                ]],
            ]),
            $this->makeEvent(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'OLD_ASSISTANT_SHOULD_NOT_APPEAR']],
                ],
                'stop_reason' => 'end_turn',
                'available_tools' => ['bash'],
                'available_tools_schema_tokens_estimate' => 11,
            ]),
            $this->makeEvent(3, 1, 'context_compacted', [
                'trigger' => 'manual',
                'summary_text' => $summary,
                'messages_compacted' => 2,
                'messages_retained' => 2,
                'estimated_tokens_before' => 9000,
                'estimated_tokens_after' => 1200,
                'replacement_summary' => true,
                'hook_metadata' => [
                    'om_source' => 'observational_memory',
                    'om_projection' => 'active_durable_memory',
                    'om_has_coverage_watermark' => true,
                ],
                'messages' => [
                    ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'System prompt']], 'is_error' => false],
                    [
                        'role' => 'user',
                        'content' => [['type' => 'text', 'text' => 'The conversation history before this point was compacted into the following handoff summary.\n\n<summary>\n'.$summary.'\n</summary>']],
                        'is_error' => false,
                        'metadata' => ['compact_summary' => true],
                    ],
                ],
            ]),
            $this->makeEvent(4, 1, 'agent_command_applied', [
                'kind' => 'follow_up',
                'message' => [
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => 'POST_COMPACTION_USER']],
                ],
            ]),
            $this->makeEvent(5, 1, 'llm_step_completed', [
                'step_id' => 's3',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'POST_COMPACTION_ASSISTANT']],
                ],
                'stop_reason' => 'end_turn',
                'available_tools' => ['read', 'bash'],
                'available_tools_schema_tokens_estimate' => 42,
            ]),
        ]);

        $path = $this->projectDir.'/compaction-context.html';
        $handler = $this->createHandler('test-session');
        $result = $handler->handle(new SlashCommand('export', $path, '/export '.$path));
        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Session exported', $result->text);

        $html = (string) file_get_contents($path);
        $this->assertStringContainsString('Compaction checkpoint', $html);
        $this->assertStringContainsString('Observational memory', $html);
        $this->assertStringContainsString('observational_memory', $html);
        $this->assertStringContainsString('active_durable_memory', $html);
        $this->assertStringContainsString('condensed memories from earlier in this session', $html);
        $this->assertStringContainsString('OM-backed compaction summary in model context', $html);
        $this->assertStringContainsString('POST_COMPACTION_USER', $html);
        $this->assertStringContainsString('POST_COMPACTION_ASSISTANT', $html);
        $this->assertStringContainsString('<li>read</li>', $html);
        $this->assertStringContainsString('~42 schema tokens', $html);
        $this->assertStringNotContainsString('OLD_HISTORY_SHOULD_NOT_APPEAR', $html);
        $this->assertStringNotContainsString('OLD_ASSISTANT_SHOULD_NOT_APPEAR', $html);
    }

    // ── Thinking block rendering ───────────────────────────────────────────

    #[Test]
    public function rendersThinkingBlockWhenPresent(): void
    {
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => [['role' => 'user', 'content' => 'Think about it']]],
            ]),
            $this->makeEvent(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Here is the answer.']],
                    'details' => ['thinking' => 'Hmm, let me reason about this...'],
                ],
                'stop_reason' => 'end_turn',
            ]),
        ]);

        $path = $this->projectDir.'/thinking-render.html';
        $handler = $this->createHandler('test-session');
        $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $html = file_get_contents($path);
        $this->assertStringContainsString('Thinking', $html);
        $this->assertStringContainsString('Hmm, let me reason about this', $html);
    }

    // ── Real events.jsonl format (nested payload) ──────────────────────────

    #[Test]
    public function rendersMessagesFromNestedPayloadFormat(): void
    {
        // Real events.jsonl stores messages at payload.payload.messages
        // with content as typed blocks [{type: 'text', text: '...'}].
        // Long system messages (>500 chars) get a labelled details/summary section.
        $instructionText = str_repeat('You are an assistant. Follow the rules. ', 15);
        $userText = 'Hello world';

        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => [
                    'messages' => [
                        ['role' => 'system', 'content' => [['type' => 'text', 'text' => $instructionText]]],
                        ['role' => 'user-context', 'content' => [['type' => 'text', 'text' => 'Context info']]],
                        ['role' => 'user', 'content' => [['type' => 'text', 'text' => $userText]]],
                    ],
                ],
            ]),
            $this->makeEvent(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Response.']],
                ],
                'stop_reason' => 'end_turn',
            ]),
        ]);

        $path = $this->projectDir.'/nested-messages.html';
        $handler = $this->createHandler('test-session');
        $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $html = file_get_contents($path);

        // System instruction should be visible with long-content treatment.
        $this->assertStringContainsString('System instructions', $html);
        $this->assertStringContainsString($instructionText, $html);

        // User-context role.
        $this->assertStringContainsString('Context info', $html);

        // User message.
        $this->assertStringContainsString($userText, $html);
        $this->assertStringContainsString('<div class="message-role">user</div>', $html);
    }

    #[Test]
    public function rendersThinkingFromAssistantMessageDetails(): void
    {
        // Real events.jsonl stores thinking at
        // payload.assistant_message.details.thinking.
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => [['role' => 'user', 'content' => 'Think']]],
            ]),
            $this->makeEvent(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'stop_reason' => 'end_turn',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Answer.']],
                    'details' => ['thinking' => 'Let me reason step by step...'],
                ],
            ]),
        ]);

        $path = $this->projectDir.'/thinking-nested.html';
        $handler = $this->createHandler('test-session');
        $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $html = file_get_contents($path);
        $this->assertStringContainsString('Thinking', $html);
        $this->assertStringContainsString('Let me reason step by step', $html);
        // Should be in a thinking-block details.
        $this->assertStringContainsString('class="thinking-block"', $html);
    }

    #[Test]
    public function rendersUsageTokenStats(): void
    {
        // Context export no longer repeats per-event usage cards; keep proving assistant text remains.
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => [['role' => 'user', 'content' => 'Hi']]],
            ]),
            $this->makeEvent(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Hello with usage']],
                ],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
            ]),
        ]);

        $path = $this->projectDir.'/usage-render.html';
        $handler = $this->createHandler('test-session');
        $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $html = (string) file_get_contents($path);
        $this->assertStringContainsString('Hello with usage', $html);
    }

    #[Test]
    public function rendersToolCallArgumentsFromAssistantMessage(): void
    {
        // Tool call args come from llm_step_completed.assistant_message.tool_calls.
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => [['role' => 'user', 'content' => 'Run tool']]],
            ]),
            $this->makeEvent(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'stop_reason' => 'tool_call',
                'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'total_tokens' => 120],
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Calling tool...']],
                    'tool_calls' => [
                        ['id' => 'call_abc', 'name' => 'bash', 'arguments' => '{"command":"ls -la"}'],
                        ['id' => 'call_def', 'name' => 'read', 'arguments' => '{"path":"/tmp/file.txt"}'],
                    ],
                ],
            ]),
            $this->makeEvent(3, 1, 'tool_execution_start', [
                'tool_call_id' => 'call_abc',
                'tool_name' => 'bash',
                'order_index' => 0,
            ]),
            $this->makeEvent(4, 1, 'tool_execution_end', $this->toolEndPayload(
                toolCallId: 'call_abc',
                toolName: 'bash',
                text: 'total 8',
            )),
        ]);

        $path = $this->projectDir.'/tool-args.html';
        $handler = $this->createHandler('test-session');
        $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $html = file_get_contents($path);

        // Inline tool calls in assistant message should show name and args.
        $this->assertStringContainsString('📎 bash', $html);
        $this->assertStringContainsString('📎 read', $html);
        $this->assertStringContainsString('ls -la', $html);
        $this->assertStringContainsString('file.txt', $html);
        $this->assertStringContainsString('tool-call-inline', $html);
        $this->assertStringContainsString('pretty-json', $html);

        $this->assertStringContainsString('tool-args', $html);
    }

    #[Test]
    public function rendersAgentCommandAppliedAsUserMessage(): void
    {
        // Subsequent turns' user messages appear as agent_command_applied.
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => [['role' => 'user', 'content' => 'First message']]],
            ]),
            $this->makeEvent(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Response 1.']],
                ],
                'stop_reason' => 'end_turn',
            ]),
            $this->makeEvent(3, 1, 'agent_command_applied', [
                'kind' => 'follow_up',
                'message' => [
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => 'What about the capital of Spain?']],
                ],
            ]),
        ]);

        $path = $this->projectDir.'/agent-command-applied.html';
        $handler = $this->createHandler('test-session');
        $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $html = file_get_contents($path);

        $this->assertStringContainsString('First message', $html);
        $this->assertStringContainsString('What about the capital of Spain?', $html);
        // The follow-up should be labelled as 'user'.
        $this->assertStringContainsString('message-user', $html);
    }

    #[Test]
    public function unsupportedEventTypeFailsExplicitly(): void
    {
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => [['role' => 'user', 'content' => 'Hi']]],
            ]),
            [
                'schema_version' => '1.0',
                'run_id' => 'test-session',
                'seq' => 2,
                'turn_no' => 1,
                'type' => 'not_a_real_event_type',
                'payload' => ['text' => 'should fail'],
                'ts' => '2026-01-01T00:00:00+00:00',
            ],
        ]);

        $path = $this->projectDir.'/unsupported-event.html';
        $handler = $this->createHandler('test-session');
        $result = $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('error', $result->role);
        $this->assertStringContainsString('unsupported event type', $result->text);
        $this->assertStringContainsString('not_a_real_event_type', $result->text);
        $this->assertFileDoesNotExist($path);
    }

    #[Test]
    public function jsonlExportRemainsByteIdenticalToCanonicalEvents(): void
    {
        $events = [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => [['role' => 'user', 'content' => 'Hi']]],
            ]),
            $this->makeEvent(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Hello']],
                ],
                'stop_reason' => 'end_turn',
            ]),
        ];
        $this->setupEventsFile('test-session', $events, false);
        $source = $this->getEventsPath('test-session');
        $original = (string) file_get_contents($source);

        $path = $this->projectDir.'/identity-export.jsonl';
        $handler = $this->createHandler('test-session');
        $result = $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertFileExists($path);
        $this->assertSame($original, (string) file_get_contents($path));
        $this->assertSame($original, (string) file_get_contents($source));
    }

    #[Test]
    public function escapingPreservedInReadableSections(): void
    {
        // XSS must be prevented in ALL rendered sections, not just raw JSON.
        $this->setupEventsFile('test-session', [
            $this->makeEvent(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => [['role' => 'user', 'content' => '<script>evil()</script>']]],
            ]),
            $this->makeEvent(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'stop_reason' => 'end_turn',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => '<iframe src=evil>']],
                    'details' => ['thinking' => '<script>think_evil()</script>'],
                    'tool_calls' => [
                        ['id' => 'xss1', 'name' => 'bash', 'arguments' => '{"cmd":"<img onerror=alert(1)>"}'],
                    ],
                ],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
            ]),
            $this->makeEvent(3, 1, 'tool_execution_start', [
                'tool_call_id' => 'xss1',
                'tool_name' => 'bash',
                'order_index' => 0,
            ]),
            $this->makeEvent(4, 1, 'tool_execution_end', $this->toolEndPayload(
                toolCallId: 'xss1',
                toolName: 'bash',
                text: '<svg onload=alert(1)>',
            )),
            $this->makeEvent(5, 1, 'tool_batch_committed', [
                'step_id' => 's2',
                'turn_no' => 1,
            ]),
            $this->makeEvent(6, 1, 'agent_command_applied', [
                'kind' => 'follow_up',
                'message' => [
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => '<b>bold attempt</b>']],
                ],
            ]),
        ]);

        $path = $this->projectDir.'/escape-all-sections.html';
        $handler = $this->createHandler('test-session');
        $handler->handle(new SlashCommand('export', $path, '/export '.$path));

        $html = file_get_contents($path);

        // No raw script tags anywhere in the output.
        $this->assertStringNotContainsString('<script>', $html);
        // No raw iframe.
        $this->assertStringNotContainsString('<iframe', $html);
        // No raw svg onload.
        $this->assertStringNotContainsString('<svg onload', $html);
        // No raw marquee.
        $this->assertStringNotContainsString('<marquee>', $html);
        // No unescaped angle brackets.
        $this->assertStringNotContainsString('<b>bold attempt</b>', $html);

        // Escaped versions should appear.
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;iframe', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Create the events.jsonl file for a session in the test project dir.
     *
     * @param array<int, array<string, mixed>> $events
     */
    /**
     * @param list<array<string, mixed>> $events
     */
    private function setupEventsFile(string $sessionId, array $events = [], bool $anchorHistory = true): void
    {
        $sessionDir = $this->getSessionsDir().'/'.$sessionId;
        @mkdir($sessionDir, 0777, true);

        $prepared = $anchorHistory ? $this->withHistoryAnchors($events) : $events;
        $lines = '';
        foreach ($prepared as $event) {
            $event['run_id'] = $sessionId;
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

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return list<array<string, mixed>>
     */
    private function withHistoryAnchors(array $events): array
    {
        $normalized = [];
        $hasTurnAdvanced = false;
        foreach ($events as $event) {
            if (($event['type'] ?? null) === 'run_started') {
                $event['turn_no'] = 0;
            }
            if (($event['type'] ?? null) === 'turn_advanced') {
                $hasTurnAdvanced = true;
            }
            $normalized[] = $event;
        }

        if ($hasTurnAdvanced || [] === $normalized) {
            return $normalized;
        }

        $maxSeq = 0;
        foreach ($normalized as $event) {
            $maxSeq = max($maxSeq, (int) ($event['seq'] ?? 0));
        }

        // Insert a retained-history anchor after run_started so HistoryReplayFilter keeps later turn events.
        $anchor = $this->makeEvent($maxSeq + 1, 1, 'turn_advanced', ['turn_no' => 1]);
        $out = [];
        $inserted = false;
        foreach ($normalized as $event) {
            $out[] = $event;
            if (!$inserted && ($event['type'] ?? null) === 'run_started') {
                $out[] = $anchor;
                $inserted = true;
            }
        }
        if (!$inserted) {
            array_unshift($out, $anchor);
        }

        // Keep seq monotonic for readability.
        $seq = 1;
        foreach ($out as &$event) {
            $event['seq'] = $seq;
            ++$seq;
        }
        unset($event);

        return $out;
    }

    /** @return array<string, mixed> */
    private function toolEndPayload(string $toolCallId, string $toolName, string $text, int $orderIndex = 0, bool $isError = false): array
    {
        return [
            'tool_result' => [
                'run_id' => 'test-session',
                'turn_no' => 1,
                'step_id' => 'tool-end-'.$toolCallId,
                'attempt' => 1,
                'idempotency_key' => 'result-'.$toolCallId,
                'tool_call_id' => $toolCallId,
                'order_index' => $orderIndex,
                'result' => [
                    'tool_name' => $toolName,
                    'content' => [['type' => 'text', 'text' => $text]],
                ],
                'is_error' => $isError,
                'error' => null,
                'pending_human_input' => null,
            ],
        ];
    }

    private function createHandler(string $sessionId, ?ToolboxInterface $toolbox = null): ExportCommandHandler
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
            dispatcher: new \Symfony\Component\EventDispatcher\EventDispatcher(),
        );

        return new ExportCommandHandler($state, $sessionStore, SessionEventsExportServiceFactory::create($toolbox));
    }

    /**
     * @param list<Tool> $tools
     */
    private function createToolbox(array $tools): ToolboxInterface
    {
        $toolbox = $this->createStub(ToolboxInterface::class);
        $toolbox->method('getTools')->willReturn($tools);

        return $toolbox;
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
