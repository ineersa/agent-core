<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;

/**
 * Records exec invocations for assertions while delegating to an inner implementation.
 */
final class RecordingExec implements ExecInterface
{
    /** @var list<array{command: string, args: list<string>, cwd: ?string}> */
    private array $calls = [];

    public function __construct(
        private readonly ExecInterface $inner,
    ) {
    }

    public function exec(string $command, array $args = [], ?ExecOptionsDTO $options = null): ExecResultDTO
    {
        $this->calls[] = [
            'command' => $command,
            'args' => array_values(array_map(static fn (mixed $a): string => (string) $a, $args)),
            'cwd' => $options?->cwd,
        ];

        return $this->inner->exec($command, $args, $options);
    }

    /**
     * @return list<array{command: string, args: list<string>, cwd: ?string}>
     */
    public function calls(): array
    {
        return $this->calls;
    }
}
