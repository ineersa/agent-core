<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Parsed user shell input. The original text is retained because the command
 * text alone cannot reproduce the exact visible bang line after trimming.
 */
final readonly class ShellCommandDTO
{
    public function __construct(
        public string $commandText,
        public string $originalText,
    ) {
        if ('' === trim($commandText)) {
            throw new \InvalidArgumentException('Shell command text must not be empty.');
        }

        if (!str_starts_with($originalText, '!')
            || str_starts_with($originalText, '!!')
            || trim(substr($originalText, 1)) !== trim($commandText)) {
            throw new \InvalidArgumentException('Shell original text must contain exactly one leading ! and the parsed command.');
        }
    }

    /**
     * @return array{text: string, original_text: string, standalone: bool}
     */
    public function toPayload(bool $standalone): array
    {
        return [
            'text' => $this->commandText,
            'original_text' => $this->originalText,
            'standalone' => $standalone,
        ];
    }
}
