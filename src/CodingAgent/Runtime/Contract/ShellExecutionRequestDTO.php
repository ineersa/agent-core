<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * A shell execution request crossing the TUI/runtime boundary.
 *
 * The first-shell path needs session and working-directory context. Inline
 * shell commands reuse the same shape with the active run as sessionId and an
 * empty cwd because the controller process already owns its runtime cwd.
 */
final readonly class ShellExecutionRequestDTO
{
    public function __construct(
        public ShellCommandDTO $command,
        public string $sessionId,
        public string $cwd,
        public bool $standalone,
    ) {
        if ('' === $sessionId) {
            throw new \InvalidArgumentException('Shell execution requires a sessionId.');
        }
    }

    public static function first(
        ShellCommandDTO $command,
        string $sessionId,
        string $cwd,
    ): self {
        return new self($command, $sessionId, $cwd, true);
    }

    public static function inline(
        ShellCommandDTO $command,
        string $runId,
        bool $standalone,
    ): self {
        return new self($command, $runId, '', $standalone);
    }

    public static function fromUserCommand(string $runId, UserCommand $command): self
    {
        if ('shell_command' !== $command->type || !\is_string($command->text)) {
            throw new \InvalidArgumentException('UserCommand is not a valid shell command.');
        }

        return self::inline(
            new ShellCommandDTO(
                $command->text,
                self::requiredString($command->payload, 'original_text'),
            ),
            $runId,
            self::requiredBool($command->payload, 'standalone'),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromRuntimePayload(string $sessionId, array $payload): self
    {
        $cwd = $payload['cwd'] ?? '';
        if (!\is_string($cwd)) {
            throw new \InvalidArgumentException('shell_command payload.cwd must be a string.');
        }

        return new self(
            new ShellCommandDTO(
                self::requiredString($payload, 'text'),
                self::requiredString($payload, 'original_text'),
            ),
            $sessionId,
            $cwd,
            self::requiredBool($payload, 'standalone'),
        );
    }

    /**
     * @return array{text: string, original_text: string, standalone: bool, cwd: string}
     */
    public function toRuntimePayload(): array
    {
        return $this->command->toPayload($this->standalone) + ['cwd' => $this->cwd];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function requiredString(array $payload, string $key): string
    {
        if (!\array_key_exists($key, $payload) || !\is_string($payload[$key])) {
            throw new \InvalidArgumentException(\sprintf('shell_command requires a string payload.%s.', $key));
        }

        return $payload[$key];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function requiredBool(array $payload, string $key): bool
    {
        if (!\array_key_exists($key, $payload) || !\is_bool($payload[$key])) {
            throw new \InvalidArgumentException(\sprintf('shell_command requires a boolean payload.%s.', $key));
        }

        return $payload[$key];
    }
}
