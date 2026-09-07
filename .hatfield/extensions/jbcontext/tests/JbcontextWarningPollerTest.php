<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourceItemDTO;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourceSectionDTO;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourcesSummaryDTO;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionLocator;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionModeEnum;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionState;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextStatusStore;
use Ineersa\HatfieldExt\Jbcontext\Tests\Support\StatusFixtures;
use Ineersa\HatfieldExt\Jbcontext\Tui\JbcontextWarningPoller;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JbcontextWarningPollerTest extends TestCase
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
    public function rendersProblemsUnderStartupExtensionsAndNeverWritesStatus(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        $sessionId = 'sess';
        $store = JbcontextStatusStore::forSession($paths, $sessionId);
        $harness = new VirtualTuiHarness(columns: 200, sessionId: $sessionId);
        $harness->screen()->setLoadedResourcesSummary(new LoadedResourcesSummaryDTO([
            new LoadedResourceSectionDTO('Extensions', [new LoadedResourceItemDTO('jbcontext', '/extension')]),
        ]));
        $harness->screen()->setWorkingMessage('model is working');
        $harness->screen()->setWorkingVisible(true);

        $updates = [];
        $tui = $this->createMock(TuiExtensionContextInterface::class);
        $tui->method('getSessionId')->willReturnCallback(static function () use (&$sessionId): string {
            return $sessionId;
        });
        $tui->expects($this->never())->method('setStatus');
        $tui->method('setExtensionWarning')->willReturnCallback(static function (string $name, ?string $message) use ($harness, &$updates): void {
            $updates[] = [$name, $message];
            $harness->screen()->setExtensionWarning($name, $message);
        });
        $locator = new JbcontextSessionLocator();
        $locator->bindTui($tui);
        $now = 100.0;
        $poller = new JbcontextWarningPoller($tui, $paths, $locator, new TestLogger(), static function () use (&$now): float {
            return $now;
        });
        $pending = JbcontextSessionState::pending($sessionId)->with(eligibilityStarted: true, checkGeneration: 1);
        StatusFixtures::replace($store, $pending);
        $poller->tick();
        $this->assertSame([], $updates, 'Pending work stays silent.');

        $message = 'No index found. Run `jbcontext index` in this project, then restart Hatfield.';
        StatusFixtures::replace($store, $pending->with(mode: JbcontextSessionModeEnum::Disabled, reason: $message));
        $now += 1.0;
        $poller->tick();
        $screen = $harness->plainScreenText();
        $warning = '⚠ jbcontext: '.$message;
        $this->assertStringContainsString("[Extensions]  jbcontext\n  ".$warning, $screen);
        $this->assertSame(1, substr_count($screen, $warning));
        $this->assertStringContainsString('model is working', $screen);
        $this->assertLessThan(strpos($screen, 'model is working'), strpos($screen, $warning));

        $now += 60.0;
        $poller->tick();
        $this->assertCount(1, $updates, 'The warning stays in startup resources without repeated writes or a dwell timer.');
        $this->assertStringContainsString($warning, $harness->plainScreenText());

        // A restarted controller clears the old diagnostic while it rechecks.
        StatusFixtures::replace($store, $pending->with(checkGeneration: 2));
        $now += 1.0;
        $poller->tick();
        $this->assertStringNotContainsString($warning, $harness->plainScreenText());

        $eligible = $pending->with(mode: JbcontextSessionModeEnum::Eligible, checkGeneration: 2);
        foreach ([true, false] as $running) {
            StatusFixtures::replace($store, $eligible->with(reindexRunning: $running));
            $now += 1.0;
            $poller->tick();
        }
        $this->assertCount(2, $updates, 'Refresh and success stay silent.');

        $refreshFailure = 'Index refresh failed. Run `jbcontext index` to diagnose and retry.';
        StatusFixtures::replace($store, $eligible->with(reason: $refreshFailure));
        $now += 1.0;
        $poller->tick();
        $this->assertStringContainsString('⚠ jbcontext: '.$refreshFailure, $harness->plainScreenText());

        StatusFixtures::replace($store, $eligible->with(clearReason: true));
        $now += 1.0;
        $poller->tick();
        $this->assertStringNotContainsString('⚠ jbcontext:', $harness->plainScreenText());

        StatusFixtures::replace($store, $eligible->with(reason: $refreshFailure));
        $now += 1.0;
        $poller->tick();
        $sessionId = 'another-session';
        $now += 1.0;
        $poller->tick();
        $this->assertStringNotContainsString('⚠ jbcontext:', $harness->plainScreenText());
    }

    #[Test]
    public function doesNotStartEligibilityFromTuiTick(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        $tui = $this->createMock(TuiExtensionContextInterface::class);
        $tui->method('getSessionId')->willReturn('fresh');
        $tui->expects($this->never())->method('setStatus');
        $tui->expects($this->never())->method('setExtensionWarning');
        $locator = new JbcontextSessionLocator();
        $locator->bindTui($tui);
        $poller = new JbcontextWarningPoller($tui, $paths, $locator, new TestLogger(), static fn (): float => 1.0);
        $poller->tick();

        $this->assertFileDoesNotExist(JbcontextStatusStore::forSession($paths, 'fresh')->path());
    }
}
