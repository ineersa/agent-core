<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Export;

use Ineersa\Tui\Export\SessionEventsExportService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionEventsExportService::class)]
final class SessionEventsExportServiceTest extends TestCase
{
    private string $tmpdir;

    protected function setUp(): void
    {
        $this->tmpdir = sys_get_temp_dir().'/session-export-svc-'.bin2hex(random_bytes(6));
        mkdir($this->tmpdir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpdir);
    }

    #[Test]
    public function exportEventsFileWritesHtmlFromArbitraryPath(): void
    {
        $eventsPath = $this->tmpdir.'/child/events.jsonl';
        mkdir(\dirname($eventsPath), 0777, true);
        $event = [
            'schema_version' => '1.0',
            'run_id' => 'child-1',
            'seq' => 1,
            'turn_no' => 1,
            'type' => 'run_started',
            'payload' => ['user_messages' => [['role' => 'user', 'content' => 'hi']]],
            'ts' => '2026-01-01T00:00:00+00:00',
        ];
        file_put_contents($eventsPath, json_encode($event, \JSON_THROW_ON_ERROR)."\n");

        $out = $this->tmpdir.'/out.html';
        $svc = new SessionEventsExportService();
        $msg = $svc->exportEventsFile($eventsPath, $out, 'child-1', 'Child test');

        $this->assertStringContainsString($out, $msg);
        $this->assertFileExists($out);
        $this->assertStringContainsString('<!DOCTYPE html>', (string) file_get_contents($out));
    }

    #[Test]
    public function exportEventsFileCopiesJsonlWhenOutputEndsWithJsonl(): void
    {
        $eventsPath = $this->tmpdir.'/events.jsonl';
        file_put_contents($eventsPath, "{\"seq\":1}\n");
        $out = $this->tmpdir.'/copy.jsonl';
        $svc = new SessionEventsExportService();
        $msg = $svc->exportEventsFile($eventsPath, $out, 'sid');

        $this->assertFileExists($out);
        $this->assertSame(file_get_contents($eventsPath), file_get_contents($out));
        $this->assertStringContainsString('exported', $msg);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
