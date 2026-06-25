<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Support;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;

/**
 * Exec stub for extension load tests (register() obtains exec but does not run commands).
 */
final readonly class NullExecBridge implements ExecInterface
{
    public function exec(string $command, array $args = [], ?ExecOptionsDTO $options = null): ExecResultDTO
    {
        return new ExecResultDTO('', '', 0);
    }
}
