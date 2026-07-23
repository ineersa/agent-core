<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Sanitized provider-quota section for `/usage`.
 *
 * Infrastructure formats display lines; TUI only applies Markdown structure.
 * Lines never contain tokens, API keys, or raw response bodies.
 */
final readonly class ProviderQuotaSectionDTO
{
    /**
     * @param list<string> $lines
     */
    public function __construct(
        public string $title,
        public array $lines,
    ) {
    }
}
