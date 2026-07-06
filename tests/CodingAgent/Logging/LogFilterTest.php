<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Logging;

use Ineersa\CodingAgent\Logging\LogEntry;
use Ineersa\CodingAgent\Logging\LogFilter;
use PHPUnit\Framework\TestCase;

final class LogFilterTest extends TestCase
{
    private LogEntry $infoEntry;
    private LogEntry $warningEntry;
    private LogEntry $errorEntry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->infoEntry = new LogEntry(
            datetime: new \DateTimeImmutable('2026-05-18T10:00:00+00:00'),
            channel: 'app',
            level: 'INFO',
            message: 'Application started',
            context: ['version' => '1.0'],
        );
        $this->warningEntry = new LogEntry(
            datetime: new \DateTimeImmutable('2026-05-18T11:00:00+00:00'),
            channel: 'app',
            level: 'WARNING',
            message: 'Disk space running low',
            context: ['disk' => '/dev/sda1', 'percent' => 90],
        );
        $this->errorEntry = new LogEntry(
            datetime: new \DateTimeImmutable('2026-05-18T12:00:00+00:00'),
            channel: 'app',
            level: 'ERROR',
            message: 'Connection timeout to database',
            context: ['exception' => 'PDOException', 'timeout_ms' => 5000],
        );
    }

    public function testEmptyFilterMatchesAll(): void
    {
        $filter = new LogFilter();

        $this->assertTrue($filter->matches($this->infoEntry));
        $this->assertTrue($filter->matches($this->warningEntry));
        $this->assertTrue($filter->matches($this->errorEntry));
    }

    public function testLevelFilterMatchesExactLevel(): void
    {
        $filter = new LogFilter(level: 'ERROR');

        $this->assertFalse($filter->matches($this->infoEntry));
        $this->assertFalse($filter->matches($this->warningEntry));
        $this->assertTrue($filter->matches($this->errorEntry));
    }

    public function testLevelFilterIsCaseInsensitive(): void
    {
        $filter = new LogFilter(level: 'error');

        $this->assertTrue($filter->matches($this->errorEntry));

        $lowerFilter = new LogFilter(level: 'info');
        $this->assertTrue($lowerFilter->matches($this->infoEntry));
    }

    public function testSearchFilterMatchesMessage(): void
    {
        $filter = new LogFilter(search: 'timeout');

        $this->assertFalse($filter->matches($this->infoEntry));
        $this->assertFalse($filter->matches($this->warningEntry));
        $this->assertTrue($filter->matches($this->errorEntry));
    }

    public function testSearchFilterMatchesContext(): void
    {
        $filter = new LogFilter(search: 'PDOException');

        $this->assertFalse($filter->matches($this->infoEntry));
        $this->assertTrue($filter->matches($this->errorEntry));
    }

    public function testSearchFilterMatchesNestedContext(): void
    {
        $entry = new LogEntry(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: 'INFO',
            message: 'Request handled',
            context: ['request' => ['method' => 'POST', 'path' => '/api/users']],
        );

        $filter = new LogFilter(search: '/api/users');
        $this->assertTrue($filter->matches($entry));
    }

    public function testSearchFilterIsCaseInsensitive(): void
    {
        $filter = new LogFilter(search: 'TIMEOUT');

        $this->assertTrue($filter->matches($this->errorEntry));
    }

    public function testFromFilterExcludesEarlierEntries(): void
    {
        $filter = new LogFilter(from: new \DateTimeImmutable('2026-05-18T11:00:00+00:00'));

        $this->assertFalse($filter->matches($this->infoEntry));
        $this->assertTrue($filter->matches($this->warningEntry));
        $this->assertTrue($filter->matches($this->errorEntry));
    }

    public function testToFilterExcludesLaterEntries(): void
    {
        $filter = new LogFilter(to: new \DateTimeImmutable('2026-05-18T11:00:00+00:00'));

        $this->assertTrue($filter->matches($this->infoEntry));
        $this->assertTrue($filter->matches($this->warningEntry)); // datetime <= 11:00
        $this->assertFalse($filter->matches($this->errorEntry));
    }

    public function testFromAndToCombination(): void
    {
        $filter = new LogFilter(
            from: new \DateTimeImmutable('2026-05-18T10:30:00+00:00'),
            to: new \DateTimeImmutable('2026-05-18T11:30:00+00:00'),
        );

        $this->assertFalse($filter->matches($this->infoEntry));
        $this->assertTrue($filter->matches($this->warningEntry));
        $this->assertFalse($filter->matches($this->errorEntry));
    }

    public function testCombinedFilters(): void
    {
        $filter = new LogFilter(level: 'ERROR', search: 'timeout');

        $this->assertTrue($filter->matches($this->errorEntry));

        $wrongLevel = new LogFilter(level: 'INFO', search: 'timeout');
        $this->assertFalse($wrongLevel->matches($this->errorEntry));
    }
}
