<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\HatfieldExt\Jbcontext\Job\JbcontextEligibilityJobHandler;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionLocator;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionModeEnum;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionState;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextStatusStore;
use Ineersa\HatfieldExt\Jbcontext\Tests\Support\RecordingExec;
use Ineersa\HatfieldExt\Jbcontext\Tests\Support\TestExtensionApi;
use Ineersa\HatfieldExt\Jbcontext\Tui\JbcontextStatusPoller;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Widget\AbstractWidget;

final class JbcontextStatusPollerTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('jbcontext-poll-');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    #[Test]
    public function publishesDisabledStatusFromStoreAndDoesNotRedispatch(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        $sessionId = 'sess';
        JbcontextStatusStore::forSession($paths, $sessionId)->write(new JbcontextSessionState(
            sessionId: $sessionId,
            mode: JbcontextSessionModeEnum::Disabled,
            reason: 'no index',
            statusText: 'jbcontext disabled: no existing index snapshot. Run `jbcontext index` manually once for this repository, then restart Hatfield.',
            attempt: 1,
            startedAt: 1.0,
            nextRetryAt: null,
            reindexPending: false,
            reindexRunning: false,
            eligibilityStarted: true,
            updatedAt: 1.0,
        ));

        $statuses = [];
        $tui = $this->tui($statuses, $sessionId);
        $api = new TestExtensionApi($this->projectDir, new RecordingExec());
        $locator = new JbcontextSessionLocator();
        $locator->bindTui($tui);

        $poller = new JbcontextStatusPoller($api, $tui, $paths, $locator, new TestLogger());
        $poller->tick();

        $this->assertArrayHasKey(JbcontextStatusPoller::STATUS_KEY, $statuses);
        $this->assertStringContainsString('no existing index snapshot', (string) $statuses[JbcontextStatusPoller::STATUS_KEY]);
        $this->assertSame([], $api->jobs);
    }

    #[Test]
    public function claimsAndDispatchesEligibilityOnceForFreshSession(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        $sessionId = 'fresh';
        $statuses = [];
        $tui = $this->tui($statuses, $sessionId);
        $api = new TestExtensionApi($this->projectDir, new RecordingExec());
        $locator = new JbcontextSessionLocator();
        $locator->bindTui($tui);

        $poller = new JbcontextStatusPoller($api, $tui, $paths, $locator, new TestLogger());
        $poller->tick();
        $poller->tick();

        $this->assertCount(1, $api->jobs);
        $this->assertSame(JbcontextEligibilityJobHandler::HANDLER_ID, $api->jobs[0]->handlerId);
        $state = JbcontextStatusStore::forSession($paths, $sessionId)->read();
        $this->assertTrue($state->eligibilityStarted);
        $this->assertSame(JbcontextSessionModeEnum::Pending, $state->mode);
    }

    /**
     * @param array<string, ?string> $statuses
     */
    private function tui(array &$statuses, string $sessionId): TuiExtensionContextInterface
    {
        return new class($statuses, $sessionId) implements TuiExtensionContextInterface {
            /**
             * @param array<string, ?string> $statuses
             */
            public function __construct(
                private array &$statuses,
                private string $sessionId,
            ) {
            }

            public function getSessionId(): string
            {
                return $this->sessionId;
            }

            public function requestRender(bool $force = false): void
            {
            }

            public function setStatus(string $key, ?string $text): void
            {
                $this->statuses[$key] = $text;
            }

            public function onTick(\Closure $listener): void
            {
            }

            public function insertOverlayAfterEditor(AbstractWidget $widget): void
            {
            }

            public function removeOverlay(AbstractWidget $widget): void
            {
            }

            public function setFocus(AbstractWidget $widget): void
            {
            }

            public function formatMuted(string $text): string
            {
                return $text;
            }

            public function formatRolePrefix(string $displayRole): string
            {
                return $displayRole;
            }

            public function turnRowsInDisplayOrder(string $sessionId): array
            {
                return [];
            }
        };
    }
}
