<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionLocator;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionModeEnum;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionState;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextStatusStore;
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
    public function publishesDisabledStatusFromStoreWithoutDispatching(): void
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
            reindexPending: false,
            reindexRunning: false,
            eligibilityStarted: true,
            updatedAt: 1.0,
        ));

        $statuses = [];
        $tui = $this->tui($statuses, $sessionId);
        $locator = new JbcontextSessionLocator();
        $locator->bindTui($tui);

        $poller = new JbcontextStatusPoller($tui, $paths, $locator, new TestLogger());
        $poller->tick();

        $this->assertArrayHasKey(JbcontextStatusPoller::STATUS_KEY, $statuses);
        $this->assertStringContainsString('no existing index snapshot', (string) $statuses[JbcontextStatusPoller::STATUS_KEY]);
    }

    #[Test]
    public function doesNotStartEligibilityFromTuiTick(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        $sessionId = 'fresh';
        $statuses = [];
        $tui = $this->tui($statuses, $sessionId);
        $locator = new JbcontextSessionLocator();
        $locator->bindTui($tui);

        $poller = new JbcontextStatusPoller($tui, $paths, $locator, new TestLogger());
        $poller->tick();

        $state = JbcontextStatusStore::forSession($paths, $sessionId)->read();
        $this->assertFalse($state->eligibilityStarted);
        $this->assertSame(JbcontextSessionModeEnum::Pending, $state->mode);
        $this->assertNull($statuses[JbcontextStatusPoller::STATUS_KEY] ?? null);
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
