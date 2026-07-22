<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmConsumerSupervisor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Thesis: supervise() is argument-free after start() stores launch state, and
 * stop() is idempotent without a running process.
 */
final class OmConsumerSupervisorTest extends TestCase
{
    public function testSuperviseBeforeStartIsNoOp(): void
    {
        $supervisor = new OmConsumerSupervisor(new NullLogger());
        $supervisor->supervise();
        $supervisor->stop();
        $this->addToAssertionCount(1);
    }

    public function testStartSuperviseStopLifecycle(): void
    {
        // Launch a short-lived PHP process; supervise/stop must remain safe.
        // Full restart coverage is intentionally light for the architecture preview.
        $supervisor = new OmConsumerSupervisor(new NullLogger());
        // /bin/true ignores trailing argv (extension:run ...), so launch succeeds
        // without requiring a real Hatfield entrypoint in this unit test.
        $supervisor->start(
            applicationCommand: ['/bin/true'],
            runtimeCwd: sys_get_temp_dir(),
            sessionId: 'sess-test',
            databasePath: sys_get_temp_dir().'/om-test.sqlite',
        );

        $supervisor->supervise();
        $supervisor->stop();
        $supervisor->stop();
        // After stop, supervise must remain a no-op.
        $supervisor->supervise();
        $this->addToAssertionCount(1);
    }
}
