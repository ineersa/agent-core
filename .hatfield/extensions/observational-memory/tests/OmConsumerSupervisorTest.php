<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmConsumerSupervisor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Thesis: supervisor consumer command targets the package bin/console and
 * messenger:consume on OM transports — never Hatfield extension:run.
 */
final class OmConsumerSupervisorTest extends TestCase
{
    public function testConsumerCommandUsesPackageConsoleAndOmTransports(): void
    {
        $supervisor = new OmConsumerSupervisor(new NullLogger());

        $ref = new \ReflectionClass($supervisor);
        foreach ([
            'consolePath' => '/tmp/om-pkg/bin/console',
            'packageRoot' => '/tmp/om-pkg',
            'sessionId' => 'sess-1',
            'databasePath' => '/tmp/project/.hatfield/extensions-data/observational-memory/om.sqlite',
            'started' => true,
        ] as $prop => $value) {
            $property = $ref->getProperty($prop);
            $property->setValue($supervisor, $value);
        }

        $command = $supervisor->consumerCommand();

        $this->assertContains('/tmp/om-pkg/bin/console', $command);
        $this->assertContains('messenger:consume', $command);
        $this->assertContains('om_compaction', $command);
        $this->assertContains('om_observation', $command);
        $this->assertNotContains('extension:run', $command);
        $this->assertNotContains('agent', $command);
    }
}
