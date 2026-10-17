<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Amp\Parallel\Context\ProcessContextFactory;
use Fabpot\Amp\Sqlite\SqliteConnector;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;

/**
 * Builds the Fabpot SqliteConnector that launches Amp SQLite workers through
 * the same Hatfield executable argv used for controller/consumer children.
 */
final class SessionSqliteQueueConnectorFactory
{
    public function __construct(
        private readonly RuntimeProcessConfig $runtimeConfig,
    ) {
    }

    public function __invoke(): SqliteConnector
    {
        $binary = [
            ...$this->runtimeConfig->executableCommand(),
            InternalSqliteWorkerBootstrap::FLAG,
        ];

        return new SqliteConnector(
            new ProcessContextFactory(
                workingDirectory: $this->runtimeConfig->runtimeCwd(),
                binary: $binary,
            ),
        );
    }
}
