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
use Ineersa\HatfieldExt\Jbcontext\Tests\Support\StatusFixtures;
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
    public function keepsDisabledStatusForFiniteDwellThenClearsPanel(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        $sessionId = 'sess';
        $disabledText = 'jbcontext disabled: no existing index snapshot. Run `jbcontext index` manually once for this repository, then restart Hatfield.';
        StatusFixtures::replace(JbcontextStatusStore::forSession($paths, $sessionId), new JbcontextSessionState(
            sessionId: $sessionId,
            mode: JbcontextSessionModeEnum::Disabled,
            reason: 'no index',
            statusText: $disabledText,
            attempt: 1,
            startedAt: 1.0,
            reindexPending: false,
            reindexRunning: false,
            eligibilityStarted: true,
            checkGeneration: 1,
            updatedAt: 1.0,
        ));

        $statuses = [];
        $now = 100.0;
        $tui = $this->tui($statuses, $sessionId);
        $locator = new JbcontextSessionLocator();
        $locator->bindTui($tui);

        $poller = new JbcontextStatusPoller(
            $tui,
            $paths,
            $locator,
            new TestLogger(),
            static function () use (&$now): float {
                return $now;
            },
        );

        $poller->tick();
        $this->assertSame($disabledText, $statuses[JbcontextStatusPoller::STATUS_KEY] ?? null);

        $now = 100.0 + JbcontextStatusPoller::MIN_POLL_SECONDS;
        $poller->tick();
        $this->assertSame($disabledText, $statuses[JbcontextStatusPoller::STATUS_KEY] ?? null);

        $now = 100.0 + JbcontextStatusPoller::DISABLED_FOOTER_DWELL_SECONDS - 0.01;
        $poller->tick();
        $this->assertSame($disabledText, $statuses[JbcontextStatusPoller::STATUS_KEY] ?? null);

        $now = 100.0 + JbcontextStatusPoller::DISABLED_FOOTER_DWELL_SECONDS + JbcontextStatusPoller::MIN_POLL_SECONDS;
        $poller->tick();
        $this->assertNull($statuses[JbcontextStatusPoller::STATUS_KEY] ?? null);
    }

    #[Test]
    public function generationChangeResetsDisabledDwell(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        $sessionId = 'sess';
        $first = 'jbcontext disabled: first failure';
        $second = 'jbcontext disabled: second failure';
        $store = JbcontextStatusStore::forSession($paths, $sessionId);
        StatusFixtures::replace($store, new JbcontextSessionState(
            sessionId: $sessionId,
            mode: JbcontextSessionModeEnum::Disabled,
            reason: $first,
            statusText: $first,
            attempt: 1,
            startedAt: 1.0,
            reindexPending: false,
            reindexRunning: false,
            eligibilityStarted: true,
            checkGeneration: 1,
            updatedAt: 1.0,
        ));

        $statuses = [];
        $now = 100.0;
        $tui = $this->tui($statuses, $sessionId);
        $locator = new JbcontextSessionLocator();
        $locator->bindTui($tui);
        $poller = new JbcontextStatusPoller(
            $tui,
            $paths,
            $locator,
            new TestLogger(),
            static function () use (&$now): float {
                return $now;
            },
        );

        $poller->tick();
        $this->assertSame($first, $statuses[JbcontextStatusPoller::STATUS_KEY] ?? null);

        $now += 4.0;
        StatusFixtures::replace($store, new JbcontextSessionState(
            sessionId: $sessionId,
            mode: JbcontextSessionModeEnum::Disabled,
            reason: $second,
            statusText: $second,
            attempt: 1,
            startedAt: 1.0,
            reindexPending: false,
            reindexRunning: false,
            eligibilityStarted: true,
            checkGeneration: 2,
            updatedAt: 2.0,
        ));
        $poller->tick();
        $this->assertSame($second, $statuses[JbcontextStatusPoller::STATUS_KEY] ?? null);

        $now += 4.0;
        $poller->tick();
        $this->assertSame($second, $statuses[JbcontextStatusPoller::STATUS_KEY] ?? null);

        $now += JbcontextStatusPoller::MIN_POLL_SECONDS + 1.0;
        $poller->tick();
        $this->assertNull($statuses[JbcontextStatusPoller::STATUS_KEY] ?? null);
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

        $poller = new JbcontextStatusPoller($tui, $paths, $locator, new TestLogger(), static fn (): float => 1.0);
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
