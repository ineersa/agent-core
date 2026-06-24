<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;

final class StubExec implements ExecInterface
{
    /** @var callable|null */
    private $handler;

    public function __construct(?callable $handler = null)
    {
        $this->handler = $handler;
    }

    public function exec(string $command, array $args = [], ?ExecOptionsDTO $options = null): ExecResultDTO
    {
        if (null !== $this->handler) {
            return ($this->handler)($command, $args, $options);
        }

        return new ExecResultDTO(stdout: '', stderr: '', exitCode: 0);
    }
}
