<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Cli;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCancellationTokenInterface;

/**
 * Thin jbcontext CLI wrapper over ExtensionApi exec.
 */
final readonly class JbcontextCli
{
    public const float STATUS_TIMEOUT_SECONDS = 5.0;
    public const float SEARCH_TIMEOUT_SECONDS = 30.0;
    public const float INDEX_TIMEOUT_SECONDS = 120.0;
    public const int SEARCH_LIMIT = 8;

    public function __construct(
        private ExecInterface $exec,
        private string $projectPath,
        private string $binary = 'jbcontext',
        private float $statusTimeoutSeconds = self::STATUS_TIMEOUT_SECONDS,
    ) {
    }

    /**
     * @return array{ok: bool, payload: ?array<string, mixed>, exit_code: int, timed_out: bool, cancelled: bool, error: ?string}
     */
    public function status(?ToolCancellationTokenInterface $cancellationToken = null): array
    {
        return $this->runJson(
            args: ['status', '--project-path', $this->projectPath, '--json-output'],
            timeout: $this->statusTimeoutSeconds,
            cancellationToken: $cancellationToken,
        );
    }

    /**
     * @return array{ok: bool, payload: ?array<string, mixed>, exit_code: int, timed_out: bool, cancelled: bool, error: ?string}
     */
    public function search(
        string $text,
        ?string $pathFilter = null,
        ?ToolCancellationTokenInterface $cancellationToken = null,
        ?float $timeoutSeconds = null,
    ): array {
        $args = [
            'search',
            '--project-path',
            $this->projectPath,
            '--json-output',
            '--limit',
            (string) self::SEARCH_LIMIT,
        ];
        if (null !== $pathFilter && '' !== $pathFilter) {
            $args[] = '--path-filter';
            $args[] = $pathFilter;
        }
        $args[] = $text;

        return $this->runJson(
            args: $args,
            timeout: $timeoutSeconds ?? self::SEARCH_TIMEOUT_SECONDS,
            cancellationToken: $cancellationToken,
        );
    }

    /**
     * @return array{ok: bool, exit_code: int, timed_out: bool, cancelled: bool, error: ?string}
     */
    public function indexSilent(?ToolCancellationTokenInterface $cancellationToken = null): array
    {
        $result = $this->exec->exec(
            $this->binary,
            ['index', '--project-path', $this->projectPath, '--silent'],
            new ExecOptionsDTO(
                cwd: $this->projectPath,
                timeout: self::INDEX_TIMEOUT_SECONDS,
                cancellationToken: $cancellationToken,
            ),
        );

        if ($result->cancelled) {
            return [
                'ok' => false,
                'exit_code' => $result->exitCode,
                'timed_out' => $result->timedOut,
                'cancelled' => true,
                'error' => 'cancelled',
            ];
        }
        if ($result->timedOut) {
            return [
                'ok' => false,
                'exit_code' => $result->exitCode,
                'timed_out' => true,
                'cancelled' => false,
                'error' => 'timed_out',
            ];
        }
        if (0 !== $result->exitCode) {
            return [
                'ok' => false,
                'exit_code' => $result->exitCode,
                'timed_out' => false,
                'cancelled' => false,
                'error' => 'exit_'.$result->exitCode,
            ];
        }

        return [
            'ok' => true,
            'exit_code' => 0,
            'timed_out' => false,
            'cancelled' => false,
            'error' => null,
        ];
    }

    /**
     * @param list<string> $args
     *
     * @return array{ok: bool, payload: ?array<string, mixed>, exit_code: int, timed_out: bool, cancelled: bool, error: ?string}
     */
    private function runJson(
        array $args,
        float $timeout,
        ?ToolCancellationTokenInterface $cancellationToken,
    ): array {
        $result = $this->exec->exec(
            $this->binary,
            $args,
            new ExecOptionsDTO(
                cwd: $this->projectPath,
                timeout: $timeout,
                cancellationToken: $cancellationToken,
            ),
        );

        if ($result->cancelled) {
            return [
                'ok' => false,
                'payload' => null,
                'exit_code' => $result->exitCode,
                'timed_out' => $result->timedOut,
                'cancelled' => true,
                'error' => 'cancelled',
            ];
        }
        if ($result->timedOut) {
            return [
                'ok' => false,
                'payload' => null,
                'exit_code' => $result->exitCode,
                'timed_out' => true,
                'cancelled' => false,
                'error' => 'timed_out',
            ];
        }

        $stdout = trim($result->stdout);
        if ('' === $stdout) {
            return [
                'ok' => false,
                'payload' => null,
                'exit_code' => $result->exitCode,
                'timed_out' => false,
                'cancelled' => false,
                'error' => 'empty_stdout',
            ];
        }

        try {
            $decoded = json_decode($stdout, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [
                'ok' => false,
                'payload' => null,
                'exit_code' => $result->exitCode,
                'timed_out' => false,
                'cancelled' => false,
                'error' => 'malformed_json',
            ];
        }

        if (!\is_array($decoded)) {
            return [
                'ok' => false,
                'payload' => null,
                'exit_code' => $result->exitCode,
                'timed_out' => false,
                'cancelled' => false,
                'error' => 'malformed_json',
            ];
        }

        /** @var array<string, mixed> $decoded */
        if (isset($decoded['type']) && 'error' === $decoded['type']) {
            return [
                'ok' => false,
                'payload' => $decoded,
                'exit_code' => $result->exitCode,
                'timed_out' => false,
                'cancelled' => false,
                'error' => 'cli_error',
            ];
        }

        if (0 !== $result->exitCode) {
            return [
                'ok' => false,
                'payload' => $decoded,
                'exit_code' => $result->exitCode,
                'timed_out' => false,
                'cancelled' => false,
                'error' => 'exit_'.$result->exitCode,
            ];
        }

        return [
            'ok' => true,
            'payload' => $decoded,
            'exit_code' => 0,
            'timed_out' => false,
            'cancelled' => false,
            'error' => null,
        ];
    }
}
