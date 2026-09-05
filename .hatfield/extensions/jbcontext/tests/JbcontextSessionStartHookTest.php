<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterSessionStartHookContextDTO;
use Ineersa\HatfieldExt\Jbcontext\Job\JbcontextEligibilityJobHandler;
use Ineersa\HatfieldExt\Jbcontext\Job\JbcontextSessionStartHook;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionModeEnum;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionState;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextStatusStore;
use Ineersa\HatfieldExt\Jbcontext\Tests\Support\RecordingExec;
use Ineersa\HatfieldExt\Jbcontext\Tests\Support\TestExtensionApi;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JbcontextSessionStartHookTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('jbcontext-start-');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    #[Test]
    public function claimsAndDispatchesEligibilityOnceForFreshSession(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        $api = new TestExtensionApi($this->projectDir, new RecordingExec());
        $hook = new JbcontextSessionStartHook($api, $paths, new TestLogger());
        $sessionId = 'session-a';

        $hook->onAfterSessionStart(new AfterSessionStartHookContextDTO($sessionId));
        $hook->onAfterSessionStart(new AfterSessionStartHookContextDTO($sessionId));

        $this->assertCount(1, $api->jobs);
        $this->assertSame(JbcontextEligibilityJobHandler::HANDLER_ID, $api->jobs[0]->handlerId);
        $this->assertSame($sessionId, $api->jobs[0]->payload['session_id']);
        $this->assertSame('jbcontext.eligibility.'.$sessionId.'.attempt.1', $api->jobs[0]->jobId);

        $state = JbcontextStatusStore::forSession($paths, $sessionId)->read();
        $this->assertTrue($state->eligibilityStarted);
        $this->assertSame(JbcontextSessionModeEnum::Pending, $state->mode);
        $this->assertSame('jbcontext: checking index…', $state->statusText);
    }

    #[Test]
    public function doesNotRedispatchWhenAlreadyEligibleOrDisabled(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        $sessionId = 'session-b';
        JbcontextStatusStore::forSession($paths, $sessionId)->write(new JbcontextSessionState(
            sessionId: $sessionId,
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
        $hook = new JbcontextSessionStartHook($api, $paths, new TestLogger());
        $hook->onAfterSessionStart(new AfterSessionStartHookContextDTO($sessionId));

        $this->assertSame([], $api->jobs);
    }
}
