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
use Ineersa\HatfieldExt\Jbcontext\Tests\Support\StatusFixtures;
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
        StatusFixtures::replace($store, new JbcontextSessionState(
            sessionId: 'run-1',
            mode: JbcontextSessionModeEnum::Eligible,
            reason: null,
            attempt: 1,
            startedAt: 1.0,
            reindexPending: true,
            reindexRunning: true,
            eligibilityStarted: true,
            checkGeneration: 1,
            updatedAt: 1.0,
        ));

        $exec = new RecordingExec([
            new ExecResultDTO(stdout: '', stderr: '', exitCode: 0),
        ]);
        $api = new TestExtensionApi($this->projectDir, $exec);
        $handler = new JbcontextReindexJobHandler(new TestLogger());
        $handler->handle(
            $api,
            ['session_id' => 'run-1', 'check_generation' => 1],
            'jbcontext.reindex.run-1',
            'run-1',
        );

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
        StatusFixtures::replace($store, new JbcontextSessionState(
            sessionId: 'run-1',
            mode: JbcontextSessionModeEnum::Eligible,
            reason: null,
            attempt: 1,
            startedAt: 1.0,
            reindexPending: true,
            reindexRunning: false,
            eligibilityStarted: true,
            checkGeneration: 1,
            updatedAt: 1.0,
        ));

        $exec = new RecordingExec([
            new ExecResultDTO(stdout: '', stderr: '', exitCode: 0),
        ]);
        $api = new TestExtensionApi($this->projectDir, $exec);
        $handler = new JbcontextReindexJobHandler(new TestLogger());
        $handler->handle(
            $api,
            ['session_id' => 'run-1', 'check_generation' => 1],
            'jbcontext.reindex.run-1',
            'run-1',
        );

        $state = $store->read();
        $this->assertFalse($state->reindexRunning);
        $this->assertFalse($state->reindexPending);
        $this->assertNull($state->reason);
        $this->assertCount(1, $exec->calls());
        $this->assertSame('index', $exec->calls()[0]['args'][0]);
    }

    #[Test]
    public function refreshFailureKeepsSearchEligibleAndWarningClearsAfterSuccess(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        $store = JbcontextStatusStore::forSession($paths, 'run-1');
        StatusFixtures::replace($store, JbcontextSessionState::pending('run-1')->with(
            mode: JbcontextSessionModeEnum::Eligible,
            reindexPending: true,
            checkGeneration: 1,
        ));
        $exec = new RecordingExec([
            new ExecResultDTO(stdout: '', stderr: 'failed', exitCode: 1),
            new ExecResultDTO(stdout: '', stderr: '', exitCode: 0),
        ]);
        $api = new TestExtensionApi($this->projectDir, $exec);
        $handler = new JbcontextReindexJobHandler(new TestLogger());
        $payload = ['session_id' => 'run-1', 'check_generation' => 1];
        $handler->handle($api, $payload, 'first', 'run-1');
        $failed = $store->read();
        $this->assertSame(JbcontextSessionModeEnum::Eligible, $failed->mode);
        $this->assertFalse($failed->reindexRunning);
        $this->assertStringContainsString('Index refresh failed', (string) $failed->reason);
        $this->assertStringContainsString('Run `jbcontext index`', (string) $failed->reason);

        StatusFixtures::replace($store, $failed->with(reindexPending: true));
        $handler->handle($api, $payload, 'second', 'run-1');
        $this->assertNull($store->read()->reason);
        $this->assertSame(JbcontextSessionModeEnum::Eligible, $store->read()->mode);
    }

    #[Test]
    public function staleGenerationCompletionDoesNotClearNewerRunningFlags(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        $store = JbcontextStatusStore::forSession($paths, 'run-1');
        // Newer controller reclaim is mid-refresh on generation 2 while an older
        // generation-1 job finishes late.
        StatusFixtures::replace($store, new JbcontextSessionState(
            sessionId: 'run-1',
            mode: JbcontextSessionModeEnum::Eligible,
            reason: null,
            attempt: 1,
            startedAt: 2.0,
            reindexPending: false,
            reindexRunning: true,
            eligibilityStarted: true,
            checkGeneration: 2,
            updatedAt: 2.0,
        ));

        $exec = new RecordingExec([
            new ExecResultDTO(stdout: '', stderr: '', exitCode: 0),
        ]);
        $api = new TestExtensionApi($this->projectDir, $exec);
        $handler = new JbcontextReindexJobHandler(new TestLogger());
        $handler->handle(
            $api,
            ['session_id' => 'run-1', 'check_generation' => 1],
            'jbcontext.reindex.run-1.g1-late',
            'run-1',
        );

        $state = $store->read();
        $this->assertSame(2, $state->checkGeneration);
        $this->assertTrue($state->reindexRunning);
        $this->assertFalse($state->reindexPending);
        $this->assertNull($state->reason);
        $this->assertSame([], $exec->calls());
    }

    #[Test]
    public function missingGenerationFailsClosedWithoutIndexing(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        $store = JbcontextStatusStore::forSession($paths, 'run-1');
        StatusFixtures::replace($store, new JbcontextSessionState(
            sessionId: 'run-1',
            mode: JbcontextSessionModeEnum::Eligible,
            reason: null,
            attempt: 1,
            startedAt: 1.0,
            reindexPending: true,
            reindexRunning: false,
            eligibilityStarted: true,
            checkGeneration: 1,
            updatedAt: 1.0,
        ));

        $exec = new RecordingExec([
            new ExecResultDTO(stdout: '', stderr: '', exitCode: 0),
        ]);
        $api = new TestExtensionApi($this->projectDir, $exec);
        $logger = new TestLogger();
        $handler = new JbcontextReindexJobHandler($logger);
        $handler->handle($api, ['session_id' => 'run-1'], 'jbcontext.reindex.run-1', 'run-1');

        $state = $store->read();
        $this->assertTrue($state->reindexPending);
        $this->assertFalse($state->reindexRunning);
        $this->assertSame([], $exec->calls());
        $found = false;
        foreach ($logger->records as $record) {
            if ('jbcontext.reindex.missing_generation' === $record['message']) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }
}
