<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Logging;

use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Demotes routine Symfony framework INFO chatter without changing call sites.
 *
 * Messenger delivery/ack/send, HTTP client request/response, and Lock acquire
 * failures are high-volume diagnostics. Warnings and errors on those channels
 * stay at their original level.
 */
final class FrameworkChannelLevelProcessor implements ProcessorInterface
{
    private const CHANNELS = [
        'messenger' => true,
        'http_client' => true,
        'lock' => true,
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        if (Level::Info !== $record->level || !isset(self::CHANNELS[$record->channel])) {
            return $record;
        }

        return $record->with(level: Level::Debug);
    }
}
