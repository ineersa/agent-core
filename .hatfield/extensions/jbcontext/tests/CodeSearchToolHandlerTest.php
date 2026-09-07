<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests;

use HelgeSverre\Toon\Toon;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\HatfieldExt\Jbcontext\Cli\JbcontextCli;
use Ineersa\HatfieldExt\Jbcontext\Cli\JbcontextCliDiagnostic;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionLocator;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionModeEnum;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionState;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextStatusStore;
use Ineersa\HatfieldExt\Jbcontext\Tests\Support\RecordingExec;
use Ineersa\HatfieldExt\Jbcontext\Tests\Support\StatusFixtures;
use Ineersa\HatfieldExt\Jbcontext\Tool\CodeSearchToolHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CodeSearchToolHandlerTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('jbcontext-tool-');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    #[Test]
    public function returnsUnavailableWhenPendingAndNeverSearches(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        StatusFixtures::replace(JbcontextStatusStore::forSession($paths, 'run-1'), JbcontextSessionState::pending('run-1'));
        $exec = new RecordingExec();
        $handler = new CodeSearchToolHandler(
            $paths,
            new JbcontextSessionLocator(),
            new JbcontextCli($exec, $paths->projectRoot),
            new TestLogger(),
        );

        $result = $handler([
            'text' => 'auth flow',
        ], new ToolInvocationContextDTO(runId: 'run-1'));

        $this->assertIsString($result);
        $decoded = Toon::decode($result);
        $this->assertFalse($decoded['available']);
        $this->assertSame([], $exec->calls());
    }

    #[Test]
    public function returnsToonHitsWhenEligible(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        StatusFixtures::replace(JbcontextStatusStore::forSession($paths, 'run-1'), new JbcontextSessionState(
            sessionId: 'run-1',
            mode: JbcontextSessionModeEnum::Eligible,
            reason: null,
            attempt: 1,
            startedAt: 1.0,
            reindexPending: false,
            reindexRunning: false,
            eligibilityStarted: true,
            checkGeneration: 1,
            updatedAt: 1.0,
        ));

        $payload = [
            'type' => 'search_result',
            'results' => [
                [
                    'result' => [
                        'scoredText' => ['similarity' => 0.9],
                        'sourcePosition' => [
                            'relativePath' => 'src/Example.php',
                            'startOffset' => 10,
                            'endOffset' => 40,
                        ],
                        'indexItemType' => 'CHUNKS',
                    ],
                    'content' => "class Example\n{\n}",
                    'contentStartLine' => 12,
                ],
            ],
            'revision' => 'abc',
        ];
        $exec = new RecordingExec([
            new ExecResultDTO(stdout: json_encode($payload, \JSON_THROW_ON_ERROR), stderr: '', exitCode: 0),
        ]);
        $handler = new CodeSearchToolHandler(
            $paths,
            new JbcontextSessionLocator(),
            new JbcontextCli($exec, $paths->projectRoot),
            new TestLogger(),
        );

        $result = $handler([
            'text' => 'Example class',
        ], new ToolInvocationContextDTO(runId: 'run-1'));

        $this->assertIsString($result);
        $decoded = Toon::decode($result);
        $this->assertTrue($decoded['available']);
        $this->assertSame('src/Example.php', $decoded['results'][0]['path']);
        $this->assertSame(12, $decoded['results'][0]['start_line']);
    }

    #[Test]
    public function filtersHeaderOnlyNoiseFromEligibleSearchHits(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        StatusFixtures::replace(JbcontextStatusStore::forSession($paths, 'run-1'), new JbcontextSessionState(
            sessionId: 'run-1',
            mode: JbcontextSessionModeEnum::Eligible,
            reason: null,
            attempt: 1,
            startedAt: 1.0,
            reindexPending: false,
            reindexRunning: false,
            eligibilityStarted: true,
            checkGeneration: 1,
            updatedAt: 1.0,
        ));

        $payload = [
            'type' => 'search_result',
            'results' => [
                [
                    'result' => [
                        'scoredText' => ['similarity' => 0.99],
                        'sourcePosition' => [
                            'relativePath' => 'src/Noise.php',
                            'startOffset' => 0,
                            'endOffset' => 20,
                        ],
                        'indexItemType' => 'CHUNKS',
                    ],
                    'content' => "<?php\n\ndeclare(strict_types=1);",
                    'contentStartLine' => 1,
                ],
                [
                    'result' => [
                        'scoredText' => ['similarity' => 0.9],
                        'sourcePosition' => [
                            'relativePath' => 'src/Example.php',
                            'startOffset' => 10,
                            'endOffset' => 40,
                        ],
                        'indexItemType' => 'CHUNKS',
                    ],
                    'content' => "class Example\n{\n}",
                    'contentStartLine' => 12,
                ],
            ],
            'revision' => 'abc',
        ];
        $exec = new RecordingExec([
            new ExecResultDTO(stdout: json_encode($payload, \JSON_THROW_ON_ERROR), stderr: '', exitCode: 0),
        ]);
        $handler = new CodeSearchToolHandler(
            $paths,
            new JbcontextSessionLocator(),
            new JbcontextCli($exec, $paths->projectRoot),
            new TestLogger(),
        );

        $result = $handler([
            'text' => 'Example class',
        ], new ToolInvocationContextDTO(runId: 'run-1'));

        $this->assertIsString($result);
        $decoded = Toon::decode($result);
        $this->assertTrue($decoded['available']);
        $this->assertCount(1, $decoded['results']);
        $this->assertSame('src/Example.php', $decoded['results'][0]['path']);
    }

    #[Test]
    public function rejectsBlankTextAndInvalidPathFilterWithoutSearching(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        StatusFixtures::replace(JbcontextStatusStore::forSession($paths, 'run-1'), new JbcontextSessionState(
            sessionId: 'run-1',
            mode: JbcontextSessionModeEnum::Eligible,
            reason: null,
            attempt: 1,
            startedAt: 1.0,
            reindexPending: false,
            reindexRunning: false,
            eligibilityStarted: true,
            checkGeneration: 1,
            updatedAt: 1.0,
        ));
        $exec = new RecordingExec();
        $handler = new CodeSearchToolHandler(
            $paths,
            new JbcontextSessionLocator(),
            new JbcontextCli($exec, $paths->projectRoot),
            new TestLogger(),
        );

        $blank = Toon::decode((string) $handler([
            'text' => "  \n\t",
        ], new ToolInvocationContextDTO(runId: 'run-1')));
        $this->assertFalse($blank['available']);
        $this->assertSame('text is required and must be a non-empty string.', $blank['message']);

        $absolute = Toon::decode((string) $handler([
            'text' => 'Example class',
            'path_filter' => '/tmp/evil',
        ], new ToolInvocationContextDTO(runId: 'run-1')));
        $this->assertFalse($absolute['available']);
        $this->assertSame('path_filter must be project-relative, not absolute.', $absolute['message']);
        $this->assertSame([], $exec->calls());
    }

    #[Test]
    public function returnsBoundedCliStderrOnSearchFailureAndLogsOnlyStableCode(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        StatusFixtures::replace(JbcontextStatusStore::forSession($paths, 'run-1'), new JbcontextSessionState(
            sessionId: 'run-1',
            mode: JbcontextSessionModeEnum::Eligible,
            reason: null,
            attempt: 1,
            startedAt: 1.0,
            reindexPending: false,
            reindexRunning: false,
            eligibilityStarted: true,
            checkGeneration: 1,
            updatedAt: 1.0,
        ));

        $secret = 'token=super-secret-value';
        $stderr = "Authentication required\n".$secret;
        $exec = new RecordingExec([
            new ExecResultDTO(
                stdout: '',
                stderr: $stderr,
                exitCode: 1,
            ),
        ]);
        $logger = new TestLogger();
        $handler = new CodeSearchToolHandler(
            $paths,
            new JbcontextSessionLocator(),
            new JbcontextCli($exec, $paths->projectRoot),
            $logger,
        );

        $result = $handler([
            'text' => 'Example class',
        ], new ToolInvocationContextDTO(runId: 'run-1'));

        $this->assertIsString($result);
        $decoded = Toon::decode($result);
        $this->assertFalse($decoded['available']);
        $this->assertSame(JbcontextCliDiagnostic::format($stderr), $decoded['message']);
        $this->assertSame('empty_stdout', $decoded['error']);
        $this->assertStringContainsString('Authentication required', $result);
        $this->assertStringContainsString($secret, $result);

        $records = $logger->records;
        $this->assertNotEmpty($records);
        $failed = null;
        foreach ($records as $record) {
            if (($record['context']['event_type'] ?? null) === 'jbcontext.search.failed') {
                $failed = $record;
                break;
            }
        }
        $this->assertNotNull($failed);
        $this->assertSame('empty_stdout', $failed['context']['error']);
        $encodedLog = json_encode($failed, \JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($secret, $encodedLog);
        $this->assertStringNotContainsString('Authentication required', $encodedLog);
        $this->assertStringNotContainsString('JB Context error', $encodedLog);
    }

    #[Test]
    public function disabledStateSurfacesStoredCliDetailToModel(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        $message = 'jbcontext disabled: '.JbcontextCliDiagnostic::format('Authentication required');
        StatusFixtures::replace(JbcontextStatusStore::forSession($paths, 'run-1'), new JbcontextSessionState(
            sessionId: 'run-1',
            mode: JbcontextSessionModeEnum::Disabled,
            reason: $message,
            attempt: 1,
            startedAt: 1.0,
            reindexPending: false,
            reindexRunning: false,
            eligibilityStarted: true,
            checkGeneration: 1,
            updatedAt: 1.0,
        ));
        $exec = new RecordingExec();
        $handler = new CodeSearchToolHandler(
            $paths,
            new JbcontextSessionLocator(),
            new JbcontextCli($exec, $paths->projectRoot),
            new TestLogger(),
        );

        $result = $handler([
            'text' => 'Example class',
        ], new ToolInvocationContextDTO(runId: 'run-1'));

        $this->assertIsString($result);
        $decoded = Toon::decode($result);
        $this->assertFalse($decoded['available']);
        $this->assertSame($message, $decoded['message']);
        $this->assertSame([], $exec->calls());
    }

    #[Test]
    public function returnsGenericSearchFailureWhenStderrEmpty(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        StatusFixtures::replace(JbcontextStatusStore::forSession($paths, 'run-1'), new JbcontextSessionState(
            sessionId: 'run-1',
            mode: JbcontextSessionModeEnum::Eligible,
            reason: null,
            attempt: 1,
            startedAt: 1.0,
            reindexPending: false,
            reindexRunning: false,
            eligibilityStarted: true,
            checkGeneration: 1,
            updatedAt: 1.0,
        ));

        $exec = new RecordingExec([
            new ExecResultDTO(stdout: '', stderr: '', exitCode: 1),
        ]);
        $handler = new CodeSearchToolHandler(
            $paths,
            new JbcontextSessionLocator(),
            new JbcontextCli($exec, $paths->projectRoot),
            new TestLogger(),
        );

        $result = $handler([
            'text' => 'Example class',
        ], new ToolInvocationContextDTO(runId: 'run-1'));

        $this->assertIsString($result);
        $decoded = Toon::decode($result);
        $this->assertFalse($decoded['available']);
        $this->assertSame('jbcontext search failed. Check CLI status and try again.', $decoded['message']);
        $this->assertSame('empty_stdout', $decoded['error']);
    }
}
