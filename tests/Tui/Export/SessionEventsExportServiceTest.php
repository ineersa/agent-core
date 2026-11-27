<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Export;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Export\SessionEventsExportService;
use Ineersa\Tui\Export\SessionExportOutcomeEnum;
use Ineersa\Tui\Tests\Support\SessionEventsExportServiceFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionEventsExportService::class)]
final class SessionEventsExportServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TestDirectoryIsolation::createOsTempDir('session-export');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->dir);
    }

    #[Test]
    public function missingAndBlankEventsAreNoEvents(): void
    {
        $service = SessionEventsExportServiceFactory::create();
        $blank = $this->dir.'/events.jsonl';
        file_put_contents($blank, "\n");

        foreach ([$this->dir.'/missing.jsonl', $blank] as $eventsPath) {
            $result = $service->exportEventsFile($eventsPath, $this->dir.'/out.jsonl', 'sess-1');

            $this->assertSame(SessionExportOutcomeEnum::NoEvents, $result->outcome);
            $this->assertSame('Session sess-1 has no events to export.', $result->message);
        }
        $this->assertFileDoesNotExist($this->dir.'/out.jsonl');
    }

    #[Test]
    public function jsonlExportCopiesEventsAndReportsPath(): void
    {
        $eventsPath = $this->dir.'/events.jsonl';
        file_put_contents($eventsPath, "{\"seq\":1}\n");
        $outputPath = $this->dir.'/nested/out.jsonl';

        $result = SessionEventsExportServiceFactory::create()->exportEventsFile($eventsPath, $outputPath, 'sess-1');

        $this->assertSame(SessionExportOutcomeEnum::Exported, $result->outcome);
        $this->assertSame($outputPath, $result->path);
        $this->assertSame('', $result->message);
        $this->assertFileEquals($eventsPath, $outputPath);
    }

    #[Test]
    public function jsonlExportOntoTheEventsFileFailsAndLeavesItUntouched(): void
    {
        $eventsPath = $this->dir.'/events.jsonl';
        file_put_contents($eventsPath, "{\"seq\":1}\n");
        $inode = fileinode($eventsPath);
        $linkPath = $this->dir.'/link.jsonl';
        symlink($eventsPath, $linkPath);

        foreach ([$eventsPath, $linkPath] as $outputPath) {
            $result = SessionEventsExportServiceFactory::create()->exportEventsFile($eventsPath, $outputPath, 'sess-1');

            $this->assertSame(SessionExportOutcomeEnum::Failed, $result->outcome);
            $this->assertStringContainsString('may be the session events file itself', $result->message);
            $this->assertSame("{\"seq\":1}\n", file_get_contents($eventsPath));
            clearstatcache();
            $this->assertSame($inode, fileinode($eventsPath));
        }
    }

    #[Test]
    public function writeFailureIsFailedAndLogged(): void
    {
        $eventsPath = $this->dir.'/events.jsonl';
        file_put_contents($eventsPath, "{\"seq\":1}\n");
        file_put_contents($this->dir.'/blocker', '');
        $outputPath = $this->dir.'/blocker/out.jsonl';
        $logger = new TestLogger();

        $result = SessionEventsExportServiceFactory::create(logger: $logger)->exportEventsFile($eventsPath, $outputPath, 'sess-1');

        $this->assertSame(SessionExportOutcomeEnum::Failed, $result->outcome);
        $this->assertStringStartsWith('Failed to write export to: '.$outputPath.': ', $result->message);
        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0]['level']);
        $this->assertSame('session_export.write_failed', $logger->records[0]['context']['event_type']);
        $this->assertSame('sess-1', $logger->records[0]['context']['session_id']);
    }
}
