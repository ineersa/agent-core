<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests\Tui;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ActivityRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmDatabaseFactoryTestService;
use Ineersa\HatfieldExt\ObservationalMemory\Tui\OmBackgroundStatusPoller;
use Ineersa\Tui\Runtime\BridgeTuiExtensionContext;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Runtime\TuiTickDispatcher;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\Tui\Event\TickEvent;

/**
 * Thesis: keyed OM activity status via real poller → Bridge → ChatScreen is
 * rendered once in the status panel, never on the footer line, and clears
 * independently of working status.
 */
final class OmBackgroundStatusVirtualRenderTest extends IsolatedKernelTestCase
{
    use TuiRuntimeContextBuilderTrait;

    private const string SESSION_ID = 'om-status-virtual-session';
    private const string ACTIVITY = 'Observational memory: reflector running (~2,500 tokens)';

    #[Test]
    public function testActivityStatusRendersOnceInPanelNotFooterClearsAndStaysIdleSafe(): void
    {
        $projectDir = TestDirectoryIsolation::createProjectTempDir('om-status-virtual');
        try {
            TestDirectoryIsolation::createHatfieldTree($projectDir);
            $dbPath = $projectDir.'/.hatfield/extensions-data/observational-memory/om.sqlite';
            /** @var OmDatabaseFactoryTestService $factory */
            $factory = self::getContainer()->get('test.om_database_factory');
            $connection = $factory->connectAndMigrate($dbPath, new NullLogger());
            $activity = new ActivityRepository($connection);
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $activity->upsert(
                self::SESSION_ID,
                'job-virtual',
                'reflector',
                2500,
                null,
                $now->format(\DateTimeInterface::ATOM),
            );

            $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
            $screen = $harness->screen();
            $screen->setWorkingVisible(true);
            $screen->setWorkingMessage('Working...');

            $ticks = new TuiTickDispatcher();
            $runtime = $this->buildTuiContext()
                ->withTui($harness->tui())
                ->withScreen($screen)
                ->withState(new TuiSessionState(self::SESSION_ID))
                ->withTicks($ticks)
                ->build();
            $bridge = new BridgeTuiExtensionContext($runtime);
            $poller = new OmBackgroundStatusPoller($bridge, $dbPath, new NullLogger());
            $bridge->onTick(static function () use ($poller): void {
                $poller->tick();
            });

            // Force first poll immediately (poller starts with lastPollAt=0).
            $busyHint = $ticks->dispatch(new TickEvent(0.0));
            $this->assertNull($busyHint, 'extension onTick must not force active cadence');

            $text = $harness->plainScreenText();
            $this->assertSame(1, substr_count($text, self::ACTIVITY), 'activity text must appear exactly once');
            $this->assertStringContainsString('om-background', $text);
            $this->assertSame(
                self::ACTIVITY,
                $screen->registry()->getStatusEntries()[OmBackgroundStatusPoller::STATUS_KEY] ?? null,
            );
            $this->assertFooterDoesNotContain($text, self::ACTIVITY);
            $this->assertStringContainsString('Working...', $text);

            $activity->clear(self::SESSION_ID, 'job-virtual');
            // Throttle is 250ms; wait just past it so clear is observed.
            usleep(260_000);
            $busyHint = $ticks->dispatch(new TickEvent(0.26));
            $this->assertNull($busyHint);

            $cleared = $harness->plainScreenText();
            $this->assertStringNotContainsString(self::ACTIVITY, $cleared);
            $this->assertArrayNotHasKey(
                OmBackgroundStatusPoller::STATUS_KEY,
                $screen->registry()->getStatusEntries(),
            );
            $this->assertStringContainsString('Working...', $cleared, 'working status remains independent');
        } finally {
            TestDirectoryIsolation::removeDirectory($projectDir);
        }
    }

    private function assertFooterDoesNotContain(string $screenText, string $needle): void
    {
        $lines = explode("\n", $screenText);
        $footerNeedle = 'session '.self::SESSION_ID;
        foreach ($lines as $line) {
            if (str_contains($line, $footerNeedle)) {
                $this->assertStringNotContainsString(
                    $needle,
                    $line,
                    'keyed status must not appear on the footer line',
                );

                return;
            }
        }

        $this->fail('Footer session line missing from virtual screen');
    }
}
