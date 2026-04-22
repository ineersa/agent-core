<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Command;

final readonly class RoutedCommand
{
    /**
     * Initializes the command with status, kind, payload, options, and optional reason.
     *
     * @param array<string, mixed>      $payload
     * @param array{cancel_safe?: bool} $options
     */
    private function __construct(
        public string $status,
        public string $kind,
        public array $payload = [],
        public array $options = [],
        public ?string $reason = null,
    ) {
    }

    /**
     * Creates a core command instance with the specified kind, payload, and options.
     *
     * @param array<string, mixed>      $payload
     * @param array{cancel_safe?: bool} $options
     */
    public static function core(string $kind, array $payload, array $options): self
    {
        return new self(status: 'core', kind: $kind, payload: $payload, options: $options);
    }

    /**
     * Creates an extension command instance with the specified kind, payload, and options.
     *
     * @param array<string, mixed>      $payload
     * @param array{cancel_safe?: bool} $options
     */
    public static function extension(string $kind, array $payload, array $options): self
    {
        return new self(status: 'extension', kind: $kind, payload: $payload, options: $options);
    }

    public static function rejected(string $kind, string $reason): self
    {
        return new self(status: 'rejected', kind: $kind, reason: $reason);
    }

    public function isRejected(): bool
    {
        return 'rejected' === $this->status;
    }
}
