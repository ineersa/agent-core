<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ActivityRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Tui\OmBackgroundStatusPoller;
use Psr\Log\NullLogger;

final class OmBackgroundStatusPollerTest extends IsolatedKernelTestCase
{
    public function testMainSessionStillShowsActivity(): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('om-main-poller');
        $path = $directory.'/om.sqlite';
        $connection = self::getContainer()->get('test.om_database_factory')->connectAndMigrate($path);
        try {
            (new ActivityRepository($connection))->upsert('main-run', 'job', 'observer', 100);
            $api = $this->createMock(ExtensionApiInterface::class);
            $api->expects($this->once())->method('isChildRun')->with('main-run')->willReturn(false);
            $tui = $this->createMock(TuiExtensionContextInterface::class);
            $tui->method('getSessionId')->willReturn('main-run');
            $tui->expects($this->once())->method('setStatus')->with(
                OmBackgroundStatusPoller::STATUS_KEY,
                'Observational memory: observer running on ~100-token chunk',
            );
            (new OmBackgroundStatusPoller($tui, $path, new NullLogger(), $api))->tick();
        } finally {
            $connection->close();
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testChildSessionsNeverOpenMemoryStorage(): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('om-child-poller');
        try {
            foreach (['subagent-run', 'fork-run'] as $runId) {
                $api = $this->createMock(ExtensionApiInterface::class);
                $api->expects($this->once())->method('isChildRun')->with($runId)->willReturn(true);
                $tui = $this->createMock(TuiExtensionContextInterface::class);
                $tui->method('getSessionId')->willReturn($runId);
                $tui->expects($this->never())->method('setStatus');
                (new OmBackgroundStatusPoller($tui, $directory.'/om.sqlite', new NullLogger(), $api))->tick();
                $this->assertFileDoesNotExist($directory.'/om.sqlite');
            }
        } finally {
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }
}
