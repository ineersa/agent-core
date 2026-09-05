<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests;

use HelgeSverre\Toon\Toon;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\HatfieldExt\Jbcontext\Cli\JbcontextCli;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionLocator;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionModeEnum;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionState;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextStatusStore;
use Ineersa\HatfieldExt\Jbcontext\Tests\Support\RecordingExec;
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
        JbcontextStatusStore::forSession($paths, 'run-1')->write(JbcontextSessionState::pending('run-1'));
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
        JbcontextStatusStore::forSession($paths, 'run-1')->write(new JbcontextSessionState(
            sessionId: 'run-1',
            mode: JbcontextSessionModeEnum::Eligible,
            reason: null,
            statusText: 'jbcontext: indexed',
            attempt: 1,
            startedAt: 1.0,
            reindexPending: false,
            reindexRunning: false,
            eligibilityStarted: true,
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
}
