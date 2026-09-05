<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
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
    public function publishesDisabledStatusFromStore(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        (new JbcontextStatusStore($paths->statusPath))->write(new JbcontextSessionState(
            mode: JbcontextSessionModeEnum::Disabled,
            reason: 'no index',
            statusText: 'jbcontext disabled: no existing index snapshot. Run `jbcontext index` manually once for this repository, then restart Hatfield.',
            attempt: 1,
            nextRetryAt: null,
            reindexPending: false,
            reindexRunning: false,
            updatedAt: microtime(true),
        ));

        $statuses = [];
        $tui = new class($statuses) implements TuiExtensionContextInterface {
            /**
             * @param array<string, ?string> $statuses
             */
            public function __construct(private array &$statuses)
            {
            }

            public function getSessionId(): string
            {
                return 'sess';
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

        $poller = new JbcontextStatusPoller($tui, new JbcontextStatusStore($paths->statusPath), new TestLogger());
        $poller->tick();

        $this->assertArrayHasKey(JbcontextStatusPoller::STATUS_KEY, $statuses);
        $this->assertStringContainsString('no existing index snapshot', (string) $statuses[JbcontextStatusPoller::STATUS_KEY]);
    }
}
