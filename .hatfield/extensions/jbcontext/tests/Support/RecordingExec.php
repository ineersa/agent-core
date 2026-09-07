<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests\Support;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;

final class RecordingExec implements ExecInterface
{
    /** @var list<array{command: string, args: list<string>, cwd: ?string, timeout: ?float}> */
    private array $calls = [];

    /** @var list<ExecResultDTO> */
    private array $responses;

    /**
     * @param list<ExecResultDTO> $responses
     */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    public function push(ExecResultDTO $result): void
    {
        $this->responses[] = $result;
    }

    public function exec(string $command, array $args = [], ?ExecOptionsDTO $options = null): ExecResultDTO
    {
        $this->calls[] = [
            'command' => $command,
            'args' => array_values(array_map(static fn (mixed $a): string => (string) $a, $args)),
            'cwd' => $options?->cwd,
            'timeout' => $options?->timeout,
        ];

        if ([] === $this->responses) {
            return new ExecResultDTO(stdout: '', stderr: 'no stub response', exitCode: 127);
        }

        return array_shift($this->responses);
    }

    /**
     * @return list<array{command: string, args: list<string>, cwd: ?string, timeout: ?float}>
     */
    public function calls(): array
    {
        return $this->calls;
    }
}
