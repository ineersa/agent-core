<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract;

/**
 * Child-kind presentation returned from terminal finalization (tool result text when applicable).
 */
final readonly class ChildRunTerminalFinalizationResultDTO
{
    public function __construct(
        public string $presentationMessage = '',
        public ?string $persistedArtifactSummary = null,
    ) {
    }

    public static function persistOnly(?string $persistedArtifactSummary = null): self
    {
        return new self('', $persistedArtifactSummary);
    }

    public static function withPresentation(string $message): self
    {
        return new self($message);
    }
}
