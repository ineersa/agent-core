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
        $this->assertContains('--time-limit=3600', $command);
        $this->assertContains('--memory-limit=256M', $command);
        $this->assertSame(1, \count(array_filter(
            $command,
            static fn (string $arg): bool => str_starts_with($arg, '--time-limit='),
        )));
        $this->assertSame(1, \count(array_filter(
            $command,
            static fn (string $arg): bool => str_starts_with($arg, '--memory-limit='),
        )));
        $this->assertNotContains('extension:run', $command);
        $this->assertNotContains('agent', $command);
    }

    public function testChildEnvIsolatesCacheAndLogUnderDatabaseDirectory(): void
    {
        $supervisor = new OmConsumerSupervisor(new NullLogger());
        $databasePath = '/tmp/project/.hatfield/extensions-data/observational-memory/om.sqlite';

        $ref = new \ReflectionClass($supervisor);
        foreach ([
            'consolePath' => '/tmp/om-pkg/bin/console',
            'packageRoot' => '/tmp/om-pkg',
            'sessionId' => 'sess-1',
            'databasePath' => $databasePath,
        ] as $prop => $value) {
            $property = $ref->getProperty($prop);
            $property->setValue($supervisor, $value);
        }

        $method = $ref->getMethod('childEnv');
        /** @var array<string, string|false> $env */
        $env = $method->invoke($supervisor);

        $this->assertSame(
            '/tmp/project/.hatfield/extensions-data/observational-memory/cache',
            $env['OM_CACHE_DIR'],
        );
        $this->assertSame(
            '/tmp/project/.hatfield/extensions-data/observational-memory/log',
            $env['OM_LOG_DIR'],
        );
        $this->assertSame($databasePath, $env['OM_DATABASE_PATH']);
        $this->assertNotSame('/tmp/om-pkg/var/cache', $env['OM_CACHE_DIR']);
    }

    public function testChildEnvRemovesHatfieldProcessRoleMarkersWithFalse(): void
    {
        $previousStdout = $_ENV['HATFIELD_CONSUMER_STDOUT_EVENTS'] ?? null;
        $previousOm = $_ENV['HATFIELD_OM_CONSUMER'] ?? null;
        $_ENV['HATFIELD_CONSUMER_STDOUT_EVENTS'] = '1';
        $_ENV['HATFIELD_OM_CONSUMER'] = '1';

        try {
            $supervisor = new OmConsumerSupervisor(new NullLogger());
            $ref = new \ReflectionClass($supervisor);
            foreach ([
                'consolePath' => '/tmp/om-pkg/bin/console',
                'packageRoot' => '/tmp/om-pkg',
                'sessionId' => 'sess-1',
                'databasePath' => '/tmp/project/.hatfield/extensions-data/observational-memory/om.sqlite',
            ] as $prop => $value) {
                $property = $ref->getProperty($prop);
                $property->setValue($supervisor, $value);
            }

            $method = $ref->getMethod('childEnv');
            /** @var array<string, string|false> $env */
            $env = $method->invoke($supervisor);

            // false removes the key after Process merges OS defaults; unset would not.
            $this->assertArrayHasKey('HATFIELD_CONSUMER_STDOUT_EVENTS', $env);
            $this->assertArrayHasKey('HATFIELD_OM_CONSUMER', $env);
            $this->assertFalse($env['HATFIELD_CONSUMER_STDOUT_EVENTS']);
            $this->assertFalse($env['HATFIELD_OM_CONSUMER']);
        } finally {
            if (null === $previousStdout) {
                unset($_ENV['HATFIELD_CONSUMER_STDOUT_EVENTS']);
            } else {
                $_ENV['HATFIELD_CONSUMER_STDOUT_EVENTS'] = $previousStdout;
            }
            if (null === $previousOm) {
                unset($_ENV['HATFIELD_OM_CONSUMER']);
            } else {
                $_ENV['HATFIELD_OM_CONSUMER'] = $previousOm;
            }
        }
    }
}
