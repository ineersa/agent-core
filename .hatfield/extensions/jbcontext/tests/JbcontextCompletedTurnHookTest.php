<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitEventSummaryDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookContextDTO;
use Ineersa\HatfieldExt\Jbcontext\Job\JbcontextCompletedTurnHook;
use Ineersa\HatfieldExt\Jbcontext\Job\JbcontextReindexJobHandler;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionLocator;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionModeEnum;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionState;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextStatusStore;
use Ineersa\HatfieldExt\Jbcontext\Tests\Support\RecordingExec;
use Ineersa\HatfieldExt\Jbcontext\Tests\Support\TestExtensionApi;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JbcontextCompletedTurnHookTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('jbcontext-turn-');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    #[Test]
    public function dispatchesReindexOnlyOnCompletedEligibleTurns(): void
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
            reindexPending: false,
            reindexRunning: false,
            eligibilityStarted: true,
            updatedAt: 1.0,
        ));

        $api = new TestExtensionApi($this->projectDir, new RecordingExec());
        $hook = new JbcontextCompletedTurnHook($api, $paths, new JbcontextSessionLocator(), new TestLogger());

        $hook->onAfterTurnCommit(new AfterTurnCommitHookContextDTO(
            runId: 'run-1',
            turnNo: 3,
            status: 'completed',
            events: [
                new AfterTurnCommitEventSummaryDTO(seq: 9, type: 'agent_end', payload: ['reason' => 'completed']),
            ],
            effectsCount: 0,
        ));

        $this->assertCount(1, $api->jobs);
        $this->assertSame(JbcontextReindexJobHandler::HANDLER_ID, $api->jobs[0]->handlerId);
        $this->assertTrue($store->read()->reindexPending);

        $api->jobs = [];
        $hook->onAfterTurnCommit(new AfterTurnCommitHookContextDTO(
            runId: 'run-1',
            turnNo: 4,
            status: 'cancelled',
            events: [
                new AfterTurnCommitEventSummaryDTO(seq: 10, type: 'agent_end', payload: ['reason' => 'cancelled']),
            ],
            effectsCount: 0,
        ));
        $this->assertSame([], $api->jobs);
    }

    #[Test]
    public function skipsWhenDisabled(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        JbcontextStatusStore::forSession($paths, 'run-1')->write(new JbcontextSessionState(
            sessionId: 'run-1',
            mode: JbcontextSessionModeEnum::Disabled,
            reason: 'no index',
            statusText: 'disabled',
            attempt: 1,
            startedAt: 1.0,
            reindexPending: false,
            reindexRunning: false,
            eligibilityStarted: true,
            updatedAt: 1.0,
        ));

        $api = new TestExtensionApi($this->projectDir, new RecordingExec());
        $hook = new JbcontextCompletedTurnHook($api, $paths, new JbcontextSessionLocator(), new TestLogger());
        $hook->onAfterTurnCommit(new AfterTurnCommitHookContextDTO(
            runId: 'run-1',
            turnNo: 1,
            status: 'completed',
            events: [
                new AfterTurnCommitEventSummaryDTO(seq: 1, type: 'agent_end', payload: ['reason' => 'completed']),
            ],
            effectsCount: 0,
        ));

        $this->assertSame([], $api->jobs);
    }
}
