<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Completes a previously deferred tool execution using stored correlation.
 *
 * Payload fields only; run/turn/step/tool identity come from the durable record.
 */
final readonly class CompleteDeferredToolCall
{
    /**
     * @param array<int, array<string, mixed>> $content
     * @param array<string, mixed>|null        $details
     * @param array<string, mixed>|null        $error
     */
    public function __construct(
        public string $deferredId,
        public array $content,
        public ?array $details = null,
        public bool $isError = false,
        public ?array $error = null,
    ) {
    }
}
