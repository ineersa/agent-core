<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Logging;

use Ineersa\CodingAgent\Logging\FrameworkChannelLevelProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class FrameworkChannelLevelProcessorTest extends TestCase
{
    public function testDemotesFrameworkInfoToDebug(): void
    {
        $processor = new FrameworkChannelLevelProcessor();

        foreach (['messenger', 'http_client', 'lock'] as $channel) {
            $result = $processor(new LogRecord(
                datetime: new \DateTimeImmutable(),
                channel: $channel,
                level: Level::Info,
                message: 'routine',
            ));

            $this->assertSame(Level::Debug, $result->level, $channel);
            $this->assertSame('routine', $result->message);
        }
    }

    public function testLeavesWarningsErrorsAndAppInfoUnchanged(): void
    {
        $processor = new FrameworkChannelLevelProcessor();

        $warning = $processor(new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'messenger',
            level: Level::Warning,
            message: 'retry',
        ));
        $this->assertSame(Level::Warning, $warning->level);

        $error = $processor(new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'http_client',
            level: Level::Error,
            message: 'failed',
        ));
        $this->assertSame(Level::Error, $error->level);

        $appInfo = $processor(new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'llm.request.completed',
        ));
        $this->assertSame(Level::Info, $appInfo->level);
    }
}
