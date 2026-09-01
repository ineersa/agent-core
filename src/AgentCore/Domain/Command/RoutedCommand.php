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
        public array $payload = [],
        public array $options = [],
        public ?string $reason = null,
    ) {
    }

    /**
     * Creates a core command instance with the specified payload and options.
     *
     * @param array<string, mixed>      $payload
     * @param array{cancel_safe?: bool} $options
     */
    public static function core(array $payload, array $options): self
    {
        return new self(status: 'core', payload: $payload, options: $options);
    }

    /**
     * Creates an extension command instance with the specified payload and options.
     *
     * @param array<string, mixed>      $payload
     * @param array{cancel_safe?: bool} $options
     */
    public static function extension(array $payload, array $options): self
    {
        return new self(status: 'extension', payload: $payload, options: $options);
    }

    public static function rejected(string $reason): self
    {
        return new self(status: 'rejected', reason: $reason);
    }

    public function isRejected(): bool
    {
        return 'rejected' === $this->status;
    }
}
