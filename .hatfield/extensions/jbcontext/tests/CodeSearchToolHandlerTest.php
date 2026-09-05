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
        (new JbcontextStatusStore($paths->statusPath))->write(JbcontextSessionState::pending());
        $exec = new RecordingExec();
        $handler = new CodeSearchToolHandler(
            new JbcontextStatusStore($paths->statusPath),
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
        (new JbcontextStatusStore($paths->statusPath))->write(new JbcontextSessionState(
            mode: JbcontextSessionModeEnum::Eligible,
            reason: null,
            statusText: 'jbcontext: indexed',
            attempt: 1,
            nextRetryAt: null,
            reindexPending: false,
            reindexRunning: false,
            updatedAt: microtime(true),
        ));

        $payload = [
            'type' => 'search_result',
            'results' => [
                [
                    'result' => [
                        'scoredText' => ['similarity' => 0.9],
                        'sourcePosition' => ['relativePath' => 'src/A.php', 'startOffset' => 1, 'endOffset' => 2],
                        'indexItemType' => 'CHUNKS',
                    ],
                    'content' => 'class A {}',
                    'contentStartLine' => 4,
                ],
            ],
            'revision' => 'rev1',
        ];
        $exec = new RecordingExec([
            new ExecResultDTO(stdout: json_encode($payload, \JSON_THROW_ON_ERROR), stderr: '', exitCode: 0),
        ]);
        $handler = new CodeSearchToolHandler(
            new JbcontextStatusStore($paths->statusPath),
            new JbcontextCli($exec, $paths->projectRoot),
            new TestLogger(),
        );

        $result = $handler([
            'text' => 'class A',
            'path_filter' => 'src',
        ], new ToolInvocationContextDTO(runId: 'run-1', timeoutSeconds: 12));

        $decoded = Toon::decode($result);
        $this->assertTrue($decoded['available']);
        $this->assertSame('src/A.php', $decoded['results'][0]['path']);
        $this->assertSame(4, $decoded['results'][0]['start_line']);
        $this->assertSame('class A {}', $decoded['results'][0]['content']);
        $this->assertSame('search', $exec->calls()[0]['args'][0]);
        $this->assertContains('--path-filter', $exec->calls()[0]['args']);
        $this->assertSame(12.0, $exec->calls()[0]['timeout']);
    }

    #[Test]
    public function rejectsAbsolutePathFilter(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        (new JbcontextStatusStore($paths->statusPath))->write(new JbcontextSessionState(
            mode: JbcontextSessionModeEnum::Eligible,
            reason: null,
            statusText: 'jbcontext: indexed',
            attempt: 1,
            nextRetryAt: null,
            reindexPending: false,
            reindexRunning: false,
            updatedAt: microtime(true),
        ));
        $exec = new RecordingExec();
        $handler = new CodeSearchToolHandler(
            new JbcontextStatusStore($paths->statusPath),
            new JbcontextCli($exec, $paths->projectRoot),
            new TestLogger(),
        );

        $result = $handler([
            'text' => 'x',
            'path_filter' => '/etc',
        ], new ToolInvocationContextDTO(runId: 'run-1'));

        $decoded = Toon::decode($result);
        $this->assertFalse($decoded['available']);
        $this->assertSame([], $exec->calls());
    }
}
