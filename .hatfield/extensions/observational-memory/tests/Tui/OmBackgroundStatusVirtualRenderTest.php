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
