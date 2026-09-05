<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\HatfieldExt\Jbcontext\Job\JbcontextReindexJobHandler;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionModeEnum;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionState;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextStatusStore;
use Ineersa\HatfieldExt\Jbcontext\Tests\Support\RecordingExec;
use Ineersa\HatfieldExt\Jbcontext\Tests\Support\TestExtensionApi;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JbcontextReindexCoalesceTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('jbcontext-reindex-');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    #[Test]
    public function concurrentJobKeepsPendingInsteadOfStartingSecondIndex(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        $store = JbcontextStatusStore::forSession($paths, 'run-1');
        $store->write(new JbcontextSessionState(
            sessionId: 'run-1',
            mode: JbcontextSessionModeEnum::Eligible,
            reason: null,
            statusText: 'jbcontext: refreshing index…',
            attempt: 1,
            startedAt: 1.0,
            nextRetryAt: null,
            reindexPending: true,
            reindexRunning: true,
            eligibilityStarted: true,
            updatedAt: 1.0,
        ));

        $exec = new RecordingExec([
            new ExecResultDTO(stdout: '', stderr: '', exitCode: 0),
        ]);
        $api = new TestExtensionApi($this->projectDir, $exec);
        $handler = new JbcontextReindexJobHandler(new TestLogger());
        $handler->handle($api, ['session_id' => 'run-1'], 'jbcontext.reindex.run-1', 'run-1');

        $state = $store->read();
        $this->assertTrue($state->reindexPending);
        $this->assertTrue($state->reindexRunning);
        $this->assertSame([], $exec->calls());
    }

    #[Test]
    public function drainsPendingWorkAndClearsRunningFlag(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        $store = JbcontextStatusStore::forSession($paths, 'run-1');
        $store->write(new JbcontextSessionState(
            sessionId: 'run-1',
            mode: JbcontextSessionModeEnum::Eligible,
            reason: null,
            statusText: 'jbcontext: indexed',
            attempt: 1,
            startedAt: 1.0,
            nextRetryAt: null,
            reindexPending: true,
            reindexRunning: false,
            eligibilityStarted: true,
            updatedAt: 1.0,
        ));

        $exec = new RecordingExec([
            new ExecResultDTO(stdout: '', stderr: '', exitCode: 0),
        ]);
        $api = new TestExtensionApi($this->projectDir, $exec);
        $handler = new JbcontextReindexJobHandler(new TestLogger());
        $handler->handle($api, ['session_id' => 'run-1'], 'jbcontext.reindex.run-1', 'run-1');

        $state = $store->read();
        $this->assertFalse($state->reindexRunning);
        $this->assertFalse($state->reindexPending);
        $this->assertSame('jbcontext: indexed', $state->statusText);
        $this->assertCount(1, $exec->calls());
        $this->assertSame('index', $exec->calls()[0]['args'][0]);
    }
}
