<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\ControllerReplayE2eTestCase;
use Ineersa\CodingAgent\Tests\Runtime\Controller\Support\OptionalJobProbeExtension;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Real process-local extension registration and dedicated worker execution.
 * A mock supervisor cannot establish those contracts. Timeouts are safety caps.
 */
#[Group('controller-replay')]
final class HeadlessControllerTest extends ControllerReplayE2eTestCase
{
    public function testNoRegisteredJobsOmitsOnlyExtensionConsumer(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());
        $this->assertLaunchCount('extension_agent', 0);
        $this->assertLaunchCount('mcp', 1);
    }

    public function testRegisteredExtensionJobRunsInDedicatedConsumer(): void
    {
        $path = $this->tempDir.'/.hatfield/settings.yaml';
        $settings = Yaml::parseFile($path);
        $settings['extensions']['enabled'][] = OptionalJobProbeExtension::class;
        file_put_contents($path, Yaml::dump($settings, 12));

        $this->spawnController();
        $this->waitForEvent('test.extension.completed', 8.0);
        $this->assertLaunchCount('extension_agent', 1);
        $this->assertLaunchCount('mcp', 1);
    }

    protected function extraSettingsYaml(): string
    {
        return Yaml::dump(['logging' => ['level' => 'info']], 6);
    }

    protected function tempDirPrefix(): string
    {
        return 'optional-consumers';
    }

    protected function replayFixtures(): array
    {
        return [];
    }

    private function assertLaunchCount(string $transport, int $expected): void
    {
        $log = (string) file_get_contents($this->tempDir.'/.hatfield/logs/agent-'.date('Y-m-d').'.log');
        $records = array_filter(explode("\n", $log), static fn (string $line): bool => str_contains($line, '"message":"Launched messenger consumer"') && str_contains($line, '"transport":"'.$transport.'"'));
        $this->assertCount($expected, $records, $log);
    }
}
